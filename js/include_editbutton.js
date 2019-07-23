/**
 * Javascript functionality for the plugin headings_include syntax component
 */

/**
 * Highlight the included section when hovering over the appropriate include edit button
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @author Michael Klier <chi@chimeric.de>
 * @author Michael Hamann <michael@content-space.de>
 */
jQuery(function() {
    jQuery('.btn_plugin_headings_include')
        .mouseover(function () {
            jQuery(this).closest('.plugin_headings_include_content').addClass('section_highlight');
        })
        .mouseout(function () {
            jQuery('.section_highlight').removeClass('section_highlight');
        });
});
