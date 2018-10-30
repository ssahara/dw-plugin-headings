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
    function header($text, $level, $pos) {
        global $ACT, $INFO, $ID, $conf;
        $debug = strtoupper(get_class($this));  //デバッグ用

        /*
         * EXPERIMENTAL: Render a formatted heading
         */
        static $map;

        if (!isset($map)) {
            $toc = &$INFO['meta']['description']['tableofcontents'] ?? [];
            $map = array_column($toc, null, 'hid0');
        }

        // resolove hid0
        $title0 = $text; // may contains wiki formatting markups
        $hid0 = sectionID($title0, $this->headings0); // hid0 must be unique in the page


        if ($ACT != 'preview') {
            if (!isset($map[$hid0])) {
                error_log($debug.' hid0='.$hid0.' NOT found in map title='.$title.' in '.$ID);

            }

            // retrieve headings meatadata
            // Note: metadata is NOT available during preview mode ($ACT=preview)
            $xhtml = $map[$hid0]['xhtml'] ?? hsc($title0);
            $title = $map[$hid0]['title'] ?? $title0;
            $hid1  = $map[$hid0]['hid'] ?? '';
        }

        // preview mode or fallback when metadata is not available
        if (empty($hid1) && $this->getConf('header_formatting')) { 

            // NOTE: common plugin function render_text()
            // output text string through the parser, allows DokuWiki markup to be used
            // very ineffecient for small pieces of data - try not to use
            $xhtml = $this->render_text($title0);
            $xhtml = substr($xhtml, 5, -6); // drop p tag and \n
            $xhtml = preg_replace('#<a\b.*?>(.*?)</a>#', '${1}', $xhtml);
            $title = htmlspecialchars_decode(strip_tags($xhtml), ENT_QUOTES);
            $title = str_replace(DOKU_LF, '', $title); // remove any linebreak

        } else if (!isset($title)) {
            $xhtml = '';
            $title = $title0;
        }


        // creates a linkid from a heading
        $heading_id = $hid1 ?? $title;
        $hid = $this->_headerToLink($heading_id, true); // ensure unique hid


        // write anchor for empty or hidden/unvisible headings
        if (empty($title)) {
            $this->doc .= DOKU_LF.'<a id="'.$hid.'"></a>'.DOKU_LF;
            return;
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
        $this->doc .= $xhtml;
        $this->doc .= '</h'.$level.'>'.DOKU_LF;

         // 直後にTOCを表示する見だしの場合
        $toc_hid = $INFO['meta']['toc']['hid'] ?? '#';
        if ($toc_hid == $hid0) {
            $this->doc .= '<!-- TOC_HERE -->'.DOKU_LF;
        }


    }

}

