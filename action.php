<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_headings extends DokuWiki_Action_Plugin {

    private $toptoclevel, $maxtoclevel, $tocminheads;


    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS', 'BEFORE', $this, '_initTocConfig'
        );
        $controller->register_hook(
            'PARSER_CACAHE_USE', 'AFTER', $this, '_initTocConfig'
        );
        $controller->register_hook(
            'PARSER_METADATA_RENDER', 'AFTER', $this, '_modifyTableOfContents'
        );
        $controller->register_hook(
            'TPL_TOC_RENDER', 'BEFORE', $this, '_modifyGlobalToc'
        );
    }

    /**
     * Alter toc config parameters to catch up all headings in pages
     * and to store them in the metadata
     */
    function _initTocConfig(Doku_Event $event) {
        global $conf;

        switch ($event->name) {
            case 'ACTION_ACT_PREPROCESS':
                if ($event->data == 'admin') {
                    // set DokuWiki default values for admin type plugins
                    $conf['tocminheads'] = 1;
                    $conf['toptoclevel'] = 1;
                    $conf['maxtoclevel'] = 3;
                }
                break;

            case 'PARSER_CACHE_USE':
            default:
                // ensure all headings records to be stored in metadata
                if (!isset($this->tocminheads)) {
                    $this->tocminheads = $conf['tocminheads'];
                    $conf['tocminheads'] = 1;
                }
                if (!isset($this->toptoclevel)) {
                    $this->toptoclevel = $conf['toptoclevel'];
                    $conf['toptoclevel'] = 1;
                }
                if (!isset($this->maxtoclevel)) {
                    $this->maxtoclevel = $conf['maxtoclevel'];
                    $conf['maxtoclevel'] = 5;
                }
        } // end of switch
        //error_log(strtoupper($this->getPluginName()).' '.$event->name.' TocConfig altered ');
    }

    /**
     * PARSER_METADATA_RENDER event handler
     *
     */
    function _modifyTableOfContents(Doku_Event $event) {
        global $ID;

        $toc =& $event->data['current']['description']['tableofcontents'];
        if (!isset($toc)) return;

        $headings = $event->data['current']['plugin']['headings'];
        if (!isset($headings)) return;

        $toc = $headings;
    }

    /**
     * TPL_TOC_RENDER event handler
     * Adjust global TOC array according to a given config settings
     */
    function _modifyGlobalToc(Doku_Event $event) {
        global $conf;

        $toc =& $event->data; // = $TOC array

        $tocminheads = $this->tocminheads ?? $conf['tocminheads'];
        $toptoclevel = $this->toptoclevel ?? $conf['toptoclevel'];
        $maxtoclevel = $this->maxtoclevel ?? $conf['maxtoclevel'];

        foreach ($toc as $k => $item) {
            if (empty($item['title'])
                || ($item['level'] < $toptoclevel)
                || ($item['level'] > $maxtoclevel)
            ) {
                unset($toc[$k]);
            }
            $item['level'] = $item['level'] - $toptoclevel +1;
        }
        if (count($toc) < $tocminheads) {
            $toc = [];
        }
    }

}
