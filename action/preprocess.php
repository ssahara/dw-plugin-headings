<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
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
        $controller->register_hook(
            'PARSER_METADATA_RENDER', 'AFTER', $this, 'extend_TableOfContents', [], -100
        );
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

        $headings = $event->data['current']['plugin']['headings']['tableofcontents'];
        if (!isset($headings)) return;

        // handler で生成した headings を tableofcontents 互換の toc データベースに変換する
        $headers0 = []; // memory once used hid (title0)
        $headers1 = []; // memory once used hid (new hid)

        foreach ($headings as &$item) {
            // $item = [
            //          'page' => $page, 'pos' => $pos,
            //          'level' => $level, 'title0' => $title0,
            //          'title' => $title, 'xhtml' => $xhtml, 'hid' => $hid,
            //         ];
            // $item を直接更新する
            $item['hid']  = sectionID($item['hid'], $headers1);
            $item['hid0'] = sectionID($item['title0'], $headers0);
            $item['type'] = 'ul';
        }
        unset($item);

        $toc = $headings; // overwrite tableofcontents

        // remove plugin's metadata
        unset($event->data['current']['plugin']['headings']['tableofcontents']);
    }

}
