<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * Extended DokuWiki built-in TOC
 *  - set top and max level of headlines to be found in the table of contents
 *  - allow to set autoTOC state initially closed
 *  - render toc placeholder to show built-in toc box in the page
 * Embedded TOC
 *  - render TOC as page contents
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */
if (!defined('DOKU_INC')) die();

class syntax_plugin_headings_toc extends DokuWiki_Syntax_Plugin
{

    public function getType() { return 'substition'; }
    public function getPType(){ return 'block'; }
    public function getSort() { return 29; } // less than Doku_Parser_Mode_notoc = 30

    protected $tocStyle = [           // toc visual design options
        'TOC'       => 'toc_dokuwiki',
        'INLINETOC' => 'toc_inline',
        'SIDETOC'   => 'toc_shrinken',
    ];

    /**
     * Connect pattern to lexer
     */
    protected $mode, $pattern;

    public function preConnect()
    {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern
        $this->pattern[0] = '~~(?:NO|CLOSE)?TOC\b.*?~~';
        $this->pattern[1] = '{{(?:CLOSED_)?(?:INLINE)?TOC\b.*?}}';
        $this->pattern[2] = '{{!(?:SIDE|INLINE)?TOC\b.*?}}';
    }

    public function connectTo($mode)
    {
        always: {
            $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
        }
        if ($this->getConf('tocDisplay') != 'disabled') {
            $this->Lexer->addSpecialPattern($this->pattern[1], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[2], $mode, $this->mode);
        }
    }

    /**
     * Handle the match
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        global $ID;

        // parse syntax
        if ($match[0] == '~') {
            $type = 0;
            [$name, $param] = explode(' ', substr($match, 2, -2), 2);
        } else {
            $type = ($match[2] == '!') ? 2 : 1;
            [$name, $param] = explode(' ', substr($match, $type +1, -2), 2);
        }

        // resolve toc parameters such as toptoclevel, maxtoclevel, class, title
        $tocProps = $param ? $this->parse($param) : [];

        switch ($type) {
            case 0: // macro appricable both TOC and INLINETOC
                if ($name == 'NOTOC') {
                    $handler->_addCall('notoc', array(), $pos);
                    $tocProps['display'] = 'none';
                    $type = 1;
                } elseif ($name == 'CLOSETOC') {
                    $tocProps['state'] = -1;
                }
                break;

            case 1: // DokiWiki original TOC or alternative INLINETOC
                if (substr($name, 0, 6) == 'CLOSED') {
                    $tocProps['state'] = -1;
                    $tocProps['display'] = strtolower(substr($name, 7));
                } else {
                    $tocProps['display'] = strtolower($name);
                }
                break;

            case 2: // Embedded TOC variants
                // usage : {{!TOC 2-4 toc_hierarchical >id#section | title}}
                if ($name == 'SIDETOC') {
                    // disable using cache
                    $handler->_addCall('nocache', array(), $pos);
                }
                if (isset($tocProps['page']) && ($tocProps['page'][0] == '#')) {
                    $tocProps['page'] = $ID.$tocProps['page'];
                }
                // check basic tocStyle
                $tocStyle = $this->tocStyle[$name];
                if (!isset($tocProps['class'])) {
                    $tocProps['class'] = $tocStyle;
                } elseif (!preg_match('/\btoc_\w+\b/', $tocProps['class'])) {
                    $tocProps['class'] = $tocStyle.' '.$tocProps['class'];
                }
                break;
        } // end of switch ($type)

        return $data = [$type, $ID, $tocProps];
    }

    /**
     * Create output
     */
    public function render($format, Doku_Renderer $renderer, $data)
    {
        [$type, $id, $tocProps] = $data;

        if ($format == 'metadata') {
            return $this->render_metadata($renderer, $data);
        } else { // $format == 'xhtml'
            switch ($type) {
                case 0:
                case 1:
                    return $this->render_toc($renderer, $data);
                case 2:
                    return $this->render_embeddedtoc($renderer, $data);
            }
        }
        return false;
    }

