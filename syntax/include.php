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

if (!defined('DOKU_INC')) die();

class syntax_plugin_headings_include extends DokuWiki_Syntax_Plugin
{
    /** @var $helper helper_plugin_include */
    var $helper = null;

    public function getType() { return 'protected'; }
    public function getPType(){ return 'block'; }

    /**
     * Connect pattern to lexer, implement Doku_Parser_Mode_Interface
     */
    protected $mode, $pattern;

    // sort number used to determine priority of this mode
    public function getSort()
    {
        return 30;
    }

    public function preConnect()
    {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern
        $this->pattern[0] = '{{INCLUDE\b.+?}}';  // {{INCLUDE [flags] >[id]#[section]}}
        $this->pattern[1] = '{{page>.+?}}';      // {{page>[id]&[flags]}}
        $this->pattern[2] = '{{section>.+?}}';   // {{section>[id]#[section]&[flags]}}
        $this->pattern[3] = '{{namespace>.+?}}'; // {{namespace>[namespace]#[section]&[flags]}}
        $this->pattern[4] = '{{tagtopic>.+?}}';  // {{tagtopic>[tag]&[flags]}}
    }

    public function connectTo($mode)
    {
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
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $ID;

        if (substr($match, 2, 7) == 'INCLUDE') {
            // use case {{INCLUDE [flags] >[id]#[section]}}
            [$flags, $page] = array_map('trim', explode('>', substr($match, 9, -2), 2));
            [$page, $sect] = explode('#', $page, 2);
            $flags = explode(' ', $flags);
            $mode = $sect ? 'section' : 'page';

            $page = $page ? cleanID($page) : $ID;
            $check = false;
            // sectは タイトルではなく、hidを指定する
            // 指定されたhidをここで小文字に(sectionID or cleanID)しないでおく。
            $sect = isset($sect) ? $sect : null;

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
            // sectは タイトルを指定する（hid ではない）
            $sect = isset($sect) ? sectionID($sect, $check) : null;
            $extra = [$match,''];
        }

        static $includeHelper;
        isset($includeHelper) || $includeHelper = $this->loadHelper('include', true);
        $flags = $includeHelper->get_flags($flags);

        $level = null; // it will be set in PARSER_HANDLER_DONE event handler
        return $data = [$mode, $page, $sect, $flags, $level, $pos, $extra];
    }

