<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class syntax_plugin_headings_embeddedtoc extends DokuWiki_Syntax_Plugin {

    function getType() { return 'substition'; }
    function getPType(){ return 'block'; }
    function getSort() { return 30; }

    protected $tocStyle = array(  // default toc visual design
        'TOC' => 'toc_dokuwiki',
        'INLINETOC' => 'toc_inline',
    );

    /**
     * Connect pattern to lexer
     */
    protected $mode, $pattern;

    function preConnect() {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern
        $this->pattern[5] = '{{!(?:INLINETOC|TOC)\b.*?}}';
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern($this->pattern[5], $mode, $this->mode);
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
        [$name, $param] = explode(' ', substr($match, 3, -2), 2);

        // resolve toc parameters such as toptoclevel, maxtoclevel
        $tocProps = $tocTweak->parse($param);

        if (!isset($tocProps['page'])) {
            $tocProps['page'] = $ID;
        } elseif ($tocProps['page'][0] == '#') {
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

        return $data = $tocProps;
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $ID, $conf, $lang;

        $tocProps = $data;
        [$id, $section] = explode('#', $tocProps['page']);

        switch ($format) {
            case 'metadata':
                if ($id != $ID) { // not current page
                    // set dependency info for PARSER_CACHE_USE event handler
                    $renderer->meta['relation']['toctweak'][] = metaFN($id,'.meta');
                }
                return true;

            case 'xhtml':
                global $INFO;

                // retrieve TableOfContents from metadata
                if ($id == $INFO['id']) {
                    $toc = $INFO['meta']['description']['tableofcontents'];
                } else {
                    $toc = p_get_metadata($id,'description tableofcontents');
                }
                if ($toc == null) $toc = [];

                // load helper object
                isset($tocTweak) || $tocTweak = $this->loadHelper($this->getPluginName());

                // retrieve TableOfContents from metadata
                $topLv = $tocProps['toptoclevel'];
                $maxLv = $tocProps['maxtoclevel'];

                // filter toc items
                $toc = $tocTweak->toc_filter($toc, $topLv, $maxLv, $section);

                // modify toc items directly within loop by reference
                foreach ($toc as &$item) {
                    if ($id == $INFO['id']) {
                        // headings found in current page (internal link)
                        $item['url']  = '#'.$item['hid'];
                    } else {
                        // headings found in other wiki page
                        $item['page']  = $id;
                        $item['url']   = wl($id).'#'.$item['hid'];
                        $item['class'] = 'wikilink1';
                    }
                } // end of foreach
                unset($item); // break the reference with the last item

                // toc wrapper attributes
                $attr['class'] = $tocProps['class'];
                $title = $tocProps['title'] ?? $lang['toc'];

                $html = '<!-- EMBEDDED TOC START -->'.DOKU_LF;
                $html.= '<div '.buildAttributes($attr).'>'.DOKU_LF;
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