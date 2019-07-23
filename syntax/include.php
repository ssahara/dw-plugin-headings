<?php 
/** 
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * Include Plugin: displays a wiki page within another wiki page
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
    protected $mode;

    public function __construct() {
        $this->mode = substr(get_class($this), 7);  // drop 'syntax_' from class name
    }

    public function getType() { return 'protected'; }
    public function getPType(){ return 'block'; }

    /**
     * Connect pattern to lexer, implement Doku_Parser_Mode_Interface
     */
    protected $pattern;

    public function preConnect()
    {
        // syntax pattern
        $this->pattern[0] = '{{INCLUDE\b.+?}}';  // {{INCLUDE [flags] >[id]#[section]}}
        $this->pattern[1] = '{{page>.+?}}';      // {{page>[id]&[flags]}}
        $this->pattern[2] = '{{section>.+?}}';   // {{section>[id]#[section]&[flags]}}
        $this->pattern[3] = '{{namespace>.+?}}'; // {{namespace>[namespace]#[section]&[flags]}}
        $this->pattern[4] = '{{tagtopic>.+?}}';  // {{tagtopic>[tag]&[flags]}}
    }

    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
        if (plugin_isdisabled('include')) {
            $this->Lexer->addSpecialPattern($this->pattern[1], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[2], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[3], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[4], $mode, $this->mode);
        }
    }

    // sort number used to determine priority of this mode
    public function getSort()
    {
        return 30;
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

        $flags = $this->get_flags($flags);

        // "linkonly" mode: page/section inclusion does not required
        if ($flags['linkonly']) {
            $flags = array_filter($flags, function($k) {
                return in_array($k, ['linkonly','pageexists','parlink','depth','order','rsort']);
            }, ARRAY_FILTER_USE_KEY);
            return $data = ['linkonly', [$page, $sect, $flags, $mode]]; // indataの最初の3つの順を通常と揃える
        }

        $level = null; // it will be set in PARSER_HANDLER_DONE event handler
        return $data = [$mode, [$page, $sect, $flags, $level, $pos, $extra]];
    }

    /**
     * Renders the included page(s)
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        global $ACT, $ID, $conf;

        [$mode, $indata] = $data;
        // call auxiliary render method where applicable
        switch ($mode) {
            case 'readmore':
                return $this->readmore($format, $renderer, $indata);
            case 'editbtn':
                return $this->editbtn($format, $renderer, $indata);
            case 'wrap':
                return $this->wrap($format, $renderer, $indata);
            case 'closelastsecedit':
                return $this->closelastsecedit($format, $renderer, $indata);
            case 'footer':
                return $this->footer($format, $renderer, $indata);
            case 'linkonly':
                return $this->linkonly($format, $renderer, $indata);
        }


        // get data, of which $level has set in PARSER_HANDLER_DONE event handler
        [$mode, [$page, $sect, $flags, $level, $pos, $extra]] = $data;

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

        // store dependency in the metadata
        if ($format == 'metadata') {
            $metadata =& $renderer->meta['plugin'][$this->getPluginName()];

            $metadata['instructions'][] = compact('mode', 'page', 'sect', 'parent_id', $flags);
            $metadata['include_pages'] = array_merge( (array)$metadata['include_pages'], $pages);
            $metadata['include_content'] = isset($_REQUEST['include_content']);
        } else {
            // $format == 'xhtml'
            global $INFO;
            $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
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
                foreach ($instructions as $ins) {
                    if ($ins[0] == 'header') {
                        $metadata['include'][$pos][$id][] = $ins[1];
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
    private function dwInstruction($method, array $params, $pos=null)
    {
        $ins = [];
        $ins[0] = $method;
        $ins[1] = (array)$params;
        if (isset($pos)) {
            $ins[2] = $pos;
        }
        return $ins;
    }

    /**
     * Build an instruction array for syntax plugin components
     *
     * @author Satoshi Sahara <sahara.satoshi@gmail.com>
     */
    private function pluginInstruction($method, array $params, $pos=null)
    {
        if (!$method) {
            $method = substr(get_class($this), 14); // 'heading_include'
        }
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
            $toc = $INFO['meta']['plugin']['headings']['include'][$pos] ?? [];
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
            if ($flags['linkonly']) {
                $id = $sect ? $page.'#'.$sect : $page;
                $title = p_get_metadata($id,'title', METADATA_RENDER_USING_SIMPLE_CACHE);
                $instructions = [
                    $this->dwInstruction('p_open',[]),
                    $this->dwInstruction('internallink',[':'.$id, $title]),
                    $this->dwInstruction('p_open',[]),
                ];
                return $instructions;
             // goto STEP3;
            } else {
                // change the global $ID to $page as otherwise plugins like 
                // the discussion plugin will save data for the wrong page
                [$ID, $backupID] = [$page, $ID];
                $instructions = p_cached_instructions(wikiFN($page), false, $page);
                [$ID, $backupID] = [$backupID, null];
            }
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
                            $this->pluginInstruction('', ['readmore', [$link]]),
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
            if ($call === 'plugin_headings_include' && in_array($ins[1][1][0],['page','section'])) {
                $inserts = null;
                $data =& $ins[1][1];  // [$mode, [$page, $sect, $flags, $level, $pos, $extra]]
                $this->_get_section($inserts, $data[1][0], $data[1][1], $data[1][2], $data[1][3]);
                // replace current include instruction with $insert
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
            $instructions[] = $this->pluginInstruction('', ['closelastsecedit', [$endpos]]);
        }

        // add edit button
        if ($flags['editbtn']) {
            $btn_title = $sect ? $sect_title : $page;
            $instructions[] = $this->pluginInstruction('', ['editbtn', [$btn_title]]);
        }

        // add footer
        if ($flags['footer']) {
            $footer_lvl = $section_level;
            $sect_title = $instructions[0][1][3]['title'] ?? '?';
            $indata = [$page, $sect, $sect_title, $flags, null, $footer_lvl];
            $instructions[] = $this->pluginInstruction('', ['footer', $indata]);
        }

        STEP5:
        // Add include entry wrapper for included instructions
        $secid = 'plugin_include__'.str_replace(':', '__', $page);
/*
        // Includeプラグインに依存する場合
        array_unshift($instructions, $this->pluginInstruction(
            'include_wrap',['open', $page, $flags['redirect'], $secid]
        ));
        array_push($instructions, $this->pluginInstruction(
            'include_wrap',['close']
        ));
*/
        $indata = ['open', $page, $flags['redirect'], $secid];
        array_unshift($instructions, $this->pluginInstruction('', ['wrap', $indata]));
        $indata = ['close'];
        array_push($instructions, $this->pluginInstruction('', ['wrap', $indata]));

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
    public function _get_included_pages($mode, $page, $sect, $parent_id, $flags)
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


    /* --------------------------------------------------------------------- *
     * Combined auxiliary render methods
     * Render various parts of the included page/section
     * --------------------------------------------------------------------- */

    /**
     * Render a readmore link
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    private function readmore($format, $renderer, $data)
    {
        list($page) = $data;
        if ($format == 'xhtml') {
            $renderer->doc .= '<p class="include_readmore">';
            $renderer->internallink($page, $this->getLang('readmore'));
            $renderer->doc .= '</p>'.DOKU_LF;
        } else {
            $renderer->p_open();
            $renderer->internallink($page, $this->getLang('readmore'));
            $renderer->p_close();
        }
        return true;
    }

    /**
     * Render an include edit button
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    private function editbtn($format, $renderer, $data)
    {
        list($title) = $data;
        if ($format == 'xhtml') {
            $target = $this->mode.'_editbtn';
            if (defined('SEC_EDIT_PATTERN')) { // for DokuWiki Greebo and more recent versions
                $renderer->startSectionEdit(0, ['target' => $target, 'name' => $title]);
            } else {
                $renderer->startSectionEdit(0, $target, $title);
            }
            $renderer->finishSectionEdit();
            return true;
        }
        return false;
    }

    /**
     * Wrap the included page in a div and writes section edits for the action component
     * so it can detect where an included page starts/ends.
     *
     * @author Michael Klier <chi@chimeric.de>
     * @author Michael Hamann <michael@content-space.de>
     */
    private function wrap($format, $renderer, $data)
    {
        if ($format == 'xhtml') {
            list($state, $page, $redirect, $secid) = $data;

            switch ($state) {
                case 'open':
                    $target = $this->mode.'_start'.($redirect ? '' : '_noredirect');
                    if (defined('SEC_EDIT_PATTERN')) { // for DokuWiki Greebo and more recent versions
                        $renderer->startSectionEdit(0, ['target' => $target, 'name' => $page]);
                    } else {
                        $renderer->startSectionEdit(0, $target, $page);
                    }
                    $renderer->finishSectionEdit();

                    // Start a new section with type != section so headers in the included page
                    // won't print section edit buttons of the parent page
                    $target = $this->mode.'_end';
                    if (defined('SEC_EDIT_PATTERN')) { // for DokuWiki Greebo and more recent versions
                        $renderer->startSectionEdit(0, ['target' => $target, 'name' => $page]);
                    } else {
                        $renderer->startSectionEdit(0, $target, $page);
                    }

                    $class = $this->mode.'_content'.' plugin_include__'.$page;
                    $id = ($secid === null) ? '' : ' id="'.$secid.'"';
                    $renderer->doc .= '<div class="'.$class.'"'.$id.'>'.DOKU_LF;
                    if (is_a($renderer,'renderer_plugin_dw2pdf')) {
                        $renderer->doc .= '<a name="'.$secid.'" />';
                    }
                    break;
                case 'close':
                    $renderer->finishSectionEdit();
                    $renderer->doc .= '</div>'.DOKU_LF;
                    break;
            }
            return true;
        }
        return false;
    }

    /**
     * Finishes the last open section edit
     *
     * @author Michael Hamann <michael@content-space.de>
     */
    private function closelastsecedit($format, $renderer, $data)
    {
        list($endpos) = $data;
        if ($format == 'xhtml') {
            $renderer->finishSectionEdit($endpos);
            return true;
        }
        return false;
    }

    /**
     * Render the meta line below the included page/section
     *
     * @author Michael Klier <chi@chimeric.de>
     */
    private function footer($format, $renderer, $data)
    {
        list($page, $sect, $sect_title, $flags, $redirect_id, $footer_lvl) = $data;
        if ($format == 'xhtml' && $flags['footer']) {
            $renderer->doc .= '<div class="'.$this->mode.'_footer level'.$footer_lvl.'">';
            $renderer->doc .= $this->html_footer($page, $sect, $sect_title, $flags, $renderer);
            $renderer->doc .= '</div>';
            return true;
        }
        return false;
    }

    /**
     * Returns the meta line below the included page
     * @param $renderer Doku_Renderer_xhtml The (xhtml) renderer
     * @return string The HTML code of the footer
     */
    private function html_footer($page, $sect, $sect_title, $flags, $renderer)
    {
        global $conf, $ID;

        if (!$flags['footer']) return '';

        $meta  = p_get_metadata($page);
        $exists = page_exists($page);
        $xhtml = array();
        // permalink
        if ($flags['permalink']) {
            $class = $exists ? 'wikilink1' : 'wikilink2';
            $url   = $sect ? wl($page) . '#' . $sect : wl($page);
            $name  = $sect ? $sect_title : $page;
            $title = $sect ? $page . '#' . $sect : $page;
            if (!$title) $title = str_replace('_', ' ', noNS($page));
            $link = array(
                    'url'    => $url,
                    'title'  => $title,
                    'name'   => $name,
                    'target' => $conf['target']['wiki'],
                    'class'  => $class . ' permalink',
                    'more'   => 'rel="bookmark"',
                    );
            $xhtml[] = $renderer->_formatLink($link);
        }
        // date
        if ($flags['date'] && $exists) {
            $date = $meta['date']['created'];
            if ($date) {
                $xhtml[] = '<abbr class="published" title="'.strftime('%Y-%m-%dT%H:%M:%SZ', $date).'">'
                       . strftime($conf['dformat'], $date)
                       . '</abbr>';
            }
        }
        
        // modified date
        if ($flags['mdate'] && $exists) {
            $mdate = $meta['date']['modified'];
            if ($mdate) {
                $xhtml[] = '<abbr class="published" title="'.strftime('%Y-%m-%dT%H:%M:%SZ', $mdate).'">'
                       . strftime($conf['dformat'], $mdate)
                       . '</abbr>';
            }
        }
        // author
        if ($flags['user'] && $exists) {
            $author   = $meta['user'];
            if ($author) {
                if (function_exists('userlink')) {
                    $xhtml[] = '<span class="vcard author">'. userlink($author) .'</span>';
                } else { // DokuWiki versions < 2014-05-05 doesn't have userlink support, fall back to not providing a link
                    $xhtml[] = '<span class="vcard author">'. editorinfo($author) .'</span>';
                }
            }
        }
        // comments - let Discussion Plugin do the work for us
        if (empty($sect) && $flags['comments']
          && (!plugin_isdisabled('discussion'))
          && ($discussion =& plugin_load('helper', 'discussion'))
        ) {
            $disc = $discussion->td($page);
            if ($disc) $xhtml[] = '<span class="comment">'.$disc.'</span>';
        }
        // linkbacks - let Linkback Plugin do the work for us
        if (empty($sect) && $flags['linkbacks']
          && (!plugin_isdisabled('linkback'))
          && ($linkback =& plugin_load('helper', 'linkback'))
        ) {
            $link = $linkback->td($page);
            if ($link) $xhtml[] = '<span class="linkback">'.$link.'</span>';
        }

        $xhtml = implode(DOKU_LF . DOKU_TAB . '&middot; ', $xhtml);

        // tags - let Tag Plugin do the work for us
        if (empty($sect) && $flags['tags']
          && (!plugin_isdisabled('tag'))
          && ($tag =& plugin_load('helper', 'tag'))
        ) {
            $tags = $tag->td($page);
            if ($tags) {
                $xhtml .= '<div class="tags"><span>'.$tags.'</span></div>'.DOKU_LF;
            }
        }

        if (!$xhtml) $xhtml = '&nbsp;';
        return $xhtml;
    }

    /**
     * Renders links to the included pages/sections instead of their contents
     *  called when $flags['linkonly'] is on
     */
    private function linkonly($format, $renderer, $data)
    {
        global $ID;

        [$page, $sect, $flags, $mode] = $data;
        $parent_id = $ID;
        $pages = $this->_get_included_pages($mode, $page, $sect, $parent_id, $flags);

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
                        $this->dwInstruction('internallink', [':'.$id, $title]),
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


    /**
     * Override default settings
     */
    private function get_flags(array $params)
    {
        // load defaults
        if (!plugin_isdisabled('include')) {
            /** @var $includeHelper helper_plugin_include */
            static $includeHelper;
            isset($includeHelper) || $includeHelper = $this->loadHelper('include', true);
            $flags = $includeHelper->get_flags($params);
            return $flags;
        }

        $defaults = array(
            'noheader'    => 0,     // Don't display the header of the inserted section
            'firstsec'    => 0,     // limit entries on main blog page to first section
            'readmore'    => 1,     // Show readmore link in case of firstsection only

            'footer'      => 1,     // display meta line below blog entries
            'permalink'   => 0,     // show permalink below blog entries
            'date'        => 1,     // show date below blog entries
            'mdate'       => 0,     // show modification date below blog entries
            'user'        => 1,     // show username below blog entries
            'comments'    => 1,     // show number of comments below blog entries
            'linkbacks'   => 1,     // show number of linkbacks below blog entries

            'indent'      => 1,     // indent included pages relative to the page they get included
            'redirect'    => 1,     // redirect back to original page after an edit
            'editbtn'     => 1,     // show the edit button

            'linkonly'    => 0,     // link only to the included pages instead of including the content
            'parlink'     => 1,     // paragraph around link

            'tags'        => 1,     // show tags below blog entries
            'link'        => 0,     // link headlines of blog entries
            'taglogos'    => 0,     // display image for first tag

            'title'       => 0,     // use first header of page in link
            'pageexists'  => 0,     // no link if page does not exist
            'safeindex'   => 1,     // prevent indexing of protected metadata

            'order'       => 'id',  // order in which the pages are included in the case of multiple pages
            'rsort'       => 0,     // reverse sort order
            'depth'       => 1,     // maximum depth of namespace includes, 0 for unlimited depth
            'inline'      => 0,
        );

        $flags = $defaults;

        foreach ($params as $flag) {
            $value = '';
            if (strpos($flag, '=') !== false) {
                list($flag, $value) = explode('=', $flag, 2);
            }

            switch ($flag) {
                case 'header':
                    $flags['noheader'] = 0;   break;
                case 'noheader':
                    $flags['noheader'] = 1;   break;
                case 'firstseconly':
                case 'firstsectiononly':
                    $flags['firstsec'] = 1;   break;
                case 'fullpage':
                    $flags['firstsec'] = 0;   break;
                case 'footer':
                    $flags['footer'] = 1;     break;
                case 'nofooter':
                    $flags['footer'] = 0;     break;

                case 'link':
                    $flags['link'] = 1;       break;
                case 'nolink':
                    $flags['link'] = 0;       break;
                case 'permalink':
                    $flags['permalink'] = 1;  break;
                case 'nopermalink':
                    $flags['permalink'] = 0;  break;
                case 'date':
                    $flags['date'] = 1;       break;
                case 'nodate':
                    $flags['date'] = 0;       break;
                case 'mdate':
                    $flags['mdate'] = 1;      break;
                case 'nomdate':
                    $flags['mdate'] = 0;      break;
                case 'user':
                    $flags['user'] = 1;       break;
                case 'nouser':
                    $flags['user'] = 0;       break;
                case 'comments':
                    $flags['comments'] = 1;   break;
                case 'nocomments':
                    $flags['comments'] = 0;   break;
                case 'linkbacks':
                    $flags['linkbacks'] = 1;  break;
                case 'nolinkbacks':
                    $flags['linkbacks'] = 0;  break;
                case 'tags':
                    $flags['tags'] = 1;       break;
                case 'notags':
                    $flags['tags'] = 0;       break;
                case 'editbtn':
                case 'editbutton':
                    $flags['editbtn'] = 1;    break;
                case 'noeditbtn':
                case 'noeditbutton':
                    $flags['editbtn'] = 0;    break;
                case 'redirect':
                    $flags['redirect'] = 1;   break;
                case 'noredirect':
                    $flags['redirect'] = 0;   break;
                case 'indent':
                    $flags['indent'] = 1;     break;
                case 'noindent':
                    $flags['indent'] = 0;     break;
                case 'linkonly':
                    $flags['linkonly'] = 1;   break;
                case 'nolinkonly':
                case 'include_content':
                    $flags['linkonly'] = 0;   break;

                case 'title':
                    $flags['title'] = 1;      break;
                case 'notitle':
                    $flags['title'] = 0;      break;
                case 'pageexists':
                    $flags['pageexists'] = 1; break;
                case 'nopageexists':
                    $flags['pageexists'] = 0; break;
                case 'existlink':
                    $flags['pageexists'] = 1; $flags['linkonly'] = 1; break;
                case 'parlink':
                    $flags['parlink'] = 1;    break;
                case 'noparlink':
                    $flags['parlink'] = 0;    break;
                case 'order':
                    $flags['order'] = $value; break;
                case 'sort':
                    $flags['rsort'] = 0;      break;
                case 'rsort':
                    $flags['rsort'] = 1;      break;
                case 'depth':
                    $flags['depth'] = max(intval($value), 0); break;
                case 'readmore':
                    $flags['readmore'] = 1;   break;
                case 'noreadmore':
                    $flags['readmore'] = 0;   break;
                case 'inline':
                    $flags['inline'] = 1;     break;
                case 'beforeeach':
                    $flags['beforeeach'] = $value; break;
                case 'aftereach':
                    $flags['aftereach']  = $value; break;

            }
        }
        // the include_content URL parameter overrides flags
        if (isset($_REQUEST['include_content']))
            $flags['linkonly'] = 0;
        return $flags;
    }

}
