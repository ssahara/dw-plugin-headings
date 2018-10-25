<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_headings extends DokuWiki_Action_Plugin {

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook(
            'PARSER_METADATA_RENDER', 'AFTER', $this, '_modifyTableOfContents'
        );
        $controller->register_hook(
            'TPL_TOC_RENDER', 'BEFORE', $this, '_modifyGlobalToc'
        );
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

    }

    /**
     * TPL_TOC_RENDER event handler
     *
     */
    function _modifyGlobalToc(Doku_Event $event) {

        $toc =& $event->data; // = $TOC array

        foreach ($toc as $k => $item) {
            if(empty($item['title'])) unset($toc[$k]);
        }
    }

}
