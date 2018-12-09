<?php 
/** 
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * Include Plugin: displays a wiki page within another
 * Usage: 
 * {{page>page}} for "page" in same namespace 
 * {{page>:page}} for "page" in top namespace 
 * {{page>namespace:page}} for "page" in namespace "namespace" 
 * {{page>.namespace:page}} for "page" in subnamespace "namespace" 
 * {{page>page#section}} for a section of "page" 
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html) 
 * @author     Esther Brunner <wikidesign@gmail.com>
 * @author     Christopher Smith <chris@jalakai.co.uk>
 * @author     Gina Häußge, Michael Klier <dokuwiki@chimeric.de>
 */ 
 
if(!defined('DOKU_INC')) die();
 
class syntax_plugin_headings_include extends DokuWiki_Syntax_Plugin {

    /** @var $helper helper_plugin_include */
    var $helper = null;

    function getType() { return 'protected'; }
    function getPType(){ return 'block'; }
    function getSort() { return 30; }

    /**
     * Connect pattern to lexer
     */
    protected $mode, $pattern;

    function preConnect() {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern
        $this->pattern[0] = '{{INCLUDE\b.+?}}';  // {{INCLUDE [flags] >[id]#[section]}}
        $this->pattern[1] = '{{page>.+?}}';      // {{page>[id]&[flags]}}
        $this->pattern[2] = '{{section>.+?}}';   // {{section>[id]#[section]&[flags]}}
        $this->pattern[3] = '{{namespace>.+?}}'; // {{namespace>[namespace]#[section]&[flags]}}
        $this->pattern[4] = '{{tagtopic>.+?}}';  // {{tagtopic>[tag]&[flags]}}
    }

