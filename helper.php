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
     * syntax parser
     */
    function parse($param) {
        global $conf;

        // Ex: {{!TOC 2-4 width18 toc_hierarchical >id#section | title}}
        $tocProps = [
            'toptoclevel' => $conf['toptoclevel'], // TOC upper level
            'maxtoclevel' => $conf['maxtoclevel'], // TOC lower level
            'class'       => null,     // TOC box CSS selector (space delimited)
            'title'       => null,     // TOC box title
            'page'        => null,     // page id & start hid for TOC (id#start_hid)
        ];

        // get tocTitle
        if (strpos($param, '|') !== false) {
            [$param, $tocTitle] = explode('|', $param, 2);
            // empty tocTitle will remove h3 'Table of Contents' headline
            $tocProps['title'] = trim($tocTitle); 
        }

        // get id#section
        if (strpos($param, '>') !== false) {
            [$param, $page] = explode('>', $param, 2);
            [$id, $section] = array_map('trim', explode('#', $page, 2));
            $section = $section ? sectionID($section, $check = false) : '';
            $tocProps['page'] = cleanID($id).($section ? '#'.$section : '');
        }

        // get other parameters
        $params = explode(' ', $param);
        foreach ($params as $token) {
            if (empty($token)) continue;

            // get TOC generation parameters, like "toptoclevel"-"maxtoclevel"
            if (preg_match('/^(?:(\d+)-(\d+)|^(\-?\d+))$/', $token, $matches)) {
                if (count($matches) == 4) {
                    if (strpos($matches[3], '-') !== false) {
                        $maxLv = abs($matches[3]);
                    } else {
                        $topLv = $matches[3];
                    }
                } else {
                        $topLv = $matches[1];
                        $maxLv = $matches[2];
                }

                if (isset($topLv)) {
                    $topLv = ($topLv < 1) ? 1 : $topLv;
                    $topLv = ($topLv > 5) ? 5 : $topLv;
                    $tocProps['toptoclevel'] = $topLv;
                }

                if (isset($maxLv)) {
                    $maxLv = ($maxLv > 5) ? 5 : $maxLv;
                    $tocProps['maxtoclevel'] = $maxLv;
                }
                continue;
            }

            // get class name for TOC box, ensure excluded any malcious character
            if (!preg_match('/[^ A-Za-z0-9_-]/', $token)) {
                $classes[] = $token;
            }
        } // end of foreach

        if (!empty($classes)) {
            $tocProps['class'] = implode(' ', $classes);
        }

        // remove null values
        $tocProps = array_filter( $tocProps,
                        function($v) { return !is_null($v); }
        );
        return $tocProps;
    }

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
     */
    function toc_numbering(array $toc) {

        $headerCount = array_fill(1, 5, 0);
        $startlevel = $this->getConf('numbering_startlevel') ?: 1;
 
        foreach ($toc as $k => &$item) {
            $number =& $item['number'];
            $level  =& $item['level'];
            $title  =& $item['title'];
            $xhtml  =& $item['xhtml'];

            if ($title || isset($number)) {
                // set header counter for numbering
                $headerCount[$level] = is_numeric($number)
                    ? $number                   // setting 0 is acceptable
                    : $headerCount[$level] + 1; // increment counter
                // reset the number of the subheadings
                for ($i = $level +1; $i <= 5; $i++) {
                    $headerCount[$i] = 0;
                }
                // build tiered number ex: 2.1, 1.
                $tier = $level - $startlevel +1;
                $tiers = array_slice($headerCount, $startlevel -1, $tier);
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

