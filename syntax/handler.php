<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * Catch all headings in the page.
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class syntax_plugin_headings_handler extends DokuWiki_Syntax_Plugin {

    function getType() { return 'baseonly'; }
    function getPType(){ return 'block'; }
    function getSort() { return 49; } // less than Doku_Parser_Mode_header = 50

    /**
     * Connect pattern to lexer
     */
    protected $mode, $pattern;

    function preConnect() {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern: catch all headings
        // see "class Doku_Parser_Mode_header" in DW/inc/parser/parser.php
        $this->pattern[0] = '[ \t]*={2,}[^\n]+={2,}[ \t]*(?=\n)';
    }

    function connectTo($mode) {
        global $conf;
        if ($conf['renderer_xhtml'] == 'headings') {
            $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
        }
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        // get level of the heading
        $text = trim($match);
        $level = 7 - min(strspn($text, '='), 6);
        $markup = str_repeat('=', 7 - $level);

        $text = trim($text, '= ');  // drop heading markup

        // speparate param from headings text
        if (strpos($text, '|') !== false) {
            [$param, $title] = array_map('trim', explode('|', $text, 2));
        } else {
            $param = '';
            $title = trim($text);
        }

        // pre-processing the heading text
        // NOTE: common plugin function render_text()
        // output text string through the parser, allows DokuWiki markup to be used
        if ($title && $this->getConf('header_formatting')) {
            $xhtml = trim($this->render_text($title));
            if (substr($xhtml, 0, 4) == "<p>\n") {
                $xhtml = strstr( substr($xhtml,4), "\n</p>", true);
                $xhtml = preg_replace('#<a\b.*?>(.*?)</a>#', '${1}', $xhtml);
                $newtitle = htmlspecialchars_decode(strip_tags($xhtml), ENT_QUOTES);
                $newtitle = str_replace(DOKU_LF, '', $newtitle); // remove any linebreak
                $title = $newtitle ?: $title;
            } else {
                $xhtml = hsc($title);
            }
        } else {
            $xhtml = '';
        }

        // param processing: hierarchical numbering for headings, eg 1.2.3
        // Note1: numbers may be numeric, or string such "A1"
        // Note2: #! means set the header level as the first tier of numbering
        if ($param[0] == '#') {
            // separate #number part, drop # from number
            [$number, $param] = explode(' ', substr($param,1), 2);
            $isFirstTier = ($number[0] == '!') ? true : false;
        } else {
            $number = null;
        }

        // param processing: persistent hid
        $hid = $param ?: $title;

        // call header method of Doku_Handler class
        $match = $markup . (strlen($title) ? $title : ' ') . $markup;
        $handler->header($match, $state, $pos);

        // call render method of this plugin
        $plugin = substr(get_class($this), 14);
        $data = [$ID, $pos, $level, $number, $hid, $title, $xhtml];
        $handler->addPluginCall($plugin, $data, $state,$pos,$match);

        return false;
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {

        // create headings metadata that is compatible with
        // $renderer->meta['description']['tableofcontents']
        if ($format == 'metadata') {
            [$page, $pos, $level, $number, $hid, $title, $xhtml] = $data;

            // store into matadata storage
            $metadata =& $renderer->meta['plugin'][$this->getPluginName()];
            $metadata['tableofcontents'][] = [
                    'page' => $page, 'pos' => $pos,
                    'level' => $level, 'number' => $number,
                    'hid' => $hid, 'title' => $title, 'xhtml' => $xhtml,
            ];
        }
    }

}
