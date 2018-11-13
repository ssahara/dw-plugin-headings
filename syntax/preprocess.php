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

class syntax_plugin_headings_preprocess extends DokuWiki_Syntax_Plugin {

    function getType() { return 'baseonly'; }
    function getPType(){ return 'block'; }
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
        global $ID;

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

        // call header method of Doku_Handler class
        $match = $markup . $hid . $markup;
        $handler->header($match, $state, $pos);

        // call render method of this plugin
        $plugin = substr(get_class($this), 14);
        $data = [$ID, $pos, $level, $title0, $hid, $title, $xhtml];
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
            [$page, $pos, $level, $title0, $hid, $title, $xhtml] = $data;

            // store into matadata storage
            $metadata =& $renderer->meta['plugin'][$this->getPluginName()];
            $metadata['tableofcontents'][] = [
                    'page' => $page, 'pos' => $pos,
                    'level' => $level, 'title0' => $title0,
                    'title' => $title, 'xhtml' => $xhtml, 'hid' => $hid,
            ];
        }
    }

}
