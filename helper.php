<?php
/**
 * Heading PreProcessor plugin for DokuWiki; helper component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */
if(!defined('DOKU_INC')) die();

class helper_plugin_headings extends DokuWiki_Plugin {

    /**
     * toc array filter
     */
    function toc_filter(array $toc, $topLv=null, $maxLv=null, $start_hid='', $depth=5) {
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

    /**
     * hierarchical numbering for toc items
     * - add tiered numbers as indexes for hierarchical headings
     * Note1: numbers may be numeric, string such "A1"
     * Note2: #! means set the header level as the first tier of numbering
     */
    function toc_numbering(array $toc) {

        $headerCountInit = true;
 
        foreach ($toc as $k => &$item) {
            $number =& $item['number'];
            $level  =& $item['level'];
            $title  =& $item['title'];
            $xhtml  =& $item['xhtml'];

            // get tiered number for the heading
            if (isset($number)) {
                $tiered_number = $this->_tiered_number($level, $number, $headerCountInit);

                // append figure space after tiered number to distinguish title
                $tiered_number .= ' '; // U+2007 figure space
                if ($title) {
                    $title = $tiered_number . $title;
                    $xhtml = '<span class="tiered_number">'.$tiered_number.'</span>'.$xhtml;
                }
            }
        } // end of foreach
        return $toc;
    }

    /**
     * Return numbering label for hierarchical headings, eg. 1.2.3
     *
     * @param int    $level   level of the heading
     * @param string $number  incrementable string for the numbered headings,
     *                        typically numeric, but also could be string such "A1"
     * @param bool   $reset   flag to initialize headline counter
     * @return string  tired numbering label for the heading
     */
    function _tiered_number($level, $number, &$reset=false) {
        static $headerCount, $firstTierLevel;

        // initialize header counter, if necessary
        if (!isset($headerCount) || $reset) {
            $headerCount = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
            $firstTierLevel = $this->getConf('numbering_firstTierLevel');
            $reset = false;
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
            $tiered_number .= '.';
        }
        return $tiered_number;
    }


    /**
     * Create a XHTML valid linkid from a given heading title
     * allow '.' in linkid, which should be match #[a-z][a-z0-9._-]*#
     *
     * @see also DW original sectionID() method defined in inc/pageutils.php
     */
    function sectionID($title, &$check) {
        $title = str_replace(array(':'),'', cleanID($title));
        // remove suffix number that appended for duplicated title in the page, like title_1
        $title = preg_replace('/_[0-9]*$/','', $title);
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

