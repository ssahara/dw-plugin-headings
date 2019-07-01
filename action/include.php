<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * Include Plugin:  Display a wiki page within another wiki page
 *
 * Action plugin component, for cache validity determination
 * 
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Christopher Smith <chris@jalakai.co.uk>  
 * @author     Michael Klier <chi@chimeric.de>
 */
if (!defined('DOKU_INC')) die();


/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class action_plugin_headings_include extends DokuWiki_Syntax_Plugin
{
    /**
     * Register event handlers
     */
    public function register(Doku_Event_Handler $controller)
    {
        if (!plugin_isdisabled('include')) return;

        $controller->register_hook('ACTION_SHOW_REDIRECT', 'BEFORE', $this, 'handle_redirect');
        // 
        $controller->register_hook('HTML_EDITFORM_OUTPUT', 'BEFORE',     $this, 'handle_form');
        $controller->register_hook('HTML_CONFLICTFORM_OUTPUT', 'BEFORE', $this, 'handle_form');
        $controller->register_hook('HTML_DRAFTFORM_OUTPUT', 'BEFORE',    $this, 'handle_form');
        $controller->register_hook('HTML_SECEDIT_BUTTON', 'BEFORE', $this, 'handle_secedit_button');
        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, '_hookjs', []);

        // safeindex が有効な場合に実行される
        $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handle_indexer');
        $controller->register_hook('INDEXER_VERSION_GET', 'BEFORE', $this, 'handle_indexer_version');

        // move plugin が有効な場合に実行される
     // $controller->register_hook('PLUGIN_MOVE_HANDLERS_REGISTER', 'BEFORE', $this, 'handle_move_register');

        $controller->register_hook('PARSER_CACHE_USE','BEFORE', $this, '_cache_prepare');
    }


    /**
     * ACTION_SHOW_REDIRECT event handler
     *
     * Modify the data for the redirect when there is a redirect_id set
     */
    public function handle_redirect(Doku_Event $event, $param)
    {
        if (array_key_exists('redirect_id', $_REQUEST)) {
            // Render metadata when this is an older DokuWiki version where
            // metadata is not automatically re-rendered as the page has probably
            // been changed but is not directly displayed
            $versionData = getVersionData();
            if ($versionData['date'] < '2010-11-23') {
                p_set_metadata($event->data['id'], array(), true);
            }
            $event->data['id']    = cleanID($_REQUEST['redirect_id']);
            $event->data['title'] = '';
        }
    }

    /**
     * HTML_EDITFORM_OUTPUT, HTML_CONFLICTFORM_OUTPUT, HTML_DRAFTFORM_OUTPUT
     *
     * Add a hidden input to the form to preserve the redirect_id
     */
    public function handle_form(Doku_Event $event, $param)
    {
        if (array_key_exists('redirect_id', $_REQUEST)) {
            $event->data->addHidden('redirect_id', cleanID($_REQUEST['redirect_id']));
        }
    }

    /**
     * HTML_SECEDIT_BUTTON event handler
     *
     * Handle special section edit buttons for the include plugin to get the current page
     * and replace normal section edit buttons when the current page is different from the
     * global $ID.
     */
    public function handle_secedit_button(Doku_Event $event, $params)
    {
        // stack of included pages in the form ('id' => page, 'rev' => modification time, 'writable' => bool)
        static $page_stack = array();

        global $ID, $lang;

        $data = $event->data;

        switch ($data['target']) {
            case 'plugin_include_start':
                $redirect = true;
            case 'plugin_include_start_noredirect';
                $redirect = $redirect ?? false;
                // handle the "section edits" added by the include plugin
                $fn = wikiFN($data['name']);
                $perm = auth_quickaclcheck($data['name']);
                $isWritable = page_exists($data['name'])
                    ? (is_writable($fn) && $perm >= AUTH_EDIT)
                    : ($perm >= AUTH_CREATE);
                array_unshift($page_stack, array(
                    'id' => $data['name'],
                    'rev' => @filemtime($fn),
                    'writable' => $isWritable,
                    'redirect' => $redirect,
                ));
                break;
            case 'plugin_include_end':
                array_shift($page_stack);
                break;
            case 'plugin_include_editbtn':
                if ($page_stack[0]['writable']) {
                    $params = array('do' => 'edit', 'id' => $page_stack[0]['id']);
                    if ($page_stack[0]['redirect']) {
                        $params['redirect_id'] = $ID;
                    }
                    $event->result  = '<div class="secedit">' . DOKU_LF;
                    $event->result .= html_btn('incledit', $page_stack[0]['id'], '',
                                          $params, 'post',
                                          $data['name'],
                                          $lang['btn_secedit'].' ('.$page_stack[0]['id'].')'
                                      );
                    $event->result .= '</div>' . DOKU_LF;
                }
                break;
            case (count($page_stack) > 0):
                // Special handling for the edittable plugin
                if ($data['target'] == 'table' && !plugin_isdisabled('edittable')) {
                    $edittable =& plugin_load('action', 'edittable_editor') ?? plugin_load('action', 'edittable');
                    $data['name'] = $edittable->getLang('secedit_name');
                }

                if ($page_stack[0]['writable'] && isset($data['name']) && $data['name'] !== '') {
                    [$name , $secid] = [$data['name'], $data['secid']];
                    unset($data['name'], $data['secid']);

                    if ($page_stack[0]['redirect']) $data['redirect_id'] = $ID;

                    $event->result  = '<div class="secedit editbutton_'.$data['target'].' editbutton_'.$secid. '">';
                    $event->result .= html_btn('secedit', $page_stack[0]['id'], '',
                                              array_merge(array('do'  => 'edit',
                                                  'rev' => $page_stack[0]['rev'],
                                                  'summary' => '['.$name.'] '), $data
                                              ), 'post', $name
                                      );
                    $event->result .= '</div>';
                } else {
                    $event->result = '';
                }
                break;
            default:
                return; // return so the event won't be stopped
        }
        $event->preventDefault();
        $event->stopPropagation();
    }

    /**
     * TPL_METAHEADER_OUTPUT event handler
     *
     * Add javascript information to script meta headers
     */
    public function _hookjs(Doku_Event $event)
    {
        $plugin_url = DOKU_REL.'lib/plugins/'.$this->getPluginName();
        $event->data['script'][] = [
            'type' => 'text/javascript',
            'charset' => 'utf-8',
            '_data' => '',
            'src' => $plugin_url.'/js/include_editbutton.js',
        ];
    }


    /**
     * INDEXER_VERSION_GET event handler
     *
     * Add a version string to the index so it is rebuilt
     * whenever the handler is updated or the safeindex setting is changed
     */
    public function handle_indexer_version(Doku_Event $event, $param)
    {
        // check if the feature is enabled at all
        if (!$this->getConf('safeindex')) return;

        $event->data['plugin_include'] = '0.1.safeindex='.$this->getConf('safeindex');
    }

    /**
     * INDEXER_PAGE_ADD event handler
     *
     * Prevents indexing of metadata from included pages that aren't public if enabled
     *
     * @param Doku_Event $event  the event object
     * @param array      $params optional parameters (unused)
     */
    public function handle_indexer(Doku_Event $event, $params)
    {
        global $USERINFO;

        // check if the feature is enabled at all
        if (!$this->getConf('safeindex')) return;

        // is there a user logged in at all? If not everything is fine already
        if (is_null($USERINFO) && !isset($_SERVER['REMOTE_USER'])) return;

        // get the include metadata in order to see which pages were included
        $metadata = p_get_metadata($event->data['page'], 'plugin_headings', METADATA_RENDER_UNLIMITED);
        $all_public = true; // are all included pages public?
        // check if the current metadata indicates that non-public pages were included
        if ($metadata !== null && isset($metadata['include_pages'])) {
            foreach ($metadata['include_pages'] as $page) {
                if (auth_aclcheck($page['id'], '', array()) < AUTH_READ) { // is $page public?
                    $all_public = false;
                    break;
                }
            }
        }

        if (!$all_public) { // there were non-public pages included - action required!
            // backup the user information
            $userinfo_backup = $USERINFO;
            $remote_user = $_SERVER['REMOTE_USER'];
            // unset user information - temporary logoff!
            $USERINFO = null;
            unset($_SERVER['REMOTE_USER']);

            // metadata is only rendered once for a page in one request - thus we need to render manually.
            $meta = p_read_metadata($event->data['page']); // load the original metdata
            $meta = p_render_metadata($event->data['page'], $meta); // render the metadata
            p_save_metadata($event->data['page'], $meta); // save the metadata so other event handlers get the public metadata, too

            $meta = $meta['current']; // we are only interested in current metadata.

            // check if the tag plugin handler has already been called before the include plugin
            $tag_called = isset($event->data['metadata']['subject']);

            // Reset the metadata in the renderer. This removes data from all other event handlers, but we need to be on the safe side here.
            $event->data['metadata'] = array('title' => $meta['title']);

            // restore the relation references metadata
            if (isset($meta['relation']['references'])) {
                $event->data['metadata']['relation_references'] = array_keys($meta['relation']['references']);
            } else {
                $event->data['metadata']['relation_references'] = array();
            }

            // restore the tag metadata if the tag plugin handler has been called before the include plugin handler.
            if ($tag_called) {
                $tag_helper = $this->loadHelper('tag', false);
                if ($tag_helper) {
                    if (isset($meta['subject']))  {
                        $event->data['metadata']['subject'] = $tag_helper->_cleanTagList($meta['subject']);
                    } else {
                        $event->data['metadata']['subject'] = array();
                    }
                }
            }

            // restore user information
            $USERINFO = $userinfo_backup;
            $_SERVER['REMOTE_USER'] = $remote_user;
        }
    }

    /**
     * PLUGIN_MOVE_HANDLERS_REGISTER
     *
     * 要修正
     */
    public function handle_move_register(Doku_Event $event, $params)
    {
        $event->data['handlers']['include_include'] = array($this, 'rewrite_include');
    }

    public function rewrite_include($match, $pos, $state, $plugin, helper_plugin_move_handler $handler)
    {
        $syntax = substr($match, 2, -2); // strip markup
        $replacers = explode('|', $syntax);
        $syntax = array_shift($replacers);
        list($syntax, $flags) = explode('&', $syntax, 2);

        // break the pattern up into its parts
        list($mode, $page, $sect) = preg_split('/>|#/u', $syntax, 3);

        if (method_exists($handler, 'adaptRelativeId')) { // move plugin before version 2015-05-16
            $newpage = $handler->adaptRelativeId($page);
        } else {
            $newpage = $handler->resolveMoves($page, 'page');
            $newpage = $handler->relativeLink($page, $newpage, 'page');
        }

        if ($newpage == $page) {
            return $match;
        } else {
            $result = '{{'.$mode.'>'.$newpage;
            if ($sect) $result .= '#'.$sect;
            if ($flags) $result .= '&'.$flags;
            if ($replacers) $result .= '|'.$replacers;
            $result .= '}}';
            return $result;
        }
    }


    /**
     * PARSER_CACHE_USE event handler
     *
     * prepare the cache object for default _useCache action
     */
    public function _cache_prepare(Doku_Event $event, $param)
    {
        global $conf;

        /* @var cache_renderer $cache */
        $cache =& $event->data;

        if (!isset($cache->page)) return;
        if (!isset($cache->mode) || $cache->mode == 'i') return;

        $metadata = p_get_metadata($cache->page, 'plugin '.$this->getPluginName());
        $instructions    =& $metadata['instructions'] ?? null;
        $include_pages   =& $metadata['include_pages'] ?? null;
        $include_content =& $metadata['include_content'] ?? null;

        if($conf['allowdebug'] && $this->getConf('debugoutput')) {
            dbglog('---- PLUGIN INCLUDE CACHE DEPENDS START ----');
            dbglog($metadata);
            dbglog('---- PLUGIN INCLUDE CACHE DEPENDS END ----');
        }

        if (!isset($instructions, $include_pages, $include_content)) return;

        if (!is_array($include_pages)
            || !is_array($instructions)
            || $include_pages != $this->_get_included_pages_from_meta_instructions($instructions)
            // the include_content url parameter may change the behavior for included pages
            || $include_content != isset($_REQUEST['include_content'])
        ) {
            $cache->depends['purge'] = true; // included pages changed or old metadata - request purge.
            if($conf['allowdebug'] && $this->getConf('debugoutput')) {
                dbglog('---- PLUGIN INCLUDE: REQUESTING CACHE PURGE ----');
                dbglog('---- PLUGIN INCLUDE CACHE PAGES FROM META START ----');
                dbglog($include_pages);
                dbglog('---- PLUGIN INCLUDE CACHE PAGES FROM META END ----');
                dbglog('---- PLUGIN INCLUDE CACHE PAGES FROM META_INSTRUCTIONS START ----');
                dbglog($this->_get_included_pages_from_meta_instructions($instructions));
                dbglog('---- PLUGIN INCLUDE CACHE PAGES FROM META_INSTRUCTIONS END ----');

            }
        } else {
            // add plugin.info.txt to depends for nicer upgrades
            $cache->depends['files'][] = dirname(__FILE__) . '/plugin.info.txt';
            foreach ($include_pages as $page) {
                if ($page['exists']) {
                    $file = wikiFN($page['id']);
                    if (!in_array($file, $cache->depends['files'])) {
                        $cache->depends['files'][] = $file;
                    }
                }
            }
        }
    }

    /**
     * Retrieve the list of all included pages from a list of metadata instructions.
     *
     * このメソッドは RENDER_CACHE_USE event handeler, cache_prepare() からコールされる
     */
    private function _get_included_pages_from_meta_instructions($instructions)
    {
        static $syntax;
        isset($syntax) || $syntax = plugin_load('syntax', 'headings_include');

        $pages = array();
        foreach ($instructions as $ins) {
            $mode      = $ins['mode'];
            $page      = $ins['page'];
            $sect      = $ins['sect'];
            $parent_id = $ins['parent_id'];
            $flags     = $ins['flags'];
            $pages = array_merge($pages, 
                $syntax->_get_included_pages($mode, $page, $sect, $parent_id, $flags)
            );
        }
        return $pages;
    }

}
