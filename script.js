/*
 * Heading PreProcessor plugin for DokuWiki
 * Change state of the auto toc, table of contents of the page to be shown
 * in a neat little box usually at the top right corner of the content.
 *  -1: toc box closed
 * @see also plugin's sub syntax component autotoc.php
 */
jQuery(function() {
    if (typeof(JSINFO.toc) != 'undefined') {
        var $toc = jQuery('#dw__toc h3');
        if ($toc.length) {
            $toc[0].setState(JSINFO.toc.initial_state);
        }
    }
});
