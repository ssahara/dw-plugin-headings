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


    protected $headings0 = array(); // memory once used hid

    /**
     * Reset protected properties of class Doku_Renderer_xhtml
     */
    function reset() {
        $this->doc = '';
        $this->footnotes = array();
        $this->lastsecid = 0;
        $this->store = '';
        $this->_counter = array();

        // properties defined in class renderer_plugin_headings
        $this->headings0 = array();
    }

    /**
     * Render plain text data - use linebreak2 plugin if available
     *
     * @param string $text  the text to display
     */
    function cdata($text) {

        static $renderer;
        isset($renderer) || $renderer = $this->loadHelper('linebreak2') ?? false;
        if ($renderer) {
            $this->doc .= $renderer->cdata($text);
        } else {
            parent::cdata($text);
        }
    }

    /**
     * Render a heading
     *
     * @param string $text  the text to display
     * @param int    $level header level
     * @param int    $pos   byte position in the original source
     */
    function header($hid, $level, $pos, $title=null, $xhtml=null) {
        global $ACT, $INFO, $ID, $conf;

        /*
         * EXPERIMENTAL: Render a formatted heading
         */
        // creates a linkid from a heading
        $hid1 = sectionID($hid, $check = false);
        $hid = $this->_headerToLink($hid, true); // ensure unique hid
        if ($hid != $hid1) {
            $debug = strtoupper(get_class($this));
            error_log($debug.' : duplicated hid ('.$hid1.') found in '.$ID);
        }


        // write anchor for empty or hidden/unvisible headings
        if (empty($title)) {
            $this->doc .= DOKU_LF.'<a id="'.$hid.'"></a>'.DOKU_LF;
            goto toc_here_check;
        }

        //only add items within configured levels
        $this->toc_additem($hid, $title, $level);

        // adjust $node to reflect hierarchy of levels
        $this->node[$level - 1]++;
        if ($level < $this->lastlevel) {
            for($i = 0; $i < $this->lastlevel - $level; $i++) {
                $this->node[$this->lastlevel - $i - 1] = 0;
            }
        }
        $this->lastlevel = $level;

        if ($level <= $conf['maxseclevel']
            && count($this->sectionedits) > 0
            && $this->sectionedits[count($this->sectionedits) - 1]['target'] === 'section'
        ) {
            $this->finishSectionEdit($pos - 1);
        }

        // write the header
        $this->doc .= DOKU_LF.'<h'.$level;
        if ($level <= $conf['maxseclevel']) {
            $data = array();
            $data['target'] = 'section';
            $data['name'] = $title;
            $data['hid'] = $hid;
            $data['codeblockOffset'] = $this->_codeblock;
            $this->doc .= ' class="'.$this->startSectionEdit($pos, $data).'"';
        }
        $this->doc .= ' id="'.$hid.'">';
        $this->doc .= empty($xhtml) ? $title : $xhtml;
        $this->doc .= '</h'.$level.'>'.DOKU_LF;


        // append TOC_HERE placeholder if necessary
        toc_here_check: {
            $metadata =& $INFO['meta']['plugin'][$this->getPluginName()];
            $toc_hid = $metadata['toc']['hid'] ?? '#';
            if ($toc_hid == $hid) {
                $this->doc .= '<!-- TOC_HERE -->'.DOKU_LF;
            }
        }
    }

}
