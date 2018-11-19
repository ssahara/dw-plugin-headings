<?php
/**
 * Heading PreProcessor plugin for DokuWiki
 *
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

$meta['header_formatting'] = array('onoff');
$meta['numbering_firstTierLevel'] = array('multichoice', '_choices' => array(1, 2 ,3, 4, 5));

// Extends DokuWiki original Table of Contents (TOC) feature
$meta['tocDisplay'] = array('multichoice','_choices' => ['disabled','none','top','0','1','2']);