    function connectTo($mode) {
        if (!plugin_isdisabled('include')) {
            $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[1], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[2], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[3], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[4], $mode, $this->mode);
        }
    }

    /**
     * Handle the match
     *
     * @param string       $match   The current match
     * @param int          $state   The match state
     * @param int          $pos     The position of the match
     * @param Doku_Handler $handler The hanlder object
     * @return array The instructions of the plugin
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

      //static $includeHelper;
      //isset($includeHelper) || $includeHelper = $this->loadHelper('include', true);

        if (substr($match, 2, 7) == 'INCLUDE') {
            // use case {{INCLUDE [flags] >[id]#[section]}}
            [$flags, $page] = array_map('trim', explode('>', substr($match, 9, -2), 2));
            [$page, $sect] = explode('#', $page, 2);
            $flags = explode(' ', $flags);
            $mode = $sect ? 'section' : 'page';

            $page = $page ? cleanID($page) : $ID;
            $check = false;
            $sect = isset($sect) ? sectionID($sect, $check) : null;

            // check whether page and section exist using meta file
            $check = [];
            $toc = p_get_metadata($page,'description tableofcontents');
            $check['page'] = isset($toc);
            if (isset($toc) && $sect) {
                $map = array_column($toc, null, 'hid');
                $hid   = $map[$sect]['hid'] ?? null;
                $title = $map[$sect]['title'] ?? null;
                $check['sect'] = isset($hid);
            }
            $sect = $hid;
            $note = '';
            if (isset($check['sect']) && !$check['sect']) {
                $note = 'section not found!';
            } elseif (isset($check['page']) && !$check['page']) {
                $note = 'page not found!';
            } elseif (isset($check['page']) && $page == $ID) {
                $note = 'self page inclusion!';
            } elseif ($hid) {
                $note = '(#'.$hid.' '.$title.')';
            }
            $extra = [$match, $note];

        } else {
            // use case {{section>[id]#[section]&[flags]}}
            [$param, $flags] = explode('&', substr($match, 2, -2), 2);
            [$mode, $page, $sect] = preg_split('/>|#/u', $param, 3);
            $flags = explode('&', $flags);
            $check = false;
            $sect = isset($sect) ? sectionID($sect, $check) : null;
            $extra = [$match,''];
        }

        $level = null; // it will be set in PARSER_HANDLER_DONE event handler
        return $data = [$mode, $page, $sect, $flags, $level, $pos, $extra];
    }

    /**
     * Renders the included page(s)
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $ACT, $ID, $conf;

        // get data, of which $level has set in PARSER_HANDLER_DONE event handler
        [$mode, $page, $sect, $flags, $level, $pos, $extra] = $data;

        if ($format == 'xhtml' && $ACT == 'preview') {
            [$match, $note] = $extra;
            $renderer->doc .= '<code class="preview_note">'.$match.' '.$note.'</code>';
        }

        if ($format == 'metadata') {
            /** @var Doku_Renderer_metadata $renderer */
            $renderer->meta['plugin_include'] = [];
            $metadata =& $renderer->meta['plugin_include'];
        }

        // static stack that records all ancestors of the child pages
        static $page_stack = [];

        // when there is no id just assume the global $ID is the current id
        if (empty($page_stack)) $page_stack[] = $ID;

        $parent_id = $page_stack[count($page_stack)-1];
        $root_id = $page_stack[0];


        static $includeHelper;
        isset($includeHelper) || $includeHelper = $this->loadHelper('include', true);

        $flags = $includeHelper->get_flags($flags);

        // get included pages, of which each item has keys: id, exists, parent_id
        $pages = $this->_get_included_pages($mode, $page, $sect, $parent_id, $flags);

        if ($format == 'metadata') {
            // remove old persistent metadata of previous versions of the include plugin
            if (isset($renderer->persistent['plugin_include'])) {
                unset($renderer->persistent['plugin_include']);
                unset($renderer->meta['plugin_include']);
            }

            // 
            $metadata['instructions'][] = compact('mode', 'page', 'sect', 'parent_id', $flags);
            $metadata['pages'] = array_merge( (array)$metadata['pages'], $pages);
            $metadata['include_content'] = isset($_REQUEST['include_content']);
        }

        $secids = [];
        if ($format == 'xhtml' || $format == 'odt') {
            global $INFO;
          //$secids = p_get_metadata($ID, 'plugin_include secids');
            $secids = $INFO['meta']['plugin_include']['secids'];
        }

        foreach ($pages as $page) {
          //extract($page);
            $id = $page['id'];
            $exists = $page['exists'];

            if (in_array($id, $page_stack)) continue;
            array_push($page_stack, $id);

            if ($format == 'metadata') {
                // add references for backlink
                $renderer->meta['relation']['references'][$id] = $exists;
                $renderer->meta['relation']['haspart'][$id]    = $exists;

                if (!$sect && !$flags['firstsec'] && !$flags['linkonly']
                    && !isset($metadata['secids'][$id])
                ){
                    $metadata['secids'][$id] = [
                        'hid' => 'plugin_include__'.str_replace(':', '__', $id),
                        'pos' => $pos,
                    ];
                }
            }

            if (isset($secids[$id]) && $pos === $secids[$id]['pos']) {
                $flags['include_secid'] = $secids[$id]['hid'];
            } else {
                unset($flags['include_secid']);
            }

            $instructions = $this->_get_instructions($id, $sect, $level, $flags, $root_id, $secids);

            // store headers found in the instructions for complete tableofcontents
            // which is built later in PARSER_METADATA_RENDER event handler
            if ($format == 'metadata') {
                foreach ($instructions as $instruction) {
                    if ($instruction[0] == 'header') {
                        $metadata['tableofcontents'][$pos][] = [
                            'hid'    => $instruction[1][0],
                            'level'  => $instruction[1][1],
                            'pos'    => $instruction[1][2],
                            'number' => $instruction[1][3]['number'] ?? null,
                            'title'  => $instruction[1][3]['title'] ?? '',
                            'xhtml'  => $instruction[1][3]['xhtml'] ?? '',
                            'type'   => 'ul',
                        ];
                    }
                } // end of foreach
            }

            if (!$flags['editbtn']) {
                [$conf['maxseclevel'], $maxseclevel_org] = [0, $conf['maxseclevel']];
            }
            $renderer->nest($instructions);
            if (isset($maxseclevel_org)) {
                [$conf['maxseclevel'], $maxseclevel_org] = [$maxseclevel_org, null];
            }

            array_pop($page_stack);
        } //end of foreach

        // When all includes have been handled remove the current id
        // in order to allow the rendering of other pages
        if (count($page_stack) == 1) array_pop($page_stack);

        return true;
    }

    /* --------------------------------------------------------------------- *
     * Combine Helper
     *
     * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
     * @author     Esther Brunner <wikidesign@gmail.com>
     * @author     Christopher Smith <chris@jalakai.co.uk>
     * @author     Gina Häußge, Michael Klier <dokuwiki@chimeric.de>
     * @author     Michael Hamann <michael@content-space.de>
     *
     * 
     * --------------------------------------------------------------------- */

    /**
     * Helper functions for the include plugin and other plugins that want to include pages.
     */

    var $sec_close = true;
    var $includes  = array(); // deprecated - compatibility code for the blog plugin

    /**
     * Build a DokuWiki standard instruction array
     *
     * @author Satoshi Sahara <sahara.satoshi@gmail.com>
     * @see also https://www.dokuwiki.org/devel:parser#instructions_data_format
     */
    private function dwInstruction($method, array $params, $pos=null) {
        $instruction = [];
        $instruction[0] = $method;
        $instruction[1] = (array)$params;
        if (isset($pos)) {
            $instruction[2] = $pos;
        }
        return $instruction;
    }

    /**
     * Build an instruction array for syntax plugin components
     *
     * @author Satoshi Sahara <sahara.satoshi@gmail.com>
     */
    private function pluginInstruction($method, array $params, $pos=null) {
        return $this->dwInstruction('plugin',[$method, $params], $pos);
    }

    /**
     * Returns the converted instructions of a give page/section
     *
     * @author Michael Klier <chi@chimeric.de>
     * @author Michael Hamann <michael@content-space.de>
     */
    function _get_instructions($page, $sect, $lvl, $flags, $root_id=null, $included_pages=[]) {
        $key = ($sect) ? $page . '#' . $sect : $page;
        $this->includes[$key] = true; // legacy code for keeping compatibility with other plugins

        // keep compatibility with other plugins that don't know the $root_id parameter
        if (is_null($root_id)) {
            global $ID;
            $root_id = $ID;
        }

        // link only to the included pages instead of including the content
        if ($flags['linkonly']) {
            if (page_exists($page) || $flags['pageexists']  == 0) {
                if ($flags['title']) {
                    $render = METADATA_RENDER_USING_SIMPLE_CACHE;
                    $title = p_get_metadata($page,'title', $render);
                } else {
                    $title = '';
                }
                if ($flags['parlink']) {
                    $ins = [
                        $this->dwInstruction('p_open',[]),
                        $this->dwInstruction('internallink',[':'.$key, $title]),
                        $this->dwInstruction('p_close',[]),
                    ];
                } else {
                    $ins = [
                        $this->dwInstruction('internallink',[':'.$key, $title]),
                    ];
                }
            }else {
                $ins = [];
            }
            return $ins;
        }

        if (page_exists($page)) {
            global $ID;
            // Change the global $ID as otherwise plugins like the discussion plugin
            // will save data for the wrong page
            [$ID, $backupID] = [$page, $ID];
            $ins = p_cached_instructions(wikiFN($page), false, $page);
            $ID = $backupID;
        } else {
            $ins = [];
        }

        $this->_convert_instructions($ins, $lvl, $page, $sect, $flags, $root_id, $included_pages);
        return $ins;
    }

    /**
     * Converts instructions of the included page
     *
     * The funcion iterates over the given list of instructions and generates
     * an index of header and section indicies. It also removes document
     * start/end instructions, converts links, and removes unwanted
     * instructions like tags, comments, linkbacks.
     *
     * Later all header/section levels are convertet to match the current
     * inclusion level.
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _convert_instructions(&$ins, $lvl, $page, $sect, $flags, $root_id, $included_pages=[]) {
        global $conf;

        // filter instructions if needed
        if(!empty($sect)) {
            $this->_get_section($ins, $sect);   // section required
        }

        if($flags['firstsec']) {
            $this->_get_firstsec($ins, $page, $flags);  // only first section 
        }

        $ns  = getNS($page);
        $num = count($ins);

        $conv_idx = [];      // conversion index
        $lvl_max  = false;   // max level
        $first_header = -1;
        $no_header  = false;
        $sect_title = false;
        $endpos     = null;  // end position of the raw wiki text

        $this->adapt_links($ins, $page, $included_pages);

        for ($i = 0; $i < $num; $i++) {
            switch ($ins[$i][0]) {
                case 'document_start':
                case 'document_end':
                case 'section_edit':
                    unset($ins[$i]);
                    break;
                case 'header':
                    // get section title of first section
                    if ($sect && !$sect_title) {
                        $sect_title = $ins[$i][1][0];
                    }
                    // check if we need to skip the first header
                    if ((!$no_header) && $flags['noheader']) {
                        $no_header = true;
                    }

                    $conv_idx[] = $i;
                    // get index of first header
                    $first_header = ($first_header == -1) ? $i : -1;
                    // get max level of this instructions set
                    if (!$lvl_max || ($ins[$i][1][1] < $lvl_max)) {
                        $lvl_max = $ins[$i][1][1];
                    }
                    break;
                case 'section_open':
                    if ($flags['inline'])
                        unset($ins[$i]);
                    else
                        $conv_idx[] = $i;
                    break;
                case 'section_close':
                    if ($flags['inline'])
                        unset($ins[$i]);
                    break;
                case 'nest':
                    $this->adapt_links($ins[$i][1][0], $page, $included_pages);
                    break;
                case 'plugin':
                    // FIXME skip other plugins?
                    switch ($ins[$i][1][0]) {
                        case 'tag_tag':                 // skip tags
                        case 'discussion_comments':     // skip comments
                        case 'linkback':                // skip linkbacks
                        case 'data_entry':              // skip data plugin
                        case 'meta':                    // skip meta plugin
                        case 'indexmenu_tag':           // skip indexmenu sort tag
                        case 'include_sorttag':         // skip include plugin sort tag
                            unset($ins[$i]);
                            break;
                        // adapt indentation level of nested includes
                        case 'include_include':
                            if (!$flags['inline'] && $flags['indent'])
                                $ins[$i][1][1][4] += $lvl;
                            break;
                        /*
                         * if there is already a closelastsecedit instruction (was added by
                         * one of the section functions), store its position but delete it
                         * as it can't be determined yet if it is needed,
                         * i.e. if there is a header which generates a section edit (depends
                         * on the levels, level adjustments, $no_header, ...)
                         */
                        case 'include_closelastsecedit':
                            $endpos = $ins[$i][1][1][0];
                            unset($ins[$i]);
                            break;
                    }
                    break;
                default:
                    break;
            }
        }

        // calculate difference between header/section level and include level
        $diff = 0;
        if (!isset($lvl_max)) $lvl_max = 0; // if no level found in target, set to 0
        $diff = $lvl - $lvl_max + 1;
        if ($no_header) $diff -= 1;  // push up one level if "noheader"

        // convert headers and set footer/permalink
        $hdr_deleted      = false;
        $has_permalink    = false;
        $footer_lvl       = false;
        $contains_secedit = false;
        $section_close_at = false;
        foreach ($conv_idx as $idx) {
            if ($ins[$idx][0] == 'header') {
                if ($section_close_at === false
                    && isset($ins[$idx+1]) && $ins[$idx+1][0] == 'section_open'
                ){
                    // store the index of the first heading that is followed by a new section
                    // the wrap plugin creates sections without section_open so the section
                    // shouldn't be closed before them
                    $section_close_at = $idx;
                }

                if ($no_header && !$hdr_deleted) {
                    // ** ALTERNATIVE APPROACH in Heading PreProcessor (HPP) plugin **
                    // render the header as link anchor, instead delete it.
                    $ins[$idx][1][3]['title'] = ''; // hidden header <a id=hid></a>
                 // unset ($ins[$idx]);
                    $hdr_deleted = true;
                    continue;
                }

                if ($flags['indent']) {
                    $lvl_new = (($ins[$idx][1][1] + $diff) > 5) ? 5 : ($ins[$idx][1][1] + $diff);
                    $ins[$idx][1][1] = $lvl_new;
                }

                if ($ins[$idx][1][1] <= $conf['maxseclevel'])
                    $contains_secedit = true;

                // set permalink
                if ($flags['link'] && !$has_permalink && ($idx == $first_header)) {
                    $this->_permalink($ins[$idx], $page, $sect, $flags);
                    $has_permalink = true;
                }

                // set footer level
                if (!$footer_lvl && ($idx == $first_header) && !$no_header) {
                    if ($flags['indent'] && isset($lvl_new)) {
                        $footer_lvl = $lvl_new;
                    } else {
                        $footer_lvl = $lvl_max;
                    }
                }
            } else {
                // it's a section
                if ($flags['indent']) {
                    $lvl_new = (($ins[$idx][1][0] + $diff) > 5) ? 5 : ($ins[$idx][1][0] + $diff);
                    $ins[$idx][1][0] = $lvl_new;
                }

                // check if noheader is used and set the footer level to the first section
                if ($no_header && !$footer_lvl) {
                    if ($flags['indent'] && isset($lvl_new)) {
                        $footer_lvl = $lvl_new;
                    } else {
                        $footer_lvl = $lvl_max;
                    }
                } 
            }
        }

        // close last open section of the included page if there is any
        if ($contains_secedit) {
            $ins[] = $this->pluginInstruction('include_closelastsecedit', [$endpos]);
        }

        // add edit button
        if ($flags['editbtn']) {
          //$this->_editbtn($ins, $page, $sect, $sect_title, ($flags['redirect'] ? $root_id : false));
            $ins[] = $this->pluginInstruction('include_editbtn', [($sect ? $sect_title : $page)]);
        }

        // add footer
        if ($flags['footer']) {
          //$ins[] = $this->_footer($page, $sect, $sect_title, $flags, $footer_lvl, $root_id);
            $ins[] = $this->pluginInstruction(
                'include_footer', [$page, $sect, $sect_title, $flags, $root_id, $footer_lvl]
            );
        }

        // wrap content at the beginning of the include that is not in a section in a section
        if ($lvl > 0 && $section_close_at !== 0 && $flags['indent'] && !$flags['inline']) {
            if ($section_close_at === false) {
                $ins[] = $this->dwInstruction('section_close',[]);
                array_unshift($ins, $this->dwInstruction('section_open',[$lvl]));
            } else {
                $section_close_idx = array_search($section_close_at, array_keys($ins));
                if ($section_close_idx > 0) {
                    $before_ins = array_slice($ins, 0, $section_close_idx);
                    $after_ins = array_slice($ins, $section_close_idx);
                    $ins = array_merge(
                        $before_ins,
                        array($this->dwInstruction('section_close',[])),
                        $after_ins
                    );
                    array_unshift($ins, $this->dwInstruction('section_open',[$lvl]));
                }
            }
        }

        // add instructions entry wrapper
        $include_secid = isset($flags['include_secid']) ? $flags['include_secid'] : null;
        array_unshift($ins, $this->pluginInstruction(
            'include_wrap', ['open', $page, $flags['redirect'], $include_secid]
        ));
        array_push($ins, $this->pluginInstruction('include_wrap', ['close']));

        if (isset($flags['beforeeach'])) {
            array_unshift($ins, $this->dwInstruction('entity',[$flags['beforeeach']]));
        }
        if (isset($flags['aftereach'])) {
            array_push($ins, $this->dwInstruction('entity',[$flags['aftereach']]));
        }

        // close previous section if any and re-open after inclusion
        if ($lvl != 0 && $this->sec_close && !$flags['inline']) {
            array_unshift($ins, $this->dwInstruction('section_close',[]));
            $ins[] = $this->dwInstruction('section_open',[$lvl]);
        }
    }

    /**
     * Appends instruction item for the include plugin footer
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _footer($page, $sect, $sect_title, $flags, $footer_lvl, $root_id) {
        $footer = $this->pluginInstruction(
            'include_footer', [$page, $sect, $sect_title, $flags, $root_id, $footer_lvl]
        );
        return $footer;
    }

    /**
     * Appends instruction item for an edit button
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _editbtn(&$ins, $page, $sect, $sect_title, $root_id) {
        $title = ($sect) ? $sect_title : $page;
        $editbtn = $this->pluginInstruction('include_editbtn', [$title]);
        $ins[] = $editbtn;
    }

    /**
     * Convert instruction item for a permalink header
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _permalink(&$ins, $page, $sect, $flags) {
        $ins[0] = 'plugin';
        $ins[1] = array(
            'include_header',
            array($ins[1][0], $ins[1][1], $ins[1][2], $page, $sect, $flags)
        );
    }

    /**
     * Convert internal and local links depending on the included pages
     *
     * @param array  $ins            The instructions that shall be adapted
     * @param string $page           The included page
     * @param array  $included_pages The array of pages that are included
     */
    private function adapt_links(&$ins, $page, $included_pages = null) {
        $num = count($ins);
        $ns  = getNS($page);

        for ($i = 0; $i < $num; $i++) {
            // adjust links with image titles
            if (strpos($ins[$i][0], 'link') !== false
                && isset($ins[$i][1][1]) && is_array($ins[$i][1][1])
                && $ins[$i][1][1]['type'] == 'internalmedia'
            ) {
                // resolve relative ids, but without cleaning in order to preserve the name
                $media_id = resolve_id($ns, $ins[$i][1][1]['src']);
                // make sure that after resolving the link again it will be the same link
                if ($media_id{0} != ':') $media_id = ':'.$media_id;
                $ins[$i][1][1]['src'] = $media_id;
            }
            switch($ins[$i][0]) {
                case 'internallink':
                case 'internalmedia':
                    // make sure parameters aren't touched
                    $link_params = '';
                    $link_id = $ins[$i][1][0];
                    $link_parts = explode('?', $link_id, 2);
                    if (count($link_parts) === 2) {
                        $link_id = $link_parts[0];
                        $link_params = $link_parts[1];
                    }
                    // resolve the id without cleaning it
                    $link_id = resolve_id($ns, $link_id, false);
                    // this id is internal (i.e. absolute) now, add ':' to make resolve_id work again
                    if ($link_id{0} != ':') $link_id = ':'.$link_id;
                    // restore parameters
                    $ins[$i][1][0] = ($link_params != '') ? $link_id.'?'.$link_params : $link_id;

                    if ($ins[$i][0] == 'internallink' && !empty($included_pages)) {
                        // change links to included pages into local links
                        // only adapt links without parameters
                        $link_id = $ins[$i][1][0];
                        $link_parts = explode('?', $link_id, 2);
                        if (count($link_parts) === 1) {
                            $exists = false;
                            resolve_pageid($ns, $link_id, $exists);

                            $link_parts = explode('#', $link_id, 2);
                            $hash = '';
                            if (count($link_parts) === 2) {
                                list($link_id, $hash) = $link_parts;
                            }
                            if (array_key_exists($link_id, $included_pages)) {
                                if ($hash) {
                                    // hopefully the hash is also unique in the including page
                                    // (otherwise this might be the wrong link target)
                                    $ins[$i][0] = 'locallink';
                                    $ins[$i][1][0] = $hash;
                                } else {
                                    // the include section ids are different from normal section ids
                                    // (so they won't conflict) but this also means that
                                    // the normal locallink function can't be used
                                    $ins[$i][0] = 'plugin';
                                    $ins[$i][1] = array(
                                        'include_locallink',
                                        array($included_pages[$link_id]['hid'], $ins[$i][1][1], $ins[$i][1][0])
                                    );
                                }
                            }
                        }
                    }
                    break;
                case 'locallink':
                    /* Convert local links to internal links if the page hasn't been fully included */
                    if ($included_pages == null || !array_key_exists($page, $included_pages)) {
                        $ins[$i][0] = 'internallink';
                        $ins[$i][1][0] = ':'.$page.'#'.$ins[$i][1][0];
                    }
                    break;
            }
        }
    }

    /** 
     * Get a section including its subsections 
     *
     * @author Michael Klier <chi@chimeric.de>
     */ 
    function _get_section(&$ins, $sect) {
        $num = count($ins);
        $offset = null;
        $lvl    = null;
        $length = null;
        $endpos = null; // end position in the input text, needed for section edit buttons

        $check = []; // used for sectionID() in order to get the same ids as the xhtml renderer

        for ($i = 0; $i < $num; $i++) {
            if ($ins[$i][0] == 'header') {
                // find the right header
                if (!isset($offset, $lvl) && (sectionID($ins[$i][1][0], $check) == $sect)) {
                    $offset = $i;
                    $lvl    = $ins[$i][1][1];
                    continue;
                }
                // find end position for the section edit button, if the right header has found
                if (isset($offset, $lvl) && ($ins[$i][1][1] <= $lvl)) {
                    $length = $i - $offset;
                    $endpos = $ins[$i][1][2];
                    break;
                }
            }
        }
        $offset = isset($offset) ? $offset : 0;
        $length = isset($length) ? $length : ($num - 1);
        if(is_array($ins)) {
            $ins = array_slice($ins, $offset, $length);
            // store the end position in the include_closelastsecedit instruction
            // so it can generate a matching button
            $ins[] = $this->pluginInstruction('include_closelastsecedit', [$endpos]);
        }
    } 

    /**
     * Only display the first section of a page and a readmore link
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    function _get_firstsec(&$ins, $page, $flags) {
        $num = count($ins);
        $first_sect = false;
        $endpos = null; // end position in the input text
        for ($i = 0; $i < $num; $i++) {
            if ($ins[$i][0] == 'section_close') {
                $first_sect = $i;
            }
            if ($ins[$i][0] == 'header') {
                /*
                 * Store the position of the last header that is encountered.
                 * As section_close/open-instruction are always (unless some plugin
                 * modifies this) around a header instruction this means that the last
                 * position that is stored here is exactly the position of 
                 * the section_close/open at which the content is truncated.
                 */
                $endpos = $ins[$i][1][2];
            }
            // only truncate the content and add the read more link when there is really
            // more than that first section
            if (($first_sect) && ($ins[$i][0] == 'section_open')) {
                $ins = array_slice($ins, 0, $first_sect);
                if ($flags['readmore']) {
                    $ins[] = $this->pluginInstruction('include_readmore', [$page]);
                }
                $ins[] = $this->dwInstruction('section_close',[]);
                // store the end position in the include_closelastsecedit instruction
                // so it can generate a matching button
                $ins[] = $this->pluginInstruction('include_closelastsecedit', [$endpos]);
                return;
            }
        }
    }

    /**
     * Gives a list of pages for a given include statement
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    function _get_included_pages($mode, $page, $sect, $parent_id, $flags) {
        $pages = [];
        switch ($mode) {
            case 'namespace':
                $page = cleanID($page);
                $ns   = utf8_encodeFN(str_replace(':', '/', $page));
                // depth is absolute depth, not relative depth, but 0 has a special meaning.
                $depth = $flags['depth']
                    ? $flags['depth'] + substr_count($page, ':') + ($page ? 1 : 0)
                    : 0;
                $pages = $this->_get_pages_in_ns($ns, $depth);
                break;

            case 'tagtopic':
                $tagname = $page;
                $sect = '';
                $pages = $this->_get_tagged_pages($tagname);
                break;

            case 'page':
            case 'section':
            default:
                $page = $this->_apply_macro($page, $parent_id);
                // resolve shortcuts and clean ID
                resolve_pageid(getNS($parent_id), $page, $exists);
                if (auth_quickaclcheck($page) >= AUTH_READ) {
                    $pages = [$page];
                }
        } // end of switch

        if (count($pages) > 1) {
            $pages = $this->_sort_pages($pages, $flags['order'], $flags['rsort']);
        }

        $included_pages = [];
        foreach ($pages as $page) {
            $included_pages[] = [
                'id'        => $page,
                'exists'    => page_exists($page),
                'parent_id' => $parent_id,
            ];
        }
        return $included_pages;
    }

    /**
     * Get a list of pages found in specified namespace
     *
     * @param string $ns
     * @param int    $depth  $flags['depth'] (default 1)
     *                       maximum depth of includes, 0 for unlimited
     */
    private function _get_pages_in_ns($ns='/', $depth=1) {
        global $conf;
        $opts = ['depth' => $depth, 'skipacl' => false];
        search($pagearrays, $conf['datadir'], 'search_allpages', $opts, $ns);
        $pages = [];
        foreach ($pagearrays as $pagearray) {
            if (!isHiddenPage($pagearray['id'])) // skip hidden pages
                $pages[] = $pagearray['id'];
        }
        return $pages;
    }

    /**
     * Get a list of tagged pages with specified tag name
     */
    private function _get_tagged_pages($tagname='') {
        /** @var helper_plugin_tag $tagHelper */
        static $tagHelper;
        if (!isset($tagHelper)) {
            $tagHelper = $this->loadHelper('tag', true);
            if (!$tagHelper) {
                msg('You have to install the tag plugin to use this functionality!', -1);
                return $pages = [];
            }
        }
        $pages = [];
        $pagearrays = $tagHelper->getTopic('', null, $tagname);
        foreach ($pagearrays as $pagearray) {
            $pages[] = $pagearray['id'];
        }
        return $pages;
    }

    /**
     * Sort a list of pages
     *
     * @param string $sortOrder    $flags['order'] (default 'id')
     * @param bool   $sortReverse  $flags['rsort'] (default 0)
     */
    private function _sort_pages(array $pages, $sortOrder='id', $sortReverse=0) {
        if ($sortOrder == 'id') {
            if ($sortReverse) {
                usort($pages, array($this,'_r_strnatcasecmp'));
            } else {
                natcasesort($pages);
            }
            return $pages;
        }

        $ordered_pages = [];
        foreach ($pages as $page) {
            $key = '';
            switch ($sortOrder) {
                case 'title':
                  //$key = p_get_first_heading($page);
                    $render = METADATA_RENDER_USING_SIMPLE_CACHE;
                    $key = p_get_metadata($page,'title', $render);
                    break;
                case 'created':
                    $render = METADATA_DONT_RENDER;
                    $key = p_get_metadata($page,'date created', $render);
                    break;
                case 'modified':
                    $render = METADATA_DONT_RENDER;
                    $key = p_get_metadata($page,'date modified', $render);
                    break;
                case 'indexmenu':
                    $render = METADATA_RENDER_USING_SIMPLE_CACHE;
                    $key = p_get_metadata($page,'indexmenu_n', $render);
                    if ($key === null)
                        $key = '';
                    break;
                case 'custom':
                    $render = METADATA_RENDER_USING_SIMPLE_CACHE;
                    $key = p_get_metadata($page,'include_n', $render);
                    if ($key === null)
                        $key = '';
                    break;
            } // end of switch
            $key .= '_'.$page;
            $ordered_pages[$key] = $page;
        } // end of foreach

        if ($sortReverse) {
            uksort($ordered_pages, array($this,'_r_strnatcasecmp'));
        } else {
            uksort($ordered_pages, 'strnatcasecmp');
        }
        return $ordered_pages;
    }


    /**
     * String comparisons using a "natural order" algorithm in reverse order
     *
     * @link http://php.net/manual/en/function.strnatcmp.php
     * @param string $a First string
     * @param string $b Second string
     * @return int Similar to other string comparison functions, this one returns &lt; 0 if
     * str1 is greater than str2; &gt;
     * 0 if str1 is lesser than
     * str2, and 0 if they are equal.
     */
    function _r_strnatcasecmp($a, $b) {
        return strnatcasecmp($b, $a);
    }

    /**
     * This function generates the list of all included pages from a list of metadata
     * instructions.
     */
    function _get_included_pages_from_meta_instructions($instructions) {
        $pages = [];
        foreach ($instructions as $instruction) {
            $mode      = $instruction['mode'];
            $page      = $instruction['page'];
            $sect      = $instruction['sect'];
            $parent_id = $instruction['parent_id'];
            $flags     = $instruction['flags'];
            $pages = array_merge(
                $pages,
                $this->_get_included_pages($mode, $page, $sect, $parent_id, $flags)
            );
        }
        return $pages;
    }
    
    /**
     *  Get wiki language from "HTTP_ACCEPT_LANGUAGE"
     *  We allow the pattern e.g. "ja,en-US;q=0.7,en;q=0.3"
     */
    function _get_language_of_wiki($id, $parent_id) {
       global $conf;
       $result = $conf['lang'];
       if (strpos($id, '@BROWSER_LANG@') !== false) {
           $brlangp = "/([a-zA-Z]{1,8}(-[a-zA-Z]{1,8})*|\*)(;q=(0(.[0-9]{0,3})?|1(.0{0,3})?))?/";
           if (preg_match_all(
               $brlangp, $_SERVER["HTTP_ACCEPT_LANGUAGE"],
               $matches, PREG_SET_ORDER
           )){
               $langs = [];
               foreach ($matches as $match) {
                   $langname = $match[1] == '*' ? $conf['lang'] : $match[1];
                   $qvalue = $match[4] == '' ? 1.0 : $match[4];
                   $langs[$langname] = $qvalue;
               }
               arsort($langs);
               foreach ($langs as $lang => $langq) {
                   $testpage = str_replace('@BROWSER_LANG@', $lang, $id);
                   $testpage = $this->_apply_macro($testpage, $parent_id);
                   resolve_pageid(getNS($parent_id), $testpage, $exists);
                   if ($exists) {
                       $result = $lang;
                       break;
                   }
               }
           }
       }
       return cleanID($result);
    }
    
    /**
     * Makes user or date dependent includes possible
     */
    function _apply_macro($id, $parent_id) {
        global $INFO;
        global $auth;
        
        // if we don't have an auth object, do nothing
        if (!$auth) return $id;

        $user     = $_SERVER['REMOTE_USER'];
        $group    = $INFO['userinfo']['grps'][0];

        // set group for unregistered users
        if (!isset($group)) {
            $group = 'ALL';
        }

        $time_stamp = time();
        if (preg_match('/@DATE(\w+)@/', $id, $matches)) {
            switch ($matches[1]) {
            case 'PMONTH':
                $time_stamp = strtotime("-1 month");
                break;
            case 'NMONTH':
                $time_stamp = strtotime("+1 month");
                break;
            case 'NWEEK':
                $time_stamp = strtotime("+1 week");
                break;
            case 'PWEEK':
                $time_stamp = strtotime("-1 week");
                break;
            case 'TOMORROW':
                $time_stamp = strtotime("+1 day");
                break;
            case 'YESTERDAY':
                $time_stamp = strtotime("-1 day");
                break;
            case 'NYEAR':
                $time_stamp = strtotime("+1 year");
                break;
            case 'PYEAR':
                $time_stamp = strtotime("-1 year");
                break;
            }
            $id = preg_replace('/@DATE(\w+)@/','', $id);
        }

        $replace = array(
                '@USER@'  => cleanID($user),
                '@NAME@'  => cleanID($INFO['userinfo']['name']),
                '@GROUP@' => cleanID($group),
                '@BROWSER_LANG@'  => $this->_get_language_of_wiki($id, $parent_id),
                '@YEAR@'  => date('Y',$time_stamp),
                '@MONTH@' => date('m',$time_stamp),
                '@WEEK@'  => date('W',$time_stamp),
                '@DAY@'   => date('d',$time_stamp),
                '@YEARPMONTH@' => date('Ym',strtotime("-1 month")),
                '@PMONTH@' => date('m',strtotime("-1 month")),
                '@NMONTH@' => date('m',strtotime("+1 month")),
                '@YEARNMONTH@' => date('Ym',strtotime("+1 month")),
                '@YEARPWEEK@' => date('YW',strtotime("-1 week")),
                '@PWEEK@' => date('W',strtotime("-1 week")),
                '@NWEEK@' => date('W',strtotime("+1 week")),
                '@YEARNWEEK@' => date('YW',strtotime("+1 week")),
                );
        return str_replace(array_keys($replace), array_values($replace), $id);
    }

}
