<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_headings_embeddedtoc extends DokuWiki_Action_Plugin {

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        always: {
            $controller->register_hook(
                'DOKUWIKI_PARSER_CACHE_USE', 'BEFORE', $this, 'handleParserCache', []
            );
        }
    }


    /**
     * manipulate cache validity (to get correct toc of other page)
     */
    function handleParserCache(Doku_Event $event) {
        $cache =& $event->data;
        if (!$cache->page) return;

        switch ($cache->mode) {
            case 'i':        // instruction cache
            case 'metadata': // metadata cache
                break;
            case 'xhtml':    // xhtml cache
                // request check with additional dependent files
                $metadata_key = 'plugin '.$this->getPluginName();
                $metadata_key.= ' '.'depends';
                $depends = p_get_metadata($cache->page, $metadata_key);
                if (!$depends) break;

                $cache->depends['files'] = isset($cache->depends['files'])
                        ? array_merge($cache->depends['files'], $depends)
                        : $depends;
        } // end of switch
        return;
    }

}
