<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_headings_toc extends DokuWiki_Action_Plugin {

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        always: {
            $controller->register_hook(
                'DOKUWIKI_STARTED', 'BEFORE', $this, '_exportToJSINFO', []
            );
            $controller->register_hook(
                'TPL_METAHEADER_OUTPUT', 'BEFORE', $this, '_hookjs', []
            );
        }
        if ($this->getConf('tocDisplay') != 'disabled') {
            $controller->register_hook(
                'PARSER_CACHE_USE', 'BEFORE', $this, 'handleParserCache', []
            );
            $controller->register_hook(
                'PARSER_METADATA_RENDER', 'AFTER', $this, 'find_TocPosition', []
            );
            $controller->register_hook(
                'TPL_TOC_RENDER', 'BEFORE', $this, 'tpl_toc', []
            );
            $controller->register_hook(
                'TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'show_HtmlToc', []
            );
        }
    }


    /**
     * Exports configuration settings to $JSINFO
     */
    function _exportToJSINFO(Doku_Event $event) {
        global $JSINFO, $INFO, $ACT;
        // TOC control should be changeable in only normal page
        if (( empty($ACT) || ($ACT=='show') || ($ACT=='preview')) == false) return;

        // retrieve from metadata
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        $tocState = $metadata['toc']['state'];
        if (isset($tocState)) {
            $JSINFO['toc']['initial_state'] = $tocState;
        }
    }

    /**
     * Add javascript information to script meta headers
     */
    function _hookjs(Doku_Event $event) {
        $plugin_url = DOKU_REL.'lib/plugins/'.$this->getPluginName();
        $event->data['script'][] = [
            'type' => 'text/javascript',
            'charset' => 'utf-8',
            '_data' => '',
            'src' => $plugin_url.'/js/toc_status.js',
        ];
    }



    /**
     * PARSER_CACHE_USE event handler
     *
     * Manipulate cache validity (to get correct toc of other page)
     */
    function handleParserCache(Doku_Event $event) {
        $cache =& $event->data;
        if (!$cache->page) return;

        switch ($cache->mode) {
            case 'i':        // instruction cache
            case 'metadata': // metadata cache
                break;
            case 'xhtml':    // xhtml cache
                // request check with additional dependent files
                $metadata_key = 'plugin '.$this->getPluginName();
                $metadata_key.= ' '.'depends';
                $depends = p_get_metadata($cache->page, $metadata_key);
                if (!$depends) break;

                $cache->depends['files'] = isset($cache->depends['files'])
                        ? array_merge($cache->depends['files'], $depends)
                        : $depends;
        } // end of switch
        return;
    }

    /**
     * PARSER_METADATA_RENDER event handler
     *
     * Find toc box position in accordance with tocDisplay config
     * 直後にTOCを表示する見だしをtableofcontentsから探す（空見だしも対象）
     */
    function find_TocPosition(Doku_Event $event) {
        global $ID, $conf;

        $tocDisplay = $this->getConf('tocDisplay');
        if(!in_array($tocDisplay, ['0','1','2'])) return;

        // retrieve toc parameters from metadata storage
        $metadata =& $event->data['current']['plugin'][$this->getPluginName()];

        // toc will be rendered by {{TOC|INLINETOC}}
        if(isset($metadata['toc']['display'])) return;

        // auto toc disabled by ~~NOTOC~~ or tocminheads config setting
        $notoc = !$event->data['current']['internal']['toc'];
        if($notoc || ($conf['tocminheads'] == 0)) return;

        // no heading in the page
        $toc =& $event->data['current']['description']['tableofcontents'];
        if(!isset($toc) || empty($toc)) return;

        // now worth to seek potential toc box position from tableofcontents
        // 直後にTOCを表示する見だしの識別ID hid0を探す（空見だしも対象）
        // xhtml_rendererの headerメソッドは、title0 を引数とするため、
        // アクセスには title0ベースの 識別ID hid0 が有用

        $toc_hid0 = '';
        switch ($tocDisplay) {
            case '0': // after the First any level heading
                $toc_hid0 = $toc[0]['hid0'];
                break;
            case '1': // after the First Level 1 heading
            case '2': // after the First Level 2 heading
                foreach ($toc as $k => $item) {
                    if ($item['level'] == $tocDisplay) {
                        $toc_hid0 = $item['hid0'];
                        break;
                    }
                }
                break;
        } // end of switch

        // store toc_hid0 into matadata storage
        if ($toc_hid0) {
            // xhtml renderer側で <!-- TOC_HERE --> をセットする
            $metadata['toc']['display'] = 'toc';
            $metadata['toc']['hid'] = $toc_hid0;
        }
    }

    /**
     * TPL_TOC_RENDER event handler
     *
     * Adjust global TOC array according to a given config settings
     * This method may called from TPL_CONTENT_DISPLAY event handler
     *
     * @see also inc/template.php function tpl_toc($return = false)
     */
    function tpl_toc(Doku_Event $event) {
        global $INFO, $ACT, $TOC, $conf;

        if ($ACT == 'admin') {
            $toc = [];
            // try to load admin plugin TOC
            if ($plugin = plugin_getRequestAdminPlugin()) {
                $toc = $plugin->getTOC();
                $TOC = $toc; // avoid later rebuild
            }
            // error_log(' '.$event->name.' admin toc='.var_export($toc,1));
            $event->data = $toc;
            return;
        }

        // retrieve toc parameters from metadata storage
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        $tocDisplay  = $metadata['toc']['display'] ?? $this->getConf('tocDisplay');
        $toptoclevel = $metadata['toc']['toptoclevel'] ?? $conf['toptoclevel'];
        $maxtoclevel = $metadata['toc']['maxtoclevel'] ?? $conf['maxtoclevel'];
        $tocminheads = $conf['tocminheads'];

        if ($event->name == 'TPL_TOC_RENDER') {
            if ($tocDisplay != 'default') {
                // stop prepending TOC box to the default position (top right corner)
                // of the page by empty toc
                // note: this method is called again to build html toc
                //       from TPL_CONTENT_DISPLAY event handler
                $toc = [];
                $event->data = $toc;
                return;
            }
        }

        $toc = $INFO['meta']['description']['tableofcontents'] ?? [];
        $notoc = !($INFO['meta']['internal']['toc']); // true if toc should not be displayed

        foreach ($toc as $k => $item) {
            if (empty($item['title'])
                || ($item['level'] < $toptoclevel)
                || ($item['level'] > $maxtoclevel)
            ) {
                unset($toc[$k]);
            }
            $item['level'] = $item['level'] - $toptoclevel +1;
        }
        if ( $notoc || ($tocminheads == 0) || (count($toc) < $tocminheads) ) {
            $toc = [];
        }

        switch ($event->name) {
            case 'TPL_TOC_RENDER':
                $event->data = $toc;
                return;
            case 'TPL_CONTENT_DISPLAY':
                // build html of the table of contents
                return $this->html_TOC($toc, $metadata['toc']);
            default:
                return $toc;
        } // end of switch
    }

    /**
     * TPL_CONTENT_DISPLAY
     *
     * Insert XHTML of auto-toc at dedicated place
     *     'default': top of the content
     * The placeholder (<!-- TOC_HERE|INLINETOC_HERE -->) has been rendered
     * according to "tocDisplay" config by xhtml_renderer header method where:
     *     0: after the first heading
     *     1: after the first level 1 heading
     *     2: after the first level 2 heading
     * or, elsewhere in the content by plugin's render method.
     */
    function show_HtmlToc(Doku_Event $event) {
        global $INFO, $ID, $ACT;
        $debug = strtoupper(get_class($this)).' '.$event->name;  //デバッグ用

        if (!in_array($ACT, ['show', 'preview'])) {
            return;
        }

        // retrieve toc parameters from metadata storage
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        $tocProps = $metadata['toc'];

        // return if no placeholder has rendered
        if (!isset($tocProps['display']) || ($tocProps['display'] == 'none')) {
            return;
        }

        $tocDisplay = $tocProps['display'] ?? 'toc'; // 未設定時は標準TOC

        $search = '<!-- '.strtoupper($tocDisplay).'_HERE -->';

        if (strpos($event->data, $search) === false) {
            error_log($debug.' ACT='.$ACT.' placeholder '.$search.' not found in page '.$INFO['id']);
            return;
        }

        // prepare html of table of content
        $html_toc = $this->tpl_toc($event);

        // replace PLACEHOLDER with html of table of content
        $content = $event->data;
        $replace = $html_toc;
        $content = str_replace($search, $replace, $content, $count);

        if ($count > 1) {
            error_log(' '.$s.' '.$event->name.'  wrong? count='.$count.' > 1 in page '.$ID);
            return; // something wrong?, toc must appear once in the page
        }
        $event->data = $content;
    }


    /* -----------------------------------------------------------------------*/

    /**
     * Return the TOC or INLINETOC rendered to XHTML
     */
    private function html_TOC(array $toc, array $tocProps=[]) {

        if ($tocProps == []) {
            // use DW original functions defined inc/html.php file.
            return html_TOC($toc);
        }
        if(!count($toc)) return '';

            /*
                    'display'     => $tocDisplay, // TOC box PlaceHolder名or表示位置
                    'state'       => $tocState,   // TOC box 開閉状態 -1:close
                    'toptoclevel' => $topLv,      // TOC 見だし範囲の上位レベル
                    'maxtoclevel' => $maxLv,      // TOC 見だし範囲の下位レベル
                    'class'       => $tocClass,   // TOC box 微調整用CSSクラス名
            */

            global $lang;

            // toc properties
            $tocTitle   = $tocProps['title'] ?? $lang['toc'];
            $tocDisplay = $tocProps['display'] ?? 'toc'; // 未設定時は標準TOC
            switch ($tocDisplay) {
                case 'none':
                    return '';
                case 'toc':
                    $tocVariant = 'dw__toc'; // TOC box basic design (CSS class)
                    break;
                case 'inlinetoc':
                    $tocVariant = 'dw__inlinetoc';
                    break;
                default:
                    return '';
            } // end of switch
            $tocClass = implode(' ', [$tocVariant, $tocProps['class']]);

            $attr = ['id' => 'dw__toc', 'class' => $tocClass];

            $out  = '<!-- TOC START -->'.DOKU_LF;
         // $out .= '<div id="dw__toc" class="dw__toc">'.DOKU_LF;
            $out .= '<div '.buildAttributes($attr).'>'.DOKU_LF;
            $out .= '<h3 class="toggle">'.hsc($tocTitle).'</h3>'.DOKU_LF;
            $out .= '<div>'.DOKU_LF;
            $out .= html_buildlist($toc,'toc','html_list_toc','html_li_default',true);
            $out .= '</div>'.DOKU_LF.'</div>'.DOKU_LF;
            $out .= '<!-- TOC END -->'.DOKU_LF;
            return $out;
    }

}
