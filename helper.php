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

     // $headerCount = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $headerCount = array_fill(1, 5, 0);
        $firstTierLevel = $this->getConf('numbering_firstTierLevel') ?: 1;
 
        foreach ($toc as $k => &$item) {
            $number =& $item['number'];
            $level  =& $item['level'];
            $title  =& $item['title'];
            $xhtml  =& $item['xhtml'];

            if ($title || isset($number)) {
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
                // append figure space after tiered number to distinguish title
                $tiered_number .= ' '; // U+2007 figure space
                if ($title && isset($number)) {
                    $title = $tiered_number . $title;
                    $xhtml = '<span class="tiered_number">'.$tiered_number.'</span>'.$xhtml;
                }
            }
        } // end of foreach
        return $toc;
    }

}

