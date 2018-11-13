<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * Extends TableOfContents database that holds All headings of the page
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_headings_preprocess extends DokuWiki_Action_Plugin {

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        always: { // event handler hook must be executed "earlier" than default
            $controller->register_hook(
               'PARSER_HANDLER_DONE', 'BEFORE', $this, 'rewrite_header_instructions', []
            );
            $controller->register_hook(
                'PARSER_METADATA_RENDER', 'AFTER', $this, 'extend_TableOfContents', [], -100
            );
        }
        if ($this->getConf('tocDisplay') == 'disabled') {
            $controller->register_hook(
               'TPL_TOC_RENDER', 'BEFORE', $this, 'tpl_toc', []
            );
        }
    }



    /**
     * PARSER_HANDLER_DONE event handler
     * 
     * Rewrite header instructions
     */
    function rewrite_header_instructions(Doku_Event $event) {
        global $ID;

        $instructions =& $event->data->calls;

        foreach ($instructions as $k => &$instruction) {
            if ($instruction[0] == 'header') {
                [$hid, $level, $pos] = $instruction[1];
                $title = $instructions[$k+2][1][1][5];
                $xhtml = $instructions[$k+2][1][1][6];
                $instruction[1] = [$hid, $level, $pos, $title, $xhtml];
            }
        }
        unset($instruction);
    }

    /**
     * PARSER_METADATA_RENDER event handler
     *
     * Extends TableOfContents database that holds All headings
     */
    function extend_TableOfContents(Doku_Event $event) {
        global $ID;

        $toc =& $event->data['current']['description']['tableofcontents'];
        if (!isset($toc)) return;

        // retrieve from metadata
        $metadata =& $event->data['current']['plugin'][$this->getPluginName()];
        $headings = $metadata['tableofcontents'];
        if (!isset($headings)) return;

        $headers0 = []; // memory once used hid (title0)
        $headers1 = []; // memory once used hid (new hid)

        foreach ($headings as &$item) {
            // $item = [
            //          'page' => $page, 'pos' => $pos,
            //          'level' => $level, 'title0' => $title0,
            //          'title' => $title, 'xhtml' => $xhtml, 'hid' => $hid,
            //         ];
            $item['hid']  = sectionID($item['hid'], $headers1);
            $item['hid0'] = sectionID($item['title0'], $headers0);
            $item['type'] = 'ul';
        }
        unset($item);

        $toc = $headings; // overwrite tableofcontents

        // remove plugin's metadata
        unset($metadata['tableofcontents']);
    }


    /**
     * TPL_TOC_RENDER event handler
     *
     * Adjust global TOC array according to a given config settings
     * @see also inc/template.php function tpl_toc($return = false)
     */
    function tpl_toc(Doku_Event $event) {
        global $INFO, $ACT, $conf;

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

        $notoc = !($INFO['meta']['internal']['toc']); // true if toc should not be displayed

        if ($notoc || ($conf['tocminheads'] == 0)) {
            $event->data = $toc = [];
            return;
        }

        $toc = $INFO['meta']['description']['tableofcontents'] ?? [];
        foreach ($toc as $k => $item) {
            if (empty($item['title'])
                || ($item['level'] < $conf['toptoclevel'])
                || ($item['level'] > $conf['maxtoclevel'])
            ) {
                unset($toc[$k]);
            }
            $item['level'] = $item['level'] - $conf['toptoclevel'] +1;
        }
        $event->data = (count($toc) < $conf['tocminheads']) ? [] : $toc;
    }
}
