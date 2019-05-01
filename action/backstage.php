<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * Extends TableOfContents database that holds All headings of the page
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if (!defined('DOKU_INC')) die();

class action_plugin_headings_backstage extends DokuWiki_Action_Plugin
{
    /**
     * Register event handlers
     */
    public function register(Doku_Event_Handler $controller)
    {
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
        $controller->register_hook(
            'PARSER_CACHE_USE', 'BEFORE', $this, '_handleParserCache', []
        );
    }


    /**
     * PARSER_HANDLER_DONE event handler
     * 
     * Propagate extra information to xhtml renderer
     */
    public function rewrite_header_instructions(Doku_Event $event)
    {
        global $ID;
        $section_level = 0;

        // load helper object
        isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());

        $instructions =& $event->data->calls;

        // rewrite header instructions
        foreach ($instructions as $k => &$instruction) {
            // get call name
            $call = ($instruction[0] == 'plugin')
                ? 'plugin_'.$instruction[1][0]
                : $instruction[0];

            switch ($call) {
                case 'section_open':
                    $section_level = $instruction[1][0];
                    break;
                case 'header':
                    [$text, $level, $pos] = $instruction[1];
                    //$text = $instruction[1][0];
                    [$number, $hid, $title, $extra] = []; // set variables null

                    if ($instructions[$k+2][1][0] == 'headings_handler') {
                        $data = $instructions[$k+2][1][1];
                        [$page, $pos, $level, $number, $hid, $title, $xhtml] = $data;

                        // set tentative hid, not unique in the page, which should be checked
                        // in PARSER_METADATA_RENDER event handler where duplicated hid will
                        // be suffixed considering included pages/sections.
                        isset($hid) || $hid = $hpp->sectionID($title, $check=[]);

                    } else {
                        // fallback when renderer_xhtml is not Heading PreProcessor (HPP) plugin
                        $title = $xhtml = $text;
                        $hid = $hpp->sectionID($title, $check=[]);
                    }

                    $extra = compact('number', 'hid', 'title', 'xhtml');
                    $instruction[1] = [$text, $level, $pos, $extra];
                    break;
                case 'plugin_headings_handler':
                  //unset($instructions[$k]);
                    break;
                case 'plugin_headings_toc':
                    // 要検討：Built-in toc を初出限定にする処理を加えるか？
                    $data = $instruction[1][1];
                    [$pattern, $id, $tocProps] = $data;
                    break;
                case 'plugin_headings_include':
                    $instruction[1][1][4] = $section_level;
                    break;
            } // end of switch $call
        }
        unset($instruction);
    }

    /**
     * PARSER_METADATA_RENDER event handler
     *
     * Extends TableOfContents database that holds All headings
     */
    public function extend_TableOfContents(Doku_Event $event, array $param)
    {
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

        $toc   =& $event->data['current']['description']['tableofcontents'];
        $notoc = !$event->data['current']['internal']['toc'];
        $count_toc = is_array($toc) ? count($toc) : null;

        static $hpp; // headings preprocessor object
        isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());

        // STEP 1: collect all headers of the page from instruction data
        $header_instructions = [];
        $instructions = p_cached_instructions(wikiFN($ID), true, $ID) ?? [];
        $metadata =& $event->data['current']['plugin'][$this->getPluginName()];

        foreach ($instructions as $instruction) {
            // get call name
            $call = ($instruction[0] == 'plugin')
                ? 'plugin_'.$instruction[1][0]
                : $instruction[0];

            switch ($call) {
                case 'header':
                    $header_instructions[] = $instruction[1];
                    break;
                case 'plugin_headings_include':
                    if (!in_array($instruction[1][1][0], ['section','page'])) {
                        break;
                    }
                    $pos  = $instruction[2];
                    // $page = $instruction[1][1][1];
                    // $sect = $instruction[1][1][2];
                    // get headers from metadata (stored by include syntax component)
                    $data = $metadata['include'][$pos] ?? [];
                    foreach ($data as $page => $included_headers) {
                        $header_instructions = array_merge(
                                   $header_instructions,
                                   $included_headers
                        );
                    }
                    break;
            }
        } // end of foreach $instructions

        // STEP 2: Generate tableofcontents from header instructions
        $tableofcontents = [];
        $first_headers   = [];
        $headers         = []; // memory once used hid
        $initHeaderCount = true;

        foreach ($header_instructions as $header_args) {
            [$text, $level, $pos, $extra] = $header_args;
            // import variables from extra array; $hid, $number, $title, $xhtml
            $extra = $hpp->resolve_extra_instruction($extra, $level, $this->initHeaderCount);
            $extra = $hpp->set_numbered_title($extra);
            extract($extra);

            // ensure unique hid
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

            // store hid of the first appeared header of each level
            if (!array_key_exists($level, $first_headers)) {
                $first_headers[$level] = $hid;
            }
        } // end of foreach $header_instructions

        // STEP 3: Find position of built-in toc box
        /*
         * Find toc box position in accordance with tocDisplay config
         * if relevant heading (including empty heading) found in tableofcontents
         * store it's hid into metadata storage, which will be used xhtml renderer
         * to output placeholder for auto-TOC to be replaced in TPL_CONTENT_DISPLAY
         * event handler
         */
        $metadata =& $event->data['current']['plugin'][$this->getPluginName()];

        if (count($tableofcontents) >= $conf['tocminheads']) {
            $tocDisplay = $this->getConf('tocDisplay');
            $toc_hid = (array_key_exists($tocDisplay, $first_headers))
                ? $first_headers[$tocDisplay]
                : null;
            // store toc_hid into matadata storage for xhtml renderer
            if (!isset($metadata['toc']['display']) && $toc_hid) {
                $metadata['toc']['hid'] = $toc_hid;
                $metadata['toc']['display'] = 'toc';
            }
        }

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
     * PARSER_CACHE_USE event handler
     *
     * Manipulate cache validity (to get correct toc of other page)
     * When Embedded TOC should refer to other page, check dependency
     * to get correct toc items from relevant .meta files
     */
    public function _handleParserCache(Doku_Event $event)
    {
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

}
