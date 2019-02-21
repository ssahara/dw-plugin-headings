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

        static $hpp; // headings preprocessor object
        isset($hpp) || $hpp = $this->loadHelper($this->getPluginName());

        // get level of the heading
        $text = trim($match);
        $level = 7 - min(strspn($text, '='), 6);
        $markup = str_repeat('=', 7 - $level);

        $text = trim($text, '= ');  // drop heading markup
        $title = $text;

        // Pre-Processing: hierarchical numbering for headings, eg 1.2.3
        // example usage ====#A1 headline text ====
        // Note1: numbers may be numeric, or incrementable string such "A1"
        // Note2: #! means set the header level as the first tier of numbering
        $func_get_number = function (&$title) { // closure
            if (preg_match('/^#!?[^ |]*/u', $title, $matches)) {
                $number = substr($matches[0], 1);
                $title = ltrim( substr($title, strlen($matches[0])) );
                return $number;
            } else {
                return null;
            }
        };
        if ($title[0] == '#') {
          $number = $func_get_number($title);
        }

        // Pre-Processing: persistent hid for headings
        // example usage ====hid | headline text ====
        // example usage ====hid | #A1 headline text ====
        // example usage ==== | headline text ====
        if (strpos($title, '|') !== false) {
            // speparate hid from headings text
            [$hid, $title] = array_map('trim', explode('|', $title, 2));
            if (!isset($number)) {
                $number = $func_get_number($title);
            } elseif (empty($hid) && isset($number)) {
                $hid = '#'; // hid should be set using tiered number for the heading
            }
        }

        // Pre-Processing the heading text
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

        // fallback for persistent hid
        // NOTE: unique hid should be set in PARSER_HANDLER_DONE event handler
        if (!isset($hid)) {
            $hid = $hpp->sectionID($title, $check=[]);
        }

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
