/*
 * Heading PreProcessor plugin for DokuWiki; toc_status.js
 *
 * Set auto TOC box initial status (opened or closed) 
 * in accordance with JSINFO.toc value.
 */
jQuery(function() {
    if (typeof(JSINFO.toc) != 'undefined') {
        var $toc = jQuery('#dw__toc h3');
        if ($toc.length) {
            $toc[0].setState(JSINFO.toc.initial_state);
        }
    }
});