    /**
     * Renders the included page(s)
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        global $ACT, $ID, $conf;

        // get data, of which $level has set in PARSER_HANDLER_DONE event handler
        [$mode, $page, $sect, $flags, $level, $pos, $extra] = $data;

        if ($format == 'xhtml' && $ACT == 'preview') {
            [$match, $note] = $extra;
            $renderer->doc .= '<code class="preview_note">'.$match.' '.$note.'</code>';
        }

        // static stack that records all ancestors of the child pages
        static $page_stack = [];

        // when there is no id just assume the global $ID is the current id
        if (empty($page_stack)) $page_stack[] = $ID;

        $parent_id = $page_stack[count($page_stack)-1];
        $root_id = $page_stack[0];


        // get included pages, of which each item has keys: id, exists, parent_id
        $pages = $this->_get_included_pages($mode, $page, $sect, $parent_id, $flags);
        unset($flags['order'], $flags['rsort']);

        // "linkonly" mode: page/section inclusion does not required
        if ($flags['linkonly']) {
            // link only to the included pages instead of including the content
            return $this->render_linkonly($renderer, $pages, $sect, $flags);
        } else {
            unset($flags['linkonly'], $flags['parlink']);
        }

        if ($format == 'metadata') {
            $metadata =& $renderer->meta['plugin'][$this->getPluginName()];

            /** @var Doku_Renderer_metadata $renderer */
            if (!isset($renderer->meta['plugin_include'])) {
                $renderer->meta['plugin_include'] = [];
            }
            $meta =& $renderer->meta['plugin_include'];
            $meta['instructions'][] = compact('mode', 'page', 'sect', 'parent_id', $flags);
            $meta['pages'] = array_merge( (array)$meta['pages'], $pages);
            $meta['include_content'] = isset($_REQUEST['include_content']);
        } else {
            // $format == 'xhtml'
            global $INFO;
        }

        foreach ($pages as $page) {
          //extract($page);
            $id     = $page['id'];
            $exists = $page['exists'];

            if (in_array($id, $page_stack)) continue;
            array_push($page_stack, $id);

            if ($format == 'metadata') {
                // add references for backlink
                $renderer->meta['relation']['references'][$id] = $exists;
                $renderer->meta['relation']['haspart'][$id]    = $exists;
            }

            // get instructions of the included page
            $instructions = $this->_get_instructions($id, $sect, $level, $flags, $root_id, $pos);

            // converts instructions of the included page
            $this->_convert_instructions(
                $instructions,
                $level, $id, $sect, $flags, $root_id, $pos
            );

            // store headers found in the instructions to complete tableofcontents
            // which is built later in PARSER_METADATA_RENDER event handler
            if ($format == 'metadata') {
                foreach ($instructions as $instruction) {
                    if ($instruction[0] == 'header') {
                        $metadata['include'][$pos][$id][] = $instruction[1];
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

    /**
     * Renders links to the included pages/sections instead of their contents
     * 
     * called when $flags['linkonly'] is on
     */
    protected function render_linkonly(Doku_Renderer $renderer, $pages, $sect=null, $flags)
    {
        if (!$flags['linkonly']) return false;

        foreach ($pages as $page) {
            $id     = $sect ? $page['id'].'#'.$sect : $page['id'];
            $exists = $page['exists'];

            if ($flags['pageexists'] && !$page['exists']) {
                continue;
            } else {
                if ($flags['title']) {
                    $render = METADATA_RENDER_USING_SIMPLE_CACHE;
                    $title = p_get_metadata($id,'title', $render);
                } else {
                    $title = '';
                }
                if ($flags['parlink']) {
                    $instructions = [
                        $this->dwInstruction('p_open',[]),
                        $this->dwInstruction('internallink',[':'.$id, $title]),
                        $this->dwInstruction('p_close',[]),
                    ];
                } else {
                    $instructions = [
                        $this->dwInstruction('internallink',[':'.$id, $title]),
                    ];
                }
            }
            $renderer->nest($instructions);
        }
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
    private function dwInstruction($method, array $params, $pos=null)
    {
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
    private function pluginInstruction($method, array $params, $pos=null)
    {
        return $this->dwInstruction('plugin',[$method, $params], $pos);
    }

    /**
     * Returns the converted instructions of a given page/section
     *
     * @author Michael Klier <chi@chimeric.de>
     * @author Michael Hamann <michael@content-space.de>
     */
    protected function _get_instructions($page, $sect, $lvl, $flags, $root_id=null, $pos)
    {
        $id = ($sect) ? $page.'#'.$sect : $page;
        $this->includes[$id] = true; // legacy code for keeping compatibility with other plugins

        // keep compatibility with other plugins that don't know the $root_id parameter
        if (is_null($root_id)) {
            global $ID;
            $root_id = $ID;
        }

        if (page_exists($page)) {
            global $ID;
            // Change the global $ID as otherwise plugins like the discussion plugin
            // will save data for the wrong page
            [$ID, $backupID] = [$page, $ID];
            $instructions = p_cached_instructions(wikiFN($page), false, $page);
            [$ID, $backupID] = [$backupID, null];

            // get instructions of the section
            $this->_get_section($instructions, $page, $sect, $flags, $lvl);
        } else {
            $instructions = [];
        }

        //$this->_convert_instructions($instructions, $lvl, $page, $sect, $flags, $root_id, $pos);
        return $instructions;
    }

    /**
     * Converts instructions of the included page
     *
     * The funcion iterates over the given list of instructions and generates
     * an index of header and section indicies. It also removes document
     * start/end instructions, converts links, and removes unwanted
     * instructions like tags, comments, linkbacks.
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    protected function _convert_instructions(&$instructions, $lvl, $page, $sect, $flags, $root_id, $pos)
    {
        $this->adapt_links($instructions, $page, $root_id, $pos);

        foreach ($instructions as $k => &$ins) {
            // get call name
            $call = ($ins[0] === 'plugin') ? 'plugin_'.$ins[1][0] : $ins[0];
            switch ($call) {
                case 'nest':
                    $this->adapt_links($ins[1][0], $page, $root_id, $pos);
                    break;
                case 'plugin_include_include':
                    // adapt indentation level of nested includes
                    if (!$flags['inline'] && $flags['indent']) {
                                $ins[1][1][4] += $lvl;
                    }
                    break;
                default:
                    break;
            } // end of switch $call
        } // end of foreach
        unset($ins);

        // re-indexes the instructions, beacuse some of them may have dropped/unset
        //$instructions = array_values($instructions);
    }

    /**
     * Convert internal and local links depending on the included pages
     *
     * @param array  $instructions   The instructions that shall be adapted
     * @param string $page           The included page
     * @param string $root_id        The including page
     * @param array  $pos            The byte position in including page
     */
    protected function adapt_links(&$instructions, $page, $root_id, $pos)
    {
        global $INFO;
        if (isset($INFO['id']) && $INFO['id'] == $root_id) {
            // Note: $INFO is not available in render metadata stage
            $toc = $INFO['meta']['plugin_include']['tableofcontents'][$pos] ?? [];
        } else {
            $toc = [];
        }

        /* 何を処理するか？
        1) 他ページの一部がインクルードされたことにより、
           元ページでは到達可能であった locallink [[#hid]] のリンク先が
           インクルードされていないケース
           → internallink に修正する
        2) 元ページで インクルード先ページへの internallink であった場合
           インクルード先では ページ内でのリンクで済むケース
           → locallink に修正する
        3) 複数ページ/セクションがインクルードされていることがある
           同一の include でインクルードされた部分への internallinkの場合
           インクルード先では ページ内でのリンクで済むケース
           → locallink に修正する
           ページへのリンクの場合、リンク先はプラグイン側で用意したもの
           local link hid= 'plugin_include__'.str_replace(':', '__', $id)
        */

        $ns  = getNS($page);

        foreach ($instructions as $k => &$ins) {
            // adjust links with image titles
            if (strpos($ins[0], 'link') !== false
                && isset($ins[1][1]['type'])
                && $ins[1][1]['type'] == 'internalmedia'
            ) {
                // resolve relative ids, but without cleaning in order to preserve the name
                $media_id = resolve_id($ns, $ins[1][1]['src'], false);
                // make sure that after resolving the link again it will be the same link
                $ins[1][1]['src'] = ':'.ltrim(':', $media_id);
            }
            switch ($ins[0]) {
                case 'internallink':
                case 'internalmedia':
                    // make sure parameters aren't touched
                    [$link_id, $link_params] = explode('?', $ins[1][0], 2);
                    // resolve the id without cleaning it
                    $link_id = resolve_id($ns, $link_id, false);
                    // this id is internal (i.e. absolute) now, add ':' to make resolve_id work again
                    $link_id = ':'.ltrim(':', $link_id);
                    // restore parameters
                    $ins[1][0] = ($link_params) ? $link_id.'?'.$link_params : $link_id;

                    if ($ins[0] == 'internallink' && !empty($toc)) {
                        // change links to included pages into local links
                        // only adapt links without parameters
                        [$link_id, $link_params] = explode('?', $ins[1][0], 2);
                        // get a full page id
                        resolve_pageid($ns, $link_id, $exists);
                        [$link_id, $hash ] = explode('#', $link_id, 2);
                        if (isset($toc[$link_id])) {
                            if ($hash) {
                                // hopefully the hash is also unique in the including page
                                // (otherwise this might be the wrong link target)
                                $ins[0] = 'locallink';
                                $ins[1][0] = $hash;
                            } else {
                                // link to instructions entry wrapper (html)id for the page
                                $hash = 'plugin_include__'.str_replace(':', '__', $link_id);
                                $ins[0] = 'locallink';
                                $ins[1][0] = $hash;
                            }
                        }
                    }
                    break;
                case 'locallink':
                    // convert local links to internal links if destination not found in toc
                    if (isset($toc[$page])) {
                        $included_headers = array_column($toc[$page],'hid');
                    } else {
                        $included_headers = [];
                    }
                    if (!in_array($ins[1][0], $included_headers)) {
                        $ins[0] = 'internallink';
                        $ins[1][0] = ':'.$page.'#'.$ins[1][0];
                    }
                    break;
            } // end of switch
        }
        unset($ins);
    }

    /**
     * Get instructions of the section (and its subsections)
     * or the first section of the page
     * Relevant flags: "firstsec", "readmore"
     *
     * @author Michael Klier <chi@chimeric.de>
     * @author Satoshi Sahara <sahara.satoshi@gmail.com>
     */ 
    protected function _get_section(&$instructions, $page, $sect, $flags, $level=null)
    {
        global $ID;
        static $hpp; // headings preprocessor object

        if (!is_array($instructions)) {
            // change the global $ID to $page as otherwise plugins like 
            // the discussion plugin will save data for the wrong page
            [$ID, $backupID] = [$page, $ID];
            $instructions = p_cached_instructions(wikiFN($page), false, $page);
            [$ID, $backupID] = [$backupID, null];
        }

        STEP1:
        // 不要callを削除しつつ、hid が $sect と一致する header から始まるセクションを残す
        // upper_level を検出する
        //   - sect指定のとき、 対応するheaderのレベルになる
        //   - sect指定なし（ページ全体）の場合 最上位レベルを探す必要がある
        // section_found  0: 見つかってない
        //               +1: 見つかった
        //               -1: sect指定なし 探す必要がない

        $section_found  = $sect ? 0 : -1;
        $section_level  = null; // upper level of the section
        $section_endpos = null; // end position in the input text, needed for section edit buttons
        $endpos         = null; // end position of closelastsecedit (used in STEP3)

        if ($section_found === 0) {
            isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());
            $check = []; // used for sectionID() in order to get the same ids as the xhtml renderer
        }

        foreach ($instructions as $k => &$ins) {
            // get call name
            $call = ($ins[0] === 'plugin') ? 'plugin_'.$ins[1][0] : $ins[0];
            switch ($call) {
                case 'header':
                    if ($section_found === 0) { // $sect は何か指定があることは自明
                        $hid = $hpp->sectionID($ins[1][3]['hid'], $check);
                        if ($sect === $hid) {
                            $section_found = $k;
                            $section_level = $ins[1][1];
                            continue 2;  // switch を脱出、次の foreach loop に進む
                        }
                    } elseif ($section_found && $flags['firstsec']) {
                        // $section_found -1: ページインクルード 最初に出現するheader位置
                        // $section_found  1: セクションインクルード 最初のサブセクションの開始位置
                        isset($firstsec_header) || $firstsec_header[$k] = $instructions[$k];  //未使用
                    }
                    if ($section_found > 0 && is_null($section_endpos)) {
                        if (!($ins[1][1] > $section_level)) { // not subsection
                            /*
                             * now the section ended, set end position of the section edit button
                             * As section_close/open-instructions are always found around header
                             * instruction (unless some plugin modifies this), this means that
                             * the last position that is stored here is exactly the position
                             * of the section_close/open at which the content is truncated.
                             */
                            $section_endpos = $ins[1][2];
                        }
                    }
                    break;
                case 'section_open':
                    if ($flags['inline']) unset($instructions[$k]);
                    break;
                case 'section_close':
                    if ($flags['inline']) unset($instructions[$k]);
                    break;
                case 'plugin_headings_include':
                    // 再帰的なインクルードを検出
                    $nested_include = true;
                    break;
                case 'plugin_include_closelastsecedit':
                    /*
                     * if there is already a closelastsecedit instruction (was added by
                     * one of the section functions), store its position but delete it
                     * as it can't be determined yet if it is needed,
                     * i.e. if there is a header which generates a section edit (depends
                     * on the levels, level adjustments, $no_header, ...)
                     */
                    $endpos = $ins[1][1][0];
                    unset($instructions[$k]);
                    break;
                case 'document_start':
                case 'document_end':
                case 'section_edit':
                // FIXME skip other plugins?
                case 'plugin_tag_tag':                 // skip tags
                case 'plugin_discussion_comments':     // skip comments
                case 'plugin_linkback':                // skip linkbacks
                case 'plugin_data_entry':              // skip data plugin
                case 'plugin_meta':                    // skip meta plugin
                case 'plugin_indexmenu_tag':           // skip indexmenu sort tag
                case 'plugin_include_sorttag':         // skip include plugin sort tag
                    unset($instructions[$k]);
                    continue 2;  // switch を脱出して、次の foreach loop に進む
            }
            if ($section_found === -1) {                                // page include mode
                continue;
            } elseif ($section_found === 0 || isset($section_endpos)) { // section include mode
                unset($instructions[$k]);
            }
        }
        unset($ins);

        // $sect と一致する header から始まるセクションが見つからなかった場合
        if ($section_found === 0 || empty($instructions)) {
            $instruction = array_merge(
                    $this->dwInstruction('hr',[]),
                    $this->dwInstruction('p_open',[]),
                    $this->dwInstruction('cdata',['⚠designated section not found...']),
                    $this->dwInstruction('p_close',[]),
                    $this->dwInstruction('hr',[]),
            );
        } elseif ($section_found === -1) {
            // $sect の指定がなかった場合、セクションレベルを取得
            foreach ($instructions as $k => $ins) {
                if ($ins[0] == 'header' && $ins[1][1]) {
                    $section_level = isset($section_level)
                            ? min($section_level, $ins[1][1])
                            : $ins[1][1];
                }
            }
            $section_level = $section_level ?? 1;
        }

        $instructions = array_values($instructions);

        // check the first call of the section
        if (isset($instructions[0]) && $instructions[0][0] !== 'header') {
            // 最初に出現する header 位置を探す
            $k1 = array_search('header', array_column($instructions, 0));
            if ($k1 !== false) {
                // 最初に出現する header の見出しレベルの空セクションを生成する→ ボツ
                // $lv = $instructions[$k1][1][1];
                // $section_level の空セクションを生成する
                $lv = $section_level;
                $instruction = array_merge(
                        $this->dwInstruction('header', ['', $lv, null]), // title,level,pos
                        $this->dwInstruction('section_open', [$lv]),
                        array_slice($instructions, 0, $k1),
                        $this->dwInstruction('section_close', []),
                        array_slice($instructions, $k1 -1),
                );
            } else {
                // header 見出しがないので、レベル1見出しの空セクションを生成する
                $lv = $section_level;
                $instruction = array_merge(
                        $this->dwInstruction('header', ['', $lv, null]), // title,level,pos
                        $this->dwInstruction('section_open', [$lv]),
                        $instructions,
                        $this->dwInstruction('section_close', []),
                );
            }
        }

        STEP2:
        // First Section flag
        if ($flags['firstsec'] && $section_found) {
            $k1 = array_search('section_close', array_column($instructions, 0));
            if ($k1 !== false) { // Fisrt section が見つかった
                // read more ?
                $more_calls = (bool)($k1 < array_key_last($instructions)); // REQUIRE PHP 7.3 or later
                if ($flags['readmore'] && $more_calls) {
                    $link = $sect ? $page.'#'.$sect : $page;
                    $lastcall = $instructions[$k1];
                    $instructions = array_merge(
                            array_slice($instructions, 0, $k1),
                            $this->pluginInstruction('include_readmore',[$link]),
                            $lastcall,
                    );
                }
            } else { // Fisrt section が存在しない
                // $instructions はそのまま、すなわち全体を返す
            }
        }

        STEP3:
        // Adjust header/section level of instructions
        // インクルードセクションのレベル調整
        // _get_section()メソッドの5番目の引数 $level を追加する
        // $level は Include構文の記述位置での見出しレベルである
        // flag indent header     : サブセクションとして挿入する $level +1
        //      noindent header   : 兄弟セクションとして挿入する $level
        //      indent noheader   : サブセクションとして挿入する hidden header
        //      noindent noheader : 兄弟セクションとして挿入する hidden header

        global $conf;

        $contains_secedit = false;
//      $endpos = null;

        if (!isset($level)) $level = 1;
        $diff = $level - $section_level + ($flags['indent'] ? 1 : 0);
        foreach ($instructions as $k => &$ins) {
            // get call name
            $call = ($ins[0] === 'plugin') ? 'plugin_'.$ins[1][0] : $ins[0];
            switch ($call) {
                case 'header':
                    $ins[1][1] = min(5, $ins[1][1] + $diff);

                    if ($ins[1][1] <= $conf['maxseclevel'])
                        $contains_secedit = true;

                    if ($k == 0 && $flags['noheader']) {
                        // ** ALTERNATIVE APPROACH in Heading PreProcessor (HPP) plugin **
                        // render the header as link anchor, instead delete it.
                        if (isset($ins[1][3])) {
                            $ins[1][3]['title'] = ''; // hidden header <a id=hid></a>
                        }
                        $ins[1][0] = '';
                    }
                    break;
                case 'section_open':
                    $ins[1][0] = min(5, $ins[1][0] + $diff);
                    break;
            }
        }
        unset($ins);

        STEP3A:
        $kmax = array_key_last($instructions) ?? -1;  // REQUIRE PHP 7.3 or later
        $k = 0;
        while ($k <= $kmax) {
            $ins = $instructions[$k];
            $call = ($ins[0] === 'plugin') ? 'plugin_'.$ins[1][0] : $ins[0];
            if ($call === 'plugin_headings_include') {
                // $data = [$mode, $page, $sect, $flags, $level, $pos, $extra];
                //       = $ins[1][1]
                $inserts = null;
                $this->_get_section($inserts, $ins[1][1][1], $ins[1][1][2], $ins[1][1][3], $ins[1][1][4]);
                // replace current include instruction with 
                array_splice($instructions, $k, 1, $inserts);
                unset($inserts);
                $k += -1; // 置換したので、再チェックするためにカウンタを減らす
            }
            $k++;
        }
        unset($ins);

        STEP4:
        // close last open section of the included page if there is any
        if ($contains_secedit) {
            $instructions[] = $this->pluginInstruction('include_closelastsecedit',[$endpos]);
        }

        // add edit button
        if ($flags['editbtn']) {
            $instructions[] = $this->pluginInstruction('include_editbtn',[($sect ? $sect_title : $page)]);
        }

        // add footer
        if ($flags['footer']) {
            $footer_lvl = $section_level;
            $sect_title = $instructions[0][1][3]['title'] ?? '?';
            $instructions[] = $this->pluginInstruction(
                'include_footer',[$page, $sect, $sect_title, $flags, null, $footer_lvl]
            );
        }

        STEP5:
        // Add include entry wrapper for included instructions
        $secid = 'plugin_include__'.str_replace(':', '__', $page);
        array_unshift($instructions, $this->pluginInstruction(
            'include_wrap',['open', $page, $flags['redirect'], $secid]
        ));
        array_push($instructions, $this->pluginInstruction(
            'include_wrap',['close']
        ));

        if (isset($flags['beforeeach'])) {
            array_unshift($instructions, $this->dwInstruction('entity',[$flags['beforeeach']]));
        }
        if (isset($flags['aftereach'])) {
            array_push($instructions, $this->dwInstruction('entity',[$flags['aftereach']]));
        }

        // close previous section if any and re-open after inclusion
        if ($level != 0 && $this->sec_close && !$flags['inline']) {
            array_unshift($instructions, $this->dwInstruction('section_close',[]));
            array_push($instructions, $this->dwInstruction('section_open',[$lvl]));
        }


        // re-indexes the instructions, beacuse some of them may have dropped/unset
        // $instructions = array_values($instructions);
        return;
    }

    /**
     * Gives a list of pages for a given include statement
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    protected function _get_included_pages($mode, $page, $sect, $parent_id, $flags)
    {
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
    protected function _get_pages_in_ns($ns='/', $depth=1)
    {
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
    protected function _get_tagged_pages($tagname='')
    {
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
    private function _sort_pages(array $pages, $sortOrder='id', $sortReverse=0)
    {
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
    protected function _r_strnatcasecmp($a, $b)
    {
        return strnatcasecmp($b, $a);
    }

    /**
     * This function generates the list of all included pages from a list of metadata
     * instructions.
     *
     * このメソッドは RENDER_CACHE_USE event handeler
     *   plugin/include/action.php cache_prepare()
     * からコールされる
     */
    function _get_included_pages_from_meta_instructions($instructions)
    {
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
    protected function _get_language_of_wiki($id, $parent_id)
    {
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
    protected function _apply_macro($id, $parent_id)
    {
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
