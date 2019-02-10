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
        always: {
            $controller->register_hook(
               'PARSER_HANDLER_DONE', 'BEFORE', $this, 'rewrite_header_instructions', []
            );
            $controller->register_hook(
                'PARSER_METADATA_RENDER', 'BEFORE', $this, 'extend_TableOfContents', ['before']
            );
            $controller->register_hook(
                // event handler hook must be executed "earlier" than default
                'PARSER_METADATA_RENDER', 'AFTER',  $this, 'extend_TableOfContents', [], -100
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
     * Propagate extra information to xhtml renderer
     */
    function rewrite_header_instructions(Doku_Event $event) {
        global $ID;
        static $id = ''; // memory current page id
        $headers = [];   // memory once used hid

        // load helper object
        isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());

        // chcek whether headerCount[] in _tiered_number() need to initialize
        $reset = ($id !== $ID) ? ($id = $ID) : false;

        $instructions =& $event->data->calls;

        // rewrite header instructions
        foreach ($instructions as $k => &$instruction) {
            if ($instruction[0] == 'header') {
                // [$text, $level, $pos] = $instruction[1];
                $text = $instruction[1][0];
                if ($instructions[$k+2][1][0] == 'headings_handler') {
                    $data = $instructions[$k+2][1][1];
                    [$page, $pos, $level, $number, $hid, $title, $xhtml] = $data;
                    $extra = [
                        'number' => $number,
                        'hid'    => $hid,
                        'title'  => $title,
                        'xhtml'  => $xhtml,
                    ];
                } else {
                    [$text, $level, $pos] = $instruction[1];
                    $extra = [
                        'hid'    => $hid,
                        'title'  => $title,
                    ];
                }
                $instruction[1] = [$text, $level, $pos, $extra];
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

        static $hpp; // headings preprocessor object
        isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());

        $headerCountInit = true;
        $headers = []; // memory once used hid

        // クロージャー
        $func_resolve_tocitem = function (array &$item, &$headerCountInit) {
            extract($item);
            // get tiered number for the heading
            if (isset($number)) {
                $tiered_number = $hpp->_tiered_number($level, $number, $headerCountInit);
            }
            // update hid
            if ($hid == '#') {
                $hid = (is_int($tiered_number[0]) ? 'section' : '').$tiered_number;
            } elseif (empty($hid)) {
                $hid = $title;
            }
            $item['number'] = $tiered_number;
            $item['hid'] = sectionID($hid, $headers);
        };


        // Generate tableofcontents based on instruction data
        $tableofcontents = [];
        $instructions = p_cached_instructions(wikiFN($ID), true, $ID) ?? [];
        foreach ($instructions as $instruction) {
            if ($instruction[0] == 'header') {
                [$text, $level, $pos, $extra] = $instruction[1];
                // import variables from extra array; $hid, $number, $title, $xhtml
                $extra = $hpp->resolve_extra_instruction($extra, $level, $this->headerCountInit);
                extract($extra);

                $hid = $hpp->sectionID($hid, $headers);
                $tableofcontents[] = [
                    'hid'    => $hid,
                    'level'  => $level, //$instruction[1][1]
                    'pos'    => $pos,   //$instruction[1][2]
                    'number' => $number ?? null,  // 階層番号文字列に変更する
                    'title'  => $title, //$instruction[1][3]['title']
                    'xhtml'  => $xhtml, //$instruction[1][3]['xhtml']
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
                        // import variables from an array into the current symbol table
                        // [$hid, $level, $pos, $number, $title, $xhtml, $type] = array_values($item);
                        extract($item);

                        // get tiered number for the heading
                        $tiered_number = (isset($number))
                            ? $hpp->_tiered_number($level, $number, $this->headerCountInit)
                            : null;
                        // update hid
                        if ($hid == '#') {
                            $hid = (is_int($tiered_number[0]) ? 'section' : '').$tiered_number;
                        } elseif (empty($hid)) {
                            $hid = $title;
                        }
                        $hid = $hpp->sectionID($hid, $headers);
                        $tableofcontents[] = [
                            'hid'    => $hid,
                            'level'  => $level, //$item['level']
                            'pos'    => $pos,   //$item['pos']
                            'number' => $tiered_number ?? null,  // 階層番号文字列に変更する
                            'title'  => $title, //$item['title']
                            'xhtml'  => $xhtml, //$item['xhtml']
                            'type'   => 'ul',
                        ];
                    }
                }
            }
        } // end of foreach

        // set pagename
        $count_headers = count($tableofcontents);
        if ($count_headers && $tableofcontents[0]['title']) {
            $event->data['current']['title']
                = $event->data['persistent']['title'] ?? $tableofcontents[0]['title'];
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

        // modify toc items directly within loop by reference
        foreach ($toc as $k => &$item) {
            if (empty($item['title'])
                || ($item['level'] < $conf['toptoclevel'])
                || ($item['level'] > $conf['maxtoclevel'])
            ) {
                unset($toc[$k]);
            } elseif (isset($item['number'])) {
                // set numbered heading title
                // append figure space (U+2007) after tiered number to distinguish title
                $item['title'] = $item['number'].' '.$item['title'];
                $item['xhtml'] = '<span class="tiered_number">'.$item['number'].' </span>'
                                .$item['xhtml'];
            }
            $item['level'] = $item['level'] - $conf['toptoclevel'] +1;
        }
        unset($item);
        $event->data = (count($toc) < $conf['tocminheads']) ? [] : $toc;
    }
}
