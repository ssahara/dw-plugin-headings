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
        $this->pattern[1] = '{{(?:CLOSED_)?(?:INLINETOC|TOC)\b.*?}}';
    }

    function connectTo($mode) {
        $this->Lexer->addSpecialPattern($this->pattern[0], $mode, $this->mode);
        $this->Lexer->addSpecialPattern($this->pattern[1], $mode, $this->mode);
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
        [$topLv, $maxLv, $tocClass] = $tocTweak->parse($param);

        if ($type == 0) { // macro

            switch ($name) {
                case 'NOTOC':
                    $handler->_addCall('notoc', array(), $pos);
                    $tocDisplay = 'none';
                    $type = 1;
                    break;
                case 'CLOSETOC':
                    $tocDisplay = 'toc'; // 暫定：本来は設定すべきでない
                    $tocState = -1;
                    break;
                case 'TOC':
                    break;
            } // end of switch

        } else { // type 1
            // DokiWiki original TOC or alternative INLINETOC
            // PLACEHOLDER を出力して、TPL_CONTENT_DISPLAY イベントで置き換える

            $tocState = (substr($name, 0, 6) == 'CLOSED') ? -1 : null;
            $tocDisplay = isset($tocState) ? substr($name, 7) : $name;  // place holder name
            $tocDisplay = strtolower($tocDisplay);
            $tocVariant = ($name == 'inlinetoc') ? 'toc_inline' : null;
        }

        $props = array_filter( // remove null values
                [
                    'display'     => $tocDisplay, // TOC box 表示位置 PLACEHOLDER名
                    'state'       => $tocState,   // TOC box 開閉状態 -1:close
                    'toptoclevel' => $topLv,      // TOC 見だし範囲の上位レベル
                    'maxtoclevel' => $maxLv,      // TOC 見だし範囲の下位レベル
                    'variant'     => $tocVariant, // TOC box 基本デザイン TOC or INLINETOC
                    'class'       => $tocClass,   // TOC box 微調整用CSSクラス名
                ],
                function($v) {
                        return !is_null($v);
                }
        );
        return $data = [$type, $props];
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $ID;

        // ページに複数のTOC （見かけは同じ）を許容する
        // HTML では id="dw__toc" が振られるので、重複識別用に末尾に番号を付加する
        // 番号付けには 関数 sectionID() を使う。
        //     dw__toc, dw__inlinetoc1, dw__toc2, dw__inlinetoc3, ...
        //     <!-- toc_here -->, <!-- inlinetoc1_here -->, <!-- toc2_here -->, ...
        // 最初のものには番号が付かないことを利用して、初出のみHTMLに置換する。
        // 

        static $tocProps; // toc box properties of the page
        static $tocCount; // count toc placeholders appeared in the page
 
        [$type, $props] = $data;
 
        switch ($format) {
            case 'metadata':

                if (!isset($tocProps[$ID])) {
                    $tocProps[$ID] = [];
                }
                if ($type == 0) {
                    $tocProps[$ID] += $props; // if key exists, the value is kept
                } else {
                    $tocDisplay = $tocProps[$ID]['display'];
                    $tocProps[$ID] = array_merge($tocProps[$ID], $props);
                    if($tocDisplay === 'none') $tocProps[$ID]['display'] = 'none';
                }

                // store into matadata storage
                $metadata =& $renderer->meta['plugin'][$this->getPluginName()];
                $metadata['toc'] = $tocProps[$ID];
                return true;

            case 'xhtml':
                if(!isset($props['display']) || ($props['display'] == 'none')) return false;

                // render PLACEHOLDER, which will be replaced later
                // through action TPL_CONTENT_DISPLAY event handler
                if (!isset($tocCount[$ID])) {
                    $tocId = $props['display'];
                    $tocCount[$ID] = 0;
                } else {
                    $tocId = $props['display'].(++$tocCount[$ID]);
                }
                $renderer->doc .= '<!-- '.strtoupper($tocId).'_HERE -->'.DOKU_LF;
                return true;

        } // end of switch
        return false;
    }

}
