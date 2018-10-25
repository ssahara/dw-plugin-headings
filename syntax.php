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

        static $headings = [];

        // get level of the heading
        $text = trim($match);
        $level = 7 - min(strspn($text, '='), 6);
        $markup = str_repeat('=', 7 - $level);

        $title = trim($text, '= ');  // drop heading markup

        // genrate original heading ID
        // $hid is used to modify $meta['description']['tableofcontents']
        $hid = sectionID($title, $headings); // hid must be unique in the page

        // call render method of this plugin
        $plugin = substr(get_class($this), 14);
        $data = [$pos, $level, $hid, $title];
        $handler->addPluginCall($plugin, $data, $state,$pos,$match);

        // call header method of Doku_Handler class
        $match = $markup . $title . $markup;
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
            [$pos, $level, $hid, $title] = $data;

            $renderer->meta['plugin']['headings'][$pos] = [
                    'hid' => $hid, 'title' => $title,
                    'level' => $level, 'type' => 'ul',
            ];
        }
    }

}
