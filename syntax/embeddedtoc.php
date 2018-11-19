<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * Embed TOC as page contents.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class syntax_plugin_headings_embeddedtoc extends DokuWiki_Syntax_Plugin {

    function getType() { return 'substition'; }
    function getPType(){ return 'block'; }
    function getSort() { return 30; }

    protected $tocStyle = array(  // toc visual design
        'TOC' => 'toc_dokuwiki',
        'INLINETOC' => 'toc_inline',
        'SIDETOC' => 'toc_shrinken',
    );

    /**
     * Connect pattern to lexer
     */
    protected $mode, $pattern;

    function preConnect() {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern
        $this->pattern[2] = '{{!(?:SIDE|INLINE)?TOC\b.*?}}';
    }

    function connectTo($mode) {
        if ($this->getConf('tocDisplay') != 'disabled') {
            $this->Lexer->addSpecialPattern($this->pattern[2], $mode, $this->mode);
        }
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        // load helper object
        isset($tocTweak) || $tocTweak = $this->loadHelper($this->getPluginName());

        // usage : {{!TOC 2-4 toc_hierarchical >id#section | title}}

        // parse syntax
        $type = 2;
        [$name, $param] = explode(' ', substr($match, 3, -2), 2);

        // resolve toc parameters such as toptoclevel, maxtoclevel
        $tocProps = $param ? $tocTweak->parse($param) : [];

        if ($name == 'SIDETOC') {
            // disable using cache
            $handler->_addCall('nocache', array(), $pos);
        }

        if (isset($tocProps['page']) && ($tocProps['page'][0] == '#')) {
            $tocProps['page'] = $ID.$tocProps['page'];
        }

        // check basic tocStyle
        $tocStyle = $this->tocStyle[$name];
        if (isset($tocProps['class'])) {
            if ( !preg_match('/\btoc_\w+\b/', $tocProps['class'])) {
                $tocProps['class'] = $tocStyle.' '.$tocProps['class'];
            }
        } else {
            $tocProps['class'] = $tocStyle;
        }

        return $data = [$ID, $tocProps];
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $conf, $lang;

        [$id, $tocProps] = $data;
        if (isset($tocProps['page'])) {
            [$page, $section] = explode('#', $tocProps['page']);
        }

        switch ($format) {
            case 'metadata':
                global $ID;
                if ($id !== $ID) return false; // ignore instructions for other page

                // store into matadata storage
                $metadata =& $renderer->meta['plugin'][$this->getPluginName()];

                if (isset($page) && $page != $ID) { // not current page
                    // set dependency info for PARSER_CACHE_USE event handler
                    $files = [ metaFN($page,'.meta') ];
                    $matadata['depends'] = isset($matadata['depends'])
                        ? array_merge($matadata['depends'], $files)
                        : $files;
                }
                return true;

            case 'xhtml':
                global $INFO, $ACT;

                // retrieve TableOfContents from metadata
                $page = $page ?? $INFO['id'];
                if ($page == $INFO['id']) {
                    $toc = $INFO['meta']['description']['tableofcontents'];
                } else {
                    $toc = p_get_metadata($page,'description tableofcontents');
                }
                if ($toc == null) $toc = [];

                // load helper object
                isset($tocTweak) || $tocTweak = $this->loadHelper($this->getPluginName());

                // filter toc items, with toc numbering
                $topLv = $tocProps['toptoclevel'];
                $maxLv = $tocProps['maxtoclevel'];
                $toc = $tocTweak->toc_numbering($toc);
                $toc = $tocTweak->toc_filter($toc, $topLv, $maxLv, $section);

                // modify toc items directly within loop by reference
                foreach ($toc as &$item) {
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
                        : html_buildlist($toc, 'toc', array($this, 'html_list_metatoc'));
                $html.= '</div>'.DOKU_LF;
                $html.= '</div>'.DOKU_LF;
                $html.= '<!-- EMBEDDED TOC END -->'.DOKU_LF;

                $renderer->doc .= $html;
                return true;
        }
    }

    /**
     * Callback for html_buildlist called from $this->render()
     * Builds html of each list item
     */
    function html_list_metatoc($item) {
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

}
