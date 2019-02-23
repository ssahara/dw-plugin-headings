<?php
/**
 * Heading PreProcessor plugin for DokuWiki; helper component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */
if (!defined('DOKU_INC')) die();

class helper_plugin_headings extends DokuWiki_Plugin
{
    /**
     * Resolve extra instruction data relevant to heading properties
     * Note: this should be applied during render proocess prior to
     *       1) store metadata "description_tableofcentents",
     *       2) render xhtml.
     * used in action_plugin_headings_backstage::extend_TableOfContents(),
     *         renderer_plugin_headings::header()
     *
     * @param array $extra  extra heading data, created in handler stage
     * @param int   $level  level of the heading
     * @param bool  $initHeaderCount  flag to initialize headline counter
     * @return  interpreted extra data
     */
    public function resolve_extra_instruction(array $extra, $level, &$initHeaderCount)
    {
        $number =& $extra['number'] ?? null;
        $hid    =& $extra['hid']    ?? null;
        $title  =& $extra['title']  ?? null;
        $xhtml  =& $extra['xhtml']  ?? null;

        // get tiered number for the heading
        $number = (isset($number))
            ? $this->_tiered_number($level, $number, $initHeaderCount)
            : null;
        // decide hid value, title text or tiered numbers
        if ($hid == '#') {
            $hid = (is_int($number[0]) ? 'section' : '').$number;
        } elseif (empty($hid)) {
            $hid = $title;
        }
        return $extra;
    }

    /**
     * Set numbered heading title
     * Note: don't store numbered title to metadata "description_tableofcentents".
     * used in syntax_plugin_headings_toc::render_embeddedtoc(),
     *         action_plugin_headings_toc::tpl_toc(),
     *         renderer_plugin_headings::header()
     *
     * @param array $extra  extra heading data, created in handler stage
     * @return  interpreted extra data
     */
    public function set_numbered_title(array $extra)
    {
        $number =& $extra['number'] ?? null;
        $title  =& $extra['title']  ?? null;
        $xhtml  =& $extra['xhtml']  ?? null;

        // append figure space (U+2007) after tiered number to distinguish title
        if ($title && isset($number)) {
            $title = $number.' '.$title;
            $xhtml = '<span class="tiered_number">'.$number.'</span> '.$xhtml;
        }
        return $extra;
    }

    /**
     * Return numbering label for hierarchical headings, eg. 1.2.3
     * 
     * Note1: numbering label may be numeric, or incrementable string such "A1"
     * Note2: #! means set the header level as the first tier of numbering
     *
     * @param int    $level   level of the heading
     * @param string $number  incrementable string for the numbered headings,
     *                        typically numeric, but also could be string such "A1"
     * @param bool   $initHeaderCount   flag to initialize headline counter
     * @return string  tired numbering label for the heading
     */
    private function _tiered_number($level, $number, &$initHeaderCount=false)
    {
        static $headerCount, $firstTierLevel;

        // initialize header counter, if necessary
        if (!isset($headerCount) || $initHeaderCount) {
            $headerCount = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $firstTierLevel = $this->getConf('numbering_firstTierLevel');
            $initHeaderCount = false;
        }
        // set the first tier level if number string starts '!'
        if ($number[0] == '!') {
             $firstTierLevel = $level;
             $number = substr($number, 1);
        }
        // set header counter for numbering
        $headerCount[$level] = empty($number)
            ? ++$headerCount[$level]  // increment counter
            : $number;
        // reset the number of the subheadings
        for ($i = $level +1; $i <= 5; $i++) {
            $headerCount[$i] = 0;
        }
        // build tiered number ex: 2.1, 1.
        $tier = $level - $firstTierLevel +1;
        $tiers = array_slice($headerCount, $firstTierLevel -1, $tier);
        $tiered_number = implode('.', $tiers);
        if (count($tiers) == 1) {
            // append always tailing dot for single tiered number
            if (strlen($tiered_number) == strspn($tiered_number,'1234567890')) {
                $tiered_number .= '.';
            }
        }
        return $tiered_number;
    }

    /* ----------------------------------------------------------------------- */

    /**
     * toc array filter
     */
    public function toc_filter(array $toc, $topLv=null, $maxLv=null, $start_hid='', $depth=5)
    {
        global $conf;
        $toptoclevel = $topLv ?? $conf['toptoclevel'];
        $maxtoclevel = $maxLv ?? $conf['maxtoclevel'];

        // first step: get headings starting specified hid and its sub-sections
        if ($start_hid) {
            foreach ($toc as $k => $item) {
                if (!isset($start_hid_level)) {
                    $start_hid_level = ($item['hid'] == $start_hid) ? $item['level'] : null;
                    if (!isset($start_hid_level)) {
                        unset($toc[$k]); // start_hid has not found yet
                    } else continue;
                } elseif ($start_hid_level > 0) {
                    if ($item['level'] > $start_hid_level + $depth) {
                        unset($toc[$k]); // too deeper items
                    }  elseif ($item['level'] <= $start_hid_level) {
                        unset($toc[$k]); // out of scope
                        $start_hid_level = -$start_hid_level; // reverse sign of number
                    }
                } else { // $start_hid_level < 0
                        unset($toc[$k]);
                }
            } // end of foreach

            // decide the upper level for toc hierarchy adjustment
            $start_hid_level = $start_hid_level ?? 0;
            $toptoclevel = max($toptoclevel, abs($start_hid_level));
        }

        // second step: exclude empty headings, toc hierarchy adjustment
        foreach ($toc as $k => $item) {
            if (empty($item['title'])
                || ($item['level'] < $toptoclevel)
                || ($item['level'] > $maxtoclevel)
            ) {
                unset($toc[$k]);
            }
            $item['level'] = $item['level'] - $toptoclevel +1;
        }
        return $toc;
    }

    /* ----------------------------------------------------------------------- */

    /**
     * Create a XHTML valid linkid from a given heading title
     * allow '.' in linkid, which should be match #[a-z][a-z0-9._-]*#
     *
     * @param string $title  heading title or hid
     * @param mixed  $check  flag, or array to memory once used hid
     * @return string
     * @see also DW original sectionID() method defined in inc/pageutils.php
     */
    public function sectionID($title, &$check)
    {
        // Note: Generally, the heading title does not end with suffix number like "_1",
        // however hid should be suffixed when necessary to identify duplicated title
        // in the page. Here, we remove tailing suffix number from the title/hid
        // that was appended by this method.
        if (is_array($check)) {
            $title = preg_replace('/_[0-9]*$/','', $title);
        }

        $title = str_replace(array(':'),'', cleanID($title));
        $newtitle = ltrim($title,'0123456789._-');
        if (empty($newtitle)) {
            // here, title consists [0-9._-]
            $title = 'section'.$title;
        } else {
            $title = $newtitle;
        }

        if (is_array($check)) {
            // make sure tiles are unique
            if (!array_key_exists ($title, $check)) {
                $check[$title] = 0;
            } else {
                $check[$title]++; // increment counts
                $title .= '_'.$check[$title]; // append '_' and count number to title
                $check[$title] = 0;
            }
        }

        return $title;
    }

}
