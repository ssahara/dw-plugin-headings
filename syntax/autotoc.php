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
        $this->pattern[0] = '~~(?:TOC_HERE(?:_CLOSED)?|(?:NO|CLOSE)?TOC)\b.*?~~';
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        // load helper object
        isset($tocTweak) || $tocTweak = $this->loadHelper($this->getPluginName());

        // parse syntax
        preg_match('/^~~([A-Z_]+)/', $match, $m);
        $start = strlen($m[1]) +2;
        $param = substr($match, $start+1, -2);
        list($topLv, $maxLv, $tocClass) = $tocTweak->parse($param);

        switch ($m[1]) {
            case 'NOTOC':
                $handler->_addCall('notoc', array(), $pos);
                $tocDisplay = null; // or 'none'
                break;
            case 'CLOSETOC':
                $tocDisplay = null; // $this->getConf('tocDisplay');
                $tocState = -1;
                break;
            case 'TOC':
                $tocDisplay = null; // $this->getConf('tocDisplay');
                break;
            case 'TOC_HERE':
                $tocDisplay = -1;
                break;
            case 'TOC_HERE_CLOSED':
                $tocDisplay = -1;
                $tocState = -1;
                break;
        } // end of switch

        return $data = [$ID, $tocDisplay, $tocState, $topLv, $maxLv, $tocClass];
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $ID;
        static $call_counter = [];  // counts macro used in the page
        static $call_ignore  = [];  // flag to be set when decisive macro has resolved

        list($id, $tocDisplay, $tocState, $topLv, $maxLv, $tocClass) = $data;

        // skip calls that belong to different page (eg. included pages)
        //if ($id != $ID) return false;

        // skip unnecessary calls after decisive macro has resolved
        if (isset($call_ignore[$format][$ID])) {
            return false;
        }

        switch ($format) {
            case 'metadata':
                // skip macros appeared more than once in a page, except ~~TOC_HERE~~
                if ($tocDisplay === -1) {
                    unset($call_counter[$ID]);
                }
                if ($call_counter[$ID]++ > 0) {
                    return false;
                }

                // store into matadata storage
                $metadata =& $renderer->meta['plugin'][$this->getPluginName()];
                $metadata['toc'] = [
                    'display'     => $tocDisplay,
                    'state'       => $tocState,
                    'toptoclevel' => $topLv,
                    'maxtoclevel' => $maxLv,
                    'class'       => $tocClass,
                ];

                if ($tocDisplay === -1) {
                    $call_ignore[$format][$ID] = true;
                }

                return true;

            case 'xhtml':
                // render PLACEHOLDER, which will be replaced later
                // through action TPL_CONTENT_DISPLAY event handler
                if ($tocDisplay === -1) {
                    $renderer->doc .= '<!-- TOC_HERE -->'.DOKU_LF;
                    $call_ignore[$format][$ID] = true;
                    return true;
                } else {
                    return false;
                }

        } // end of switch
        return false;
    }

}
