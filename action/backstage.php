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

class action_plugin_headings_backstage extends DokuWiki_Action_Plugin {

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        always: { // event handler hook must be executed "earlier" than default
            $controller->register_hook(
               'PARSER_HANDLER_DONE', 'BEFORE', $this, 'rewrite_header_instructions', []
            );
            $controller->register_hook(
                'PARSER_METADATA_RENDER', 'BEFORE', $this, 'extend_TableOfContents', ['before']
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
     * Create a XHTML valid linkid from a given heading title
     * allow '.' in linkid, which should be match #[a-z][a-z0-9._-]*#
     *
     * @see also DW original sectionID() method defined in inc/pageutils.php
     */
    private function sectionID($title, &$check) {
        $title = str_replace(array(':'),'', cleanID($title));
        // remove suffix number that appended for duplicated title in the page, like title_1
        $title = preg_replace('/_[0-9]*$/','', $title);
        $newtitle = ltrim($title,'0123456789._-');
        if (empty($newtitle)) {
            // here, title consists [0-9._-]
            $title = 'section'.$title;
        } else {
            $title = $newtitle;
        }

        if (is_array($check)) {
            // make sure tiles are unique
            if (!array_key_exists ($title, $check)) {
                $check[$title] = 0;
            } else {
                $check[$title]++; // increment counts
                $title .= '_'.$check[$title]; // append '_' and count number to title
                $check[$title] = 0;
            }
        }

        return $title;
    }

    /**
     * PARSER_HANDLER_DONE event handler
     * 
     * Propagate extra information to xhtml renderer
     */
    function rewrite_header_instructions(Doku_Event $event) {
        global $ID;
        $headers = []; // memory once used hid

        $instructions =& $event->data->calls;

        // rewrite header instructions
        foreach ($instructions as $k => &$instruction) {
            if ($instruction[0] == 'header') {
                [$title, $level, $pos] = $instruction[1];
                if ($instructions[$k+2][1][0] == 'headings_handler') {
                    $hid = sectionID($instructions[$k+2][1][1][4], $headers);
                    $extra = [
                        'number' => $instructions[$k+2][1][1][3],
                        'hid'    => $hid,
                        'title'  => $instructions[$k+2][1][1][5],
                        'xhtml'  => $instructions[$k+2][1][1][6],
                    ];
                } else {
                    $hid = sectionID($title, $headers);
                    $extra = [
                        'hid'    => $hid,
                        'title'  => $title,
                    ];
                }
                $instruction[1] = [$title, $level, $pos, $extra];
            }
        }
        unset($instruction);
    }

    /**
     * PARSER_METADATA_RENDER event handler
     *
     * Extends TableOfContents database that holds All headings
     */
    function extend_TableOfContents(Doku_Event $event, array $param) {
        global $ID, $conf;
        static $tocminheads, $toptoclevel, $maxtoclevel;

        isset($tocminheads) || $tocminheads = $conf['tocminheads'];
        isset($toptoclevel) || $toptoclevel = $conf['toptoclevel'];
        isset($maxtoclevel) || $maxtoclevel = $conf['maxtoclevel'];

        if ($param[0] == 'before') {
            $conf['tocminheads'] = 1;
            $conf['toptoclevel'] = 1;
            $conf['maxtoclevel'] = 5;
            return;
        } else {
            $conf['tocminheads'] = $tocminheads;
            $conf['toptoclevel'] = $toptoclevel;
            $conf['maxtoclevel'] = $maxtoclevel;
        }

        $toc =& $event->data['current']['description']['tableofcontents'];
        $count_toc = is_array($toc) ? count($toc) : null;
        // retrieve from metadata
        $metadata =& $event->data['current']['plugin_include'];
        $headers = []; // memory once used hid

        // Generate tableofcontents based on instruction data
        $tableofcontents = [];
        $instructions = p_cached_instructions(wikiFN($ID), true, $ID) ?? [];
        foreach ($instructions as $instruction) {
            if ($instruction[0] == 'header') {
                // update hid
                $hid = sectionID($instruction[1][3]['hid'], $headers);
                $tableofcontents[] = [
                    'hid'    => $hid,
                    'level'  => $instruction[1][1],
                    'pos'    => $instruction[1][2],
                    'number' => $instruction[1][3]['number'] ?? null,
                    'title'  => $instruction[1][3]['title'] ?? '',
                    'xhtml'  => $instruction[1][3]['xhtml'] ?? '',
                    'type'   => 'ul',
                ];
            } elseif ($instruction[0] == 'plugin'
                && $instruction[1][0] == 'headings_include'
                && in_array($instruction[1][1][0], ['section','page']) // mode
            ){
                $pos  = $instruction[2];
                $page = $instruction[1][1][1];
                $sect = $instruction[1][1][2];
                // get headers from metadata that is stored by include syntax component
                $data = $metadata['tableofcontents'][$pos] ?? [];
                foreach ($data as $id => $included_headers) {
                    foreach ($included_headers as $item) {
                        $item['hid'] = sectionID($item['hid'], $headers);
                        $tableofcontents[] = $item;
                    }
                }
            }
        } // end of foreach

        // set pagename
        $count_headers = count($tableofcontents);
        if ($count_headers && $tableofcontents[0]['title']) {
            $event->data['current']['title'] = $tableofcontents[0]['title'];
        }

        if ($count_toc != $count_headers) {
            $debug = $event->name.': ';
            $s = 'toc counts ('.$count_toc.') is not equal to ';
            $s.= 'instruction-based headings counts ('.$count_headers.') in '.$ID;
            error_log($debug.$s);
        }
        $toc = $tableofcontents;
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
