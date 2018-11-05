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

        if ($type == 0) { // macro appricable both TOC and INLINETOC

            switch ($name) {
                case 'NOTOC':
                    $handler->_addCall('notoc', array(), $pos); // 他ページのこれは無視すべきか?
                    $tocDisplay = 'none';
                    $type = 1;
                    break;
                case 'CLOSETOC':
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
        }

        $tocProps = array_filter( // remove null values
                [
                    'display'     => $tocDisplay, // TOC box PlaceHolder名or表示位置
                    'state'       => $tocState,   // TOC box 開閉状態 -1:close
                    'toptoclevel' => $topLv,      // TOC 見だし範囲の上位レベル
                    'maxtoclevel' => $maxLv,      // TOC 見だし範囲の下位レベル
                    'class'       => $tocClass,   // TOC box 微調整用CSSクラス名
                ],
                function($v) {
                        return !is_null($v);
                }
        );

        return $data = [$ID, $tocProps];
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $ACT, $ID;
        static $counts; // count toc placeholders appeared in the page

        [$id, $props] = $data;

        switch ($format) {
            case 'metadata':
                // ページのTOCの見せ方（表示位置は除く）は、自身のページ内で決定する
                if ($id !== $ID) return false; // 他ページのinstructions は無視する

                // store into matadata storage
                $metadata =& $renderer->meta['plugin'][$this->getPluginName()];

                // add only new key-value pairs, keep already stored data
                // 先に出現した構文による設定が優先権をもつ
                $tocProps = ($metadata['toc'] ?? []) + (array) $props;

                $metadata['toc'] = $tocProps;
                return true;

            case 'xhtml':
                // 他ページに設置された {{TOC}} or {{INLINEOC}} も考慮する
                // ただし、~~NOTOC~~ or ~~CLOSETOC~~ は無視する
                if ( ($props['display'] ?? '') == 'none' ) {
                    return false;
                }

                // render PLACEHOLDER, which will be replaced later
                // through action TPL_CONTENT_DISPLAY event handler
                if (!isset($counts[$id])) {
                    $tocName = $props['display'];
                    $counts[$id] = 0;
                } else {
                    $tocName = $props['display'].(++$counts[$id]);
                }

                if ($ACT == 'preview') {
                    $state = $props['state'] ? 'CLOSED_' : '';
                    $range = $props['toptoclevel'].'-'.$props['toptoclevel'];
                    $renderer->doc .= '<code>';
                    $renderer->doc .= hsc('<!-- '.$state.strtoupper($tocName).'_HERE '.$range.' -->');
                    $renderer->doc .= '</code>'.DOKU_LF;
                }
                $renderer->doc .= '<!-- '.strtoupper($tocName).'_HERE -->'.DOKU_LF;
                return true;

        } // end of switch
        return false;
    }

}
