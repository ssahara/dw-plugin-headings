<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * Extends TOC feature.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */
if (!defined('DOKU_INC')) die();

class action_plugin_headings_toc extends DokuWiki_Action_Plugin
{
    /**
     * Register event handlers
     */
    public function register(Doku_Event_Handler $controller) {
        $controller->register_hook(
            // event handler hook should be executed "earlier" than default
            'TPL_ACT_RENDER', 'BEFORE', $this, '_setPrependToc', [], -100
        );
        $controller->register_hook(
            'TPL_TOC_RENDER', 'BEFORE', $this, 'tpl_toc', []
        );
        $controller->register_hook(
            'TPL_CONTENT_DISPLAY', 'BEFORE', $this, 'show_HtmlToc', []
        );

        $controller->register_hook(
            'DOKUWIKI_STARTED', 'BEFORE', $this, '_exportToJSINFO', []
        );
        $controller->register_hook(
            'TPL_METAHEADER_OUTPUT', 'BEFORE', $this, '_hookjs', []
        );
    }

    /* -----------------------------------------------------------------------*/

    /**
     * TPL_ACT_RENDER event handler
     *
     * Stop prepending built-in TOC box to the original position (top right corner)
     * of the page
     */
    public function _setPrependToc(Doku_Event $event, array $param)
    {
        global $INFO, $ACT;

        if ($ACT !== 'show') return;

        if ($this->getConf('tocDisplay') == 'disabled') {
            $INFO['prependTOC'] = $INFO['meta']['internal']['toc'];
            return;
        }

        // retrieve toc parameters from metadata storage
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        $tocDisplay = $metadata['toc']['display'] ?? $this->getConf('tocDisplay');
        $notoc = !($INFO['meta']['internal']['toc']);

        if ($tocDisplay !== 'top' || $notoc) {
            $INFO['prependTOC'] = false;
        } else {
            //prepend placeholder for TOC
            echo '<!-- TOC_HERE -->'.DOKU_LF;

            // 常に Built-in/Auto TOC はページコンテンツに追加しない
            $INFO['prependTOC'] = false;
            $metadata['toc']['display'] = 'toc'; // 注意 .meta には反映されない
        }
    }

    /* -----------------------------------------------------------------------*/

    /**
     * TPL_TOC_RENDER event handler
     *
     * Adjust global TOC array according to a given config settings
     * This method may called from TPL_CONTENT_DISPLAY event handler
     *
     * @see also inc/template.php function tpl_toc($return = false)
     */
    public function tpl_toc(Doku_Event $event)
    {
        global $INFO, $ACT, $TOC, $conf;

        if ($ACT === 'admin') return;

        // retrieve toc parameters from metadata storage
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        $tocDisplay  = $metadata['toc']['display'] ?? $this->getConf('tocDisplay');

        if ($event->name == 'TPL_TOC_RENDER') {
            if ($tocDisplay != 'top' || !$INFO['prependTOC']) {
                // stop prepending TOC box to the original position (top right corner)
                // of the page by empty toc
                // Preview 時には $INFO は利用できないことに注意
                $event->data = $toc = [];
                return;
            }
        }

        // load helper object
        static $hpp; // headings preprocessor object
        isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());


        $toc = $INFO['meta']['description']['tableofcontents'] ?? [];

        // filter toc items
        $toptoclevel = $metadata['toc']['toptoclevel'] ?? $conf['toptoclevel'];
        $maxtoclevel = $metadata['toc']['maxtoclevel'] ?? $conf['maxtoclevel'];
        $toc = $hpp->toc_filter($toc, $toptoclevel, $maxtoclevel);

        if (count($toc) < $conf['tocminheads']) {
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
     *     'top': top of the content (DokuWiki default)
     * The placeholder (<!-- TOC_HERE|INLINETOC_HERE -->) has been rendered
     * according to "tocDisplay" config by xhtml_renderer header method where:
     *     0: after the first heading
     *     1: after the first level 1 heading
     *     2: after the first level 2 heading
     * or, elsewhere in the content by plugin's render method.
     */
    public function show_HtmlToc(Doku_Event $event)
    {
        global $INFO, $ID, $ACT;

        if ($this->getConf('tocDisplay') == 'disabled') return;
        if (!in_array($ACT, ['show', 'preview'])) return;

        // retrieve toc parameters from metadata storage
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        $tocProps = $metadata['toc'] ?? [];

        // return if no placeholder has rendered
        if (!isset($tocProps['display'])) return;
        if (!in_array($tocProps['display'], ['toc','inlinetoc'])) return;

        // placeholder
        $search = '<!-- '.strtoupper($tocProps['display']).'_HERE -->';

        // prepare html of table of content (call TPL_TOC_RENDER event handler)
        $html_toc = $this->tpl_toc($event);

        // replace PLACEHOLDER with html of table of content
        $content = $event->data;
        $replace = $html_toc;
        $content = str_replace($search, $replace, $content, $count);

        // debug
        if ($count == 0 || $count > 1) {
            $debug = $event->name.': ';
            $debug.= 'placeholder '.$search.' replaced '.$count.' times in '.$INFO['id'];
            error_log($debug);
            if ($ACT == 'preview') msg($debug, -1);
            return;
        }
        $event->data = $content;
    }

    /**
     * Return the TOC or INLINETOC rendered to XHTML
     * called from TPL_TOC_RENDER event handler
     */
    private function html_TOC(array $toc, array $tocProps=[]) {

        if ($tocProps == []) {
            // use DW original functions defined inc/html.php file.
            return html_TOC($toc);
        }
        if (!count($toc)) return '';

            global $lang;

            // toc properties
            $tocTitle   = $tocProps['title'] ?? $lang['toc'];
            $tocDisplay = $tocProps['display'] ?? 'toc';
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

    /* -----------------------------------------------------------------------*/

    /**
     * Exports configuration settings to $JSINFO
     */
    public function _exportToJSINFO(Doku_Event $event)
    {
        global $JSINFO, $INFO, $ACT;
        // TOC control should be changeable in only normal page
        if (( empty($ACT) || ($ACT=='show') || ($ACT=='preview')) == false) return;

        // retrieve from metadata
        $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
        if (isset($metadata['toc']['initial_state'])) {
            $JSINFO['toc']['initial_state'] = $metadata['toc']['initial_state'];
        }
    }

    /**
     * Add javascript information to script meta headers
     */
    public function _hookjs(Doku_Event $event)
    {
        $plugin_url = DOKU_REL.'lib/plugins/'.$this->getPluginName();
        $event->data['script'][] = [
            'type' => 'text/javascript',
            'charset' => 'utf-8',
            '_data' => '',
            'src' => $plugin_url.'/js/toc_status.js',
        ];
    }

}
