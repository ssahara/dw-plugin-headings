<?php
/**
 * Heading PreProcessor plugin for DokuWiki; xhtml renderer component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_LF')) define ('DOKU_LF',"\n");

/**
 * The Renderer
 */
class renderer_plugin_headings extends Doku_Renderer_xhtml {

    function canRender($format) {
        return ($format == 'xhtml');
    }

    /**
     * Render a heading
     *
     * @param string $text  the text to display
     * @param int    $level header level
     * @param int    $pos   byte position in the original source
     */
    function header($text, $level, $pos) {
        global $conf;

        if(blank($text)) return; //skip empty headlines

        $hid = $this->_headerToLink($text, true);

        //only add items within configured levels
        $this->toc_additem($hid, $text, $level);

        // adjust $node to reflect hierarchy of levels
        $this->node[$level - 1]++;
        if($level < $this->lastlevel) {
            for($i = 0; $i < $this->lastlevel - $level; $i++) {
                $this->node[$this->lastlevel - $i - 1] = 0;
            }
        }
        $this->lastlevel = $level;

        if($level <= $conf['maxseclevel'] &&
            count($this->sectionedits) > 0 &&
            $this->sectionedits[count($this->sectionedits) - 1]['target'] === 'section'
        ) {
            $this->finishSectionEdit($pos - 1);
        }

        // write the header
        $this->doc .= DOKU_LF.'<h'.$level;
        if($level <= $conf['maxseclevel']) {
            $data = array();
            $data['target'] = 'section';
            $data['name'] = $text;
            $data['hid'] = $hid;
            $data['codeblockOffset'] = $this->_codeblock;
            $this->doc .= ' class="'.$this->startSectionEdit($pos, $data).'"';
        }
        $this->doc .= ' id="'.$hid.'">';
        $this->doc .= $this->_xmlEntities($text);
        $this->doc .= "</h$level>".DOKU_LF;
    }

}

