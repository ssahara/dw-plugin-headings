<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * set top and max level of headlines to be found in the table of contents
 * allow to set autoTOC state initially closed
 * render toc placeholder to show built-in toc box in the page
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class syntax_plugin_headings_autotoc extends DokuWiki_Syntax_Plugin {

    function getType() { return 'substition'; }
    function getPType(){ return 'block'; }
    function getSort() { return 29; } // less than Doku_Parser_Mode_notoc = 30

    /**
     * Connect pattern to lexer
     */
    protected $mode, $pattern;

    function preConnect() {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern
        $this->pattern[0] = '~~(?:NO|CLOSE)?TOC\b.*?~~';
        $this->pattern[1] = '{{(?:CLOSED_)?(?:INLINE)?TOC\b.*?}}';
    }

    function connectTo($mode) {
        always: {
            $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
        }
        if ($this->getConf('tocDisplay') != 'disabled') {
            $this->Lexer->addSpecialPattern($this->pattern[1], $mode, $this->mode);
        }
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        // load helper object
        isset($tocTweak) || $tocTweak = $this->loadHelper($this->getPluginName());

        // parse syntax
        $type = ($match[0] == '~') ? 0 : 1;
        [$name, $param] = explode(' ', substr($match, 2, -2), 2);

        // resolve toc parameters such as toptoclevel, maxtoclevel, class, title
        $tocProps = $param ? $tocTweak->parse($param) : [];

        if ($type == 0) { // macro appricable both TOC and INLINETOC

            switch ($name) {
                case 'NOTOC':
                    $handler->_addCall('notoc', array(), $pos);
                    $tocProps['display'] = 'none';
                    $type = 1;
                    break;
                case 'CLOSETOC':
                    $tocProps['state'] = -1;
                    break;
                case 'TOC':
                    break;
            } // end of switch

        } else { // type 1
            // DokiWiki original TOC or alternative INLINETOC

            if (substr($name, 0, 6) == 'CLOSED') {
                $tocProps['state'] = -1;
                $tocProps['display'] = strtolower(substr($name, 7));
            } else {
                $tocProps['display'] = strtolower($name);
            }
        }


        return $data = [$ID, $tocProps];
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {

        [$id, $props] = $data;

        switch ($format) {
            case 'metadata':
                global $ID;
                if ($id !== $ID) return false; // ignore instructions for other page

                // store into matadata storage
                $metadata =& $renderer->meta['plugin'][$this->getPluginName()];

                // add only new key-value pairs, keep already stored data
                if (in_array($props['display'], ['toc','inlinetoc'])) {
                    if (!isset($metadata['toc']['display'])) {
                        $metadata['toc'] = $props;
                    }
                }
                return true;

            case 'xhtml':
                global $INFO, $ACT;
                static $counts; // count toc placeholders appeared in the page

                // render PLACEHOLDER, which will be replaced later
                // through action TPL_CONTENT_DISPLAY event handler
                if (in_array($props['display'], ['toc','inlinetoc'])) {
                    if (!isset($counts[$id])) {
                        $tocName = $props['display'];
                        $counts[$id] = 0;
                    } else {
                        $tocName = $props['display'].(++$counts[$id]);
                    }
                } else return false;

                if ($ACT == 'preview') {
                    $state = $props['state'] ? 'CLOSED_' : '';
                    $range = $props['toptoclevel'].'-'.$props['maxtoclevel'];
                    $note = '<!-- '.$state.strtoupper($tocName).'_HERE '.$range.' -->';
                    $renderer->doc .= '<code class="preveiw_note">';
                    $renderer->doc .= hsc($note);
                    $renderer->doc .= '</code>'.DOKU_LF;
                }
                $renderer->doc .= '<!-- '.strtoupper($tocName).'_HERE -->'.DOKU_LF;
                return true;

        } // end of switch
        return false;
    }

}
