<?php
/**
 * Heading PreProcessor plugin for DokuWiki
 *
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

$meta['header_formatting'] = array('onoff'); //experimetal

// Extends DokuWiki original Table of Contents (TOC) feature
$meta['tocDisplay'] = array('multichoice','_choices' => ['disabled','none','top','0','1','2']);