    /**
     * Render metadata
     */
    protected function render_metadata(Doku_Renderer $renderer, $data)
    {
        global $ID;

        [$type, $id, $tocProps] = $data;
        if ($id !== $ID) return false; // ignore instructions for other page

        // store into matadata storage
        $metadata =& $renderer->meta['plugin'][$this->getPluginName()];

        switch ($type) {
            case 0:
            case 1:
                // add only new key-value pairs, keep already stored data
                if (!isset($metadata['toc']['display'])
                    && in_array($tocProps['display'], ['toc','inlinetoc'])
                ) {
                    $metadata['toc'] = $tocProps;
                }
                return true;
            case 2:
                if (isset($tocProps['page'])) {
                    [$page, $section] = explode('#', $tocProps['page']);
                }
                if (isset($page) && $page != $ID) { // not current page
                    // set dependency info for PARSER_CACHE_USE event handler
                    $files = [ metaFN($page,'.meta') ];
                    $matadata['depends'] = isset($matadata['depends'])
                        ? array_merge($matadata['depends'], $files)
                        : $files;
                }
                return true;
        }
        return false;
    }

    /**
     * Render xhtml placeholder for DokuWiki built-in TOC
     * the placeholder will be replaced through action TPL_CONTENT_DISPLAY event handler
     */
    protected function render_toc(Doku_Renderer $renderer, $data)
    {
        global $INFO, $ACT;
        static $counts; // count toc placeholders appeared in the page

        [$type, $id, $tocProps] = $data;

        // render PLACEHOLDER
        if (in_array($tocProps['display'], ['toc','inlinetoc'])) {
            if (!isset($counts[$id])) {
                $tocName = $tocProps['display'];
                $counts[$id] = 0;
            } else {
                $tocName = $tocProps['display'].(++$counts[$id]);
            }
        } else return false;

        if ($ACT == 'preview') {
            $state = $tocProps['state'] ? 'CLOSED_' : '';
            $range = $tocProps['toptoclevel'].'-'.$tocProps['maxtoclevel'];
            $note = '<!-- '.$state.strtoupper($tocName).'_HERE '.$range.' -->';
            $renderer->doc .= '<code class="preveiw_note">';
            $renderer->doc .= hsc($note);
            $renderer->doc .= '</code>'.DOKU_LF;
        }
        $renderer->doc .= '<!-- '.strtoupper($tocName).'_HERE -->'.DOKU_LF;
        return true;
    }

    /**
     * Render xhtml Embedded TOC
     */
    protected function render_embeddedtoc(Doku_Renderer $renderer, $data)
    {
        global $INFO, $ACT, $lang;

        [$type, $id, $tocProps] = $data;

        if (isset($tocProps['page'])) {
            [$page, $section] = explode('#', $tocProps['page']);
        }

        // retrieve TableOfContents from metadata
        $page = $page ?? $INFO['id'];
        if ($page == $INFO['id']) {
            $toc = $INFO['meta']['description']['tableofcontents'];
        } else {
            $toc = p_get_metadata($page,'description tableofcontents');
        }
        if ($toc == null) $toc = [];

        // load helper object
        isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());

        // filter toc items, with toc numbering
        $topLv = $tocProps['toptoclevel'];
        $maxLv = $tocProps['maxtoclevel'];
        $toc = $hpp->toc_filter($toc, $topLv, $maxLv, $section);

        // modify toc items directly within loop by reference
        foreach ($toc as $k => &$item) {
            if ($page == $INFO['id']) {
                // headings found in current page (internal link)
                $item['url']  = '#'.$item['hid'];
            } else {
                // headings found in other wiki page
                $item['page']  = $page;
                $item['url']   = wl($page).'#'.$item['hid'];
                $item['class'] = 'wikilink1';
            }
        } // end of foreach
        unset($item); // break the reference with the last item

        // toc wrapper attributes
        $attr['class'] = $tocProps['class'];
        $title = $tocProps['title'] ?? $lang['toc'];

        if ($ACT == 'preview') {
            $note = '<!-- Embedded TOC '.$tocProps['page'].' -->';
        } else $note = '';

