<?php
/**
 * Heading PreProcessor plugin for DokuWiki; syntax component
 *
 * wrapper for Include plugins syntax
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class syntax_plugin_headings_include extends DokuWiki_Syntax_Plugin {

    function getType() { return 'protected'; }
    function getPType(){ return 'block'; }
    function getSort() { return 30; }

    /**
     * Connect pattern to lexer
     */
    protected $mode, $pattern;

    function preConnect() {
        // syntax mode, drop 'syntax_' from class name
        $this->mode = substr(get_class($this), 7);

        // syntax pattern
        $this->pattern[3] = '{{INCLUDE\b.+?}}';  // {{INCLUDE [flags] >[id]#[section]}}
        $this->pattern[4] = '{{page>.+?}}';      // {{page>[id]&[flags]}}
        $this->pattern[5] = '{{section>.+?}}';   // {{section>[id]#[section]&[flags]}}
     // $this->pattern[6] = '{{namespace>.+?}}'; // {{namespace>[namespace]#[section]&[flags]}}
     // $this->pattern[7] = '{{tagtopic>.+?}}';  // {{tagtopic>[tag]&[flags]}}
    }

    function connectTo($mode) {
        if (!plugin_isdisabled('include')) {
            $this->Lexer->addSpecialPattern($this->pattern[3], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[4], $mode, $this->mode);
            $this->Lexer->addSpecialPattern($this->pattern[5], $mode, $this->mode);
        }
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $ID;

        if (substr($match, 2, 7) == 'INCLUDE') {
            // use case {{INCLUDE [flags] >[id]#[section]}}
            [$flags, $page] = array_map('trim', explode('>', substr($match, 9, -2), 2));
            [$page, $sect] = explode('#', $page, 2);
            $flags = explode(' ', $flags);
        } else {
            // use case {{section>[id]#[section]&[flags]}}
            [$param, $flags] = explode('&', substr($match, 2, -2), 2);
            [$mode, $page, $sect] = preg_split('/>|#/u', $param, 3);
            $flags = explode('&', $flags);
        }

        $page = ($page) ? cleanID($page) : $ID;
        $check = false;
        $sect = (isset($sect)) ? sectionID($sect, $check) : null;

        // check whether page and section exist using meta file
        $check = [];
        $toc = p_get_metadata($page,'description tableofcontents');
        $check['page'] = isset($toc);

        if (isset($toc) && $sect) {
             $map = array_column($toc, null, 'hid');
             $hid0 = $map[$sect]['hid0'] ?? null;
             $check['sect'] = isset($hid0);
        }

        $plugin = substr(get_class($this), 14);
        $data = [$match, $check, $hid0];
        $handler->addPluginCall($plugin, $data, $state,$pos,$match);

        // do not call include_include when page or section not exist
        if (array_product($check) == 0) {
            return false;
        }

        // instruction to call include plugin syntax component
        $plugin = 'include_include';
        $level = null;
        $data = [$mode, $page, $hid0, (array) $flags, $level, $pos];
        $handler->addPluginCall($plugin, $data, $state,$pos,$match);

        return false;
    }

    /**
     * Create output
     */
    function render($format, Doku_Renderer $renderer, $data) {
        global $ACT;

        [$match, $check, $hid0] = $data;

        if ($format == 'xhtml') {
            if ($ACT == 'preview') {
                $note = '';
                if (isset($check['sect']) && !$check['sect']) {
                    $note = '! section not found';
                } elseif (isset($check['page']) && !$check['page']) {
                    $note = '! page not found';
                } elseif ($hid0) {
                    $note = '(#'.$hid0.')';
                }
                $out = '<code class="preview_note">'.$match.' '.$note.'</code>';
                $renderer->doc .= $out;
            }
        }
    }

}
