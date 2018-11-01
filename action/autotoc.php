<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_headings_autotoc extends DokuWiki_Action_Plugin {

    // keep toc config parameters
    private $tocminheads, $toptoclevel, $maxtoclevel;

    function __construct() {
        global $conf;
        $this->tocminheads = $conf['tocminheads'];
        $this->toptoclevel = $conf['toptoclevel'];
        $this->maxtoclevel = $conf['maxtoclevel'];
    }

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {

        $controller->register_hook(
            'DOKUWIKI_STARTED', 'BEFORE', $this, '_exportToJSINFO', []
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
     * PARSER_METADATA_RENDER event handler
     *
     * 直後にTOCを表示する見だしを探す（空見だしも対象）
     */
    function find_TocPosition(Doku_Event $event) {
        global $ID, $conf;

        $toc =& $event->data['current']['description']['tableofcontents'];
        if (!isset($toc)) return;

        $notoc = !$event->data['current']['internal']['toc'];

        if ( $notoc || (empty($toc))
            || ($conf['tocminheads'] == 0) || (count($toc) < $conf['tocminheads'])
        ) {
            // ~~NOTOC~~指定ある、あるいは 見だし数が0、
            // または、TOC見出し表示数下限値 の設定を満足しない場合
            // auto TOCは表示しない。 ここで終了
            return;
        }

        // 直後にTOCを表示する見だしの識別ID hid0を探す（空見だしも対象）
        // xhtml_rendererの headerメソッドは、title0 を引数とするため、
        // アクセスには title0ベースの 識別ID hid0 が有用
        $toc_hid0 = 0;
        if ($this->getConf('tocPosition') == 6) {
            // after the First any level heading
            $toc_hid0 = $toc[0]['hid0'];

        } elseif (in_array($this->getConf('tocPosition'), [1,2])) {
            // after the First Level 1 or Level 2 heading
            foreach ($toc as $k => $item) {
                if ($item['level'] == $this->getConf('tocPosition')) {
                    $toc_hid0 = $item['hid0'];
                    break;
                }
            }
        }

        // store toc_hid0 into matadata storage
        $metadata =& $event->data['current']['plugin'][$this->getPluginName()];
        $metadata['toc']['hid'] = $toc_hid0 ?: null;
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

        if ($event->name == 'TPL_TOC_RENDER') {
            if (in_array($this->getConf('tocPosition'), [1,2,6])) {
                // stop prepending TOC box to the default position (top right corner)
                // of the page by empty toc
                // note: this method is called again to build html toc
                //       from TPL_CONTENT_DISPLAY event handler
                $toc = [];
                $event->data = $toc;
                return;
            }
        }

        // retrieve toc parameters from metadata storage
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        $tocminheads = $metadata['toc']['tocminheads'] ?? $conf['tocminheads'];
        $toptoclevel = $metadata['toc']['toptoclevel'] ?? $conf['toptoclevel'];
        $maxtoclevel = $metadata['toc']['maxtoclevel'] ?? $conf['maxtoclevel'];

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

        if ($event->name == 'TPL_TOC_RENDER') {
            $event->data = $toc;
        } else if ($event->name == 'TPL_CONTENT_DISPLAY') {
            return html_TOC($toc);
        } else {
            return $toc;
        }
    }

    /**
     * TPL_CONTENT_DISPLAY
     *
     * insert XHTML of auto-toc at tocPosition where
     *  0: top of the content (default)
     *  1: after the first level 1 heading
     *  2: after the first level 2 heading
     *  6: after the first heading
     */
    function show_HtmlToc(Doku_Event $event) {
        global $ID, $ACT, $TOC;
        $debug = strtoupper(get_class($this)).' '.$event->name;  //デバッグ用

        if (!in_array($ACT, ['show', 'preview'])) {
            return;
        }

        if (strpos($event->data, '<!-- TOC_HERE -->') === false) {
            error_log($debug.' ACT='.$ACT.' TOC_HERE not found in page '.$ID);
            return;
        }

        // prepare html of table of content
        $html_toc = $this->tpl_toc($event);

        // replace PLACEHOLDER with html_toc
        $content = $event->data;
        $search  = '<!-- TOC_HERE -->';
        $replace = $html_toc;
        $content = str_replace($search, $replace, $content, $count);

        if ($count > 1) {
            error_log(' '.$s.' '.$event->name.'  wrong? count='.$count.' > 1 in page '.$ID);
            return; // something wrong?, toc must appear once in the page
        }
        $event->data = $content;
    }

}