        $html = '<!-- EMBEDDED TOC START -->'.DOKU_LF;
        $html.= '<div '.buildAttributes($attr).'>'.DOKU_LF;
        $html.= $note ? '<code class="preview_note">'.hsc($note).'</code>'.DOKU_LF : '';
        $html.= $title ? '<h3>'.hsc($title).'</h3>'.DOKU_LF : '';
        $html.= '<div>'.DOKU_LF;
        $html.= empty($toc)
                ? 'nothing to show'
                : html_buildlist($toc, 'toc', array($this,'html_list_metatoc'));
        $html.= '</div>'.DOKU_LF;
        $html.= '</div>'.DOKU_LF;
        $html.= '<!-- EMBEDDED TOC END -->'.DOKU_LF;

        $renderer->doc .= $html;
        return true;
    }

    /**
     * Callback for html_buildlist called from $this->render_embeddedtoc()
     * Builds html of each list item
     */
    public function html_list_metatoc($item)
    {
        $html = '<span class="li">';
        if (isset($item['page'])) {
            $html.= '<a title="'.$item['page'].'#'.$item['hid'].'"';
            $html.= ' href="'.$item['url'].'" class="'.$item['class'].'">';
        } else {
            $html.= '<a href="#'.$item['hid'].'">';
        }
        $html.= hsc($item['title']).'</a>';
        $html.= '</span>';
        return $html;
    }

    /* ----------------------------------------------------------------------- */

    /**
     * toc syntax parser
     *
     * @param string $param
     * @return array ($tocProps)
     */
    protected function parse($param)
    {
        global $conf;

        // Ex: {{!TOC 2-4 width18 toc_hierarchical >id#section | title}}
        $tocProps = [
            'toptoclevel' => $conf['toptoclevel'], // TOC upper level
            'maxtoclevel' => $conf['maxtoclevel'], // TOC lower level
            'class'       => null,     // TOC box CSS selector (space delimited)
            'title'       => null,     // TOC box title
            'page'        => null,     // page id & start hid for TOC (id#start_hid)
        ];

        // get tocTitle
        if (strpos($param, '|') !== false) {
            [$param, $tocTitle] = explode('|', $param, 2);
            // empty tocTitle will remove h3 'Table of Contents' headline
            $tocProps['title'] = trim($tocTitle); 
        }

        // get id#section
        if (strpos($param, '>') !== false) {
            [$param, $page] = explode('>', $param, 2);
            [$id, $section] = array_map('trim', explode('#', $page, 2));
            $tocProps['page'] = cleanID($id).($section ? '#'.$section : '');
        }

        // get other parameters
        $params = explode(' ', $param);
        foreach ($params as $token) {
            if (empty($token)) continue;

            // get TOC generation parameters, like "toptoclevel"-"maxtoclevel"
            if (preg_match('/^(?:(\d+)-(\d+)|^(\-?\d+))$/', $token, $matches)) {
                if (count($matches) == 4) {
                    if (strpos($matches[3], '-') !== false) {
                        $maxLv = abs($matches[3]);
                    } else {
                        $topLv = $matches[3];
                    }
                } else {
                        $topLv = $matches[1];
                        $maxLv = $matches[2];
                }

                if (isset($topLv)) {
                    $topLv = ($topLv < 1) ? 1 : $topLv;
                    $topLv = ($topLv > 5) ? 5 : $topLv;
                    $tocProps['toptoclevel'] = $topLv;
                }

                if (isset($maxLv)) {
                    $maxLv = ($maxLv > 5) ? 5 : $maxLv;
                    $tocProps['maxtoclevel'] = $maxLv;
                }
                continue;
            }

            // get class name for TOC box, ensure excluded any malcious character
            if (!preg_match('/[^ A-Za-z0-9_-]/', $token)) {
                $classes[] = $token;
            }
        } // end of foreach

        if (!empty($classes)) {
            $tocProps['class'] = implode(' ', $classes);
        }

        // remove null values
        $tocProps = array_filter( $tocProps,
                        function($v) { return !is_null($v); }
        );
        return $tocProps;
    }

}
