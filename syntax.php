<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class syntax_plugin_headings extends DokuWiki_Syntax_Plugin {

    function getType() { return 'baseonly'; }
    function getSort() { return 45; }

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
        $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {

        static $headings0 = [];
        static $headings = [];

        // get level of the heading
        $text = trim($match);
        $level = 7 - min(strspn($text, '='), 6);
        $markup = str_repeat('=', 7 - $level);

        $text = trim($text, '= ');  // drop heading markup

        // speparate param from headings text
        if (strpos($text, '|') !== false) {
            [$param, $title0] = array_map('trim', explode('|', $text, 2));
        } else {
            $param = '';
            $title0 = trim($text);
        }

        // genrate original heading ID
        // $hid0 is used to modify $meta['description']['tableofcontents']
        $hid0 = sectionID($title0, $headings0); // hid0 must be unique in the page

        // pre-processing the heading text
        // NOTE: common plugin function render_text()
        // output text string through the parser, allows DokuWiki markup to be used
        if ($title0 && $this->getConf('header_formatting')) {
            $xhtml = $this->render_text($title0);
            $xhtml = substr($xhtml, 5, -6); // drop p tag and \n
            $xhtml = preg_replace('#<a\b.*?>(.*?)</a>#', '${1}', $xhtml);
            $title = htmlspecialchars_decode(strip_tags($xhtml), ENT_QUOTES);
            $title = str_replace(DOKU_LF, '', $title); // remove any linebreak
        } else {
            $xhtml = '';
            $title = $title0;
        }

        // param processing: user defined hid, shorter than title, independ from title change
        $hid = $param ?: $title;
        $hid = sectionID($hid, $headings); // hid must be unique in the page


        // call render method of this plugin
        $plugin = substr(get_class($this), 14);
        $data = [$pos, $level, $hid0, $title0, $hid, $title, $xhtml];
        $handler->addPluginCall($plugin, $data, $state,$pos,$match);

        // call header method of Doku_Handler class
        $match = $markup . $title0 . $markup;
        $handler->header($match, $state, $pos);

        return false;
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {

        // create headings metadata that compatible with 
        // $meta['current']['description']['tableofcontents']
        if ($format == 'metadata') {
            [$pos, $level, $hid0, $title0, $hid, $title, $xhtml] = $data;

            $renderer->meta['plugin']['headings'][$pos] = [
                    'hid0' => $hid0, 'title0' => $title0,
                    'hid' => $hid, 'title' => $title, 'xhtml' => $xhtml,
                    'level' => $level, 'type' => 'ul',
            ];
        }
    }

}
