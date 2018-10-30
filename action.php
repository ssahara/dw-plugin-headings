<?php
/**
 * Heading PreProcessor plugin for DokuWiki; action component
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Satoshi Sahara <sahara.satoshi@gmail.com>
 */

if(!defined('DOKU_INC')) die();

class action_plugin_headings extends DokuWiki_Action_Plugin {

    // keep toc config parameters
    private $tocminheads, $toptoclevel, $maxtoclevel;

    function __construct() {
        global $conf;
        $this->tocminheads = $conf['tocminheads'];
        $this->toptoclevel = $conf['toptoclevel'];
        $this->maxtoclevel = $conf['maxtoclevel'];
    }

    /**
     * Register event handlers
     */
    function register(Doku_Event_Handler $controller) {
        $controller->register_hook(
            'PARSER_METADATA_RENDER', 'AFTER', $this, '_modifyTableOfContents'
        );
        $controller->register_hook(
            'TPL_TOC_RENDER', 'BEFORE', $this, 'tpl_toc'
        );
        $controller->register_hook(
            'TPL_CONTENT_DISPLAY', 'BEFORE', $this, '_renderHtmlToc'
        );
    }


    /**
     * PARSER_METADATA_RENDER event handler
     *
     * Extends TableOfContents database that holds All headings
     */
    function _modifyTableOfContents(Doku_Event $event) {
        global $ID;

        $toc =& $event->data['current']['description']['tableofcontents'];
        if (!isset($toc)) return;

        $headings = $event->data['current']['plugin']['headings'];
        if (!isset($headings)) return;

        // handler で生成した headings を tableofcontents 互換の toc データベースに変換する
        $headers0 = []; // memory once used hid (title0)
        $headers1 = []; // memory once used hid (new hid)

        foreach ($headings as &$item) {
            // $item = [
            //          'page' => $page, 'pos' => $pos,
            //          'level' => $level, 'title0' => $title0,
            //          'title' => $title, 'xhtml' => $xhtml, 'hid' => $hid,
            //         ];
            // $item を直接更新する
            $item['hid']  = sectionID($item['hid'], $headers1);
            $item['hid0'] = sectionID($item['title0'], $headers0);
            $item['type'] = 'ul';
        }
        unset($item);

        $toc = $headings; // overwrite tableofcontents
        unset($event->data['current']['plugin']['headings']); // remove from metadata


        // 直後にTOCを表示する見だしを探す（空見だしも対象）
        $notoc = !$event->data['current']['internal']['toc'];

        if ( $notoc || (empty($toc))
            || ($this->tocminheads == 0) || (count($toc) < $this->tocminheads)
        ) {
            // ~~NOTOC~~指定ある、あるいは 見だし数が0、
            // または、TOC見出し表示数下限値 の設定を満足しない場合
            // auto TOCは表示しない。 ここで終了
            return;
        }

        $toc_hid0 = 0;
        if ($this->getConf('tocPosition') == 6) {
            // after the First any level heading
            $toc_hid0 = $toc[0]['hid0'];

        } elseif (in_array($this->getConf('tocPosition'), [1,2])) {
            // after the First Level 1 or Level 2 heading
            foreach ($toc as $k => $item) {
                if ($item['level'] == $this->getConf('tocPosition')) {
                    $toc_hid0 = $item['hid0'];
                    break;
                }
            }
        }
        if ($toc_hid0) {
            // TOC 表示位置の見だしが見つかった場合、メタデータとして記録しておく
            // xhtml_rendererの headerメソッドは、title0 を引数とするため、
            // title0ベースの 識別ID hid0 を記録する。
            $event->data['current']['toc']['hid'] = $toc_hid0;
        } else {
            unset($event->data['current']['toc']['hid']);
        }
        return;

    }

    /**
     * TPL_TOC_RENDER event handler
     * Adjust global TOC array according to a given config settings
     *
     * @see also inc/template.php function tpl_toc($return = false)
     */
    function tpl_toc(Doku_Event $event) {
        global $INFO, $ACT, $TOC, $conf;

        if ($ACT == 'admin') {
            $toc = [];
            // try to load admin plugin TOC
            if ($plugin = plugin_getRequestAdminPlugin()) {
                $toc = $plugin->getTOC();
                $TOC = $toc; // avoid later rebuild
            }
            // error_log(' '.$event->name.' admin toc='.var_export($toc,1));
            return $toc;
        }

        $toc = $INFO['meta']['description']['tableofcontents'] ?? [];
        //error_log(' '.$event->name.' info toc='.var_export($INFO['meta']['description']['tableofcontents'],1));
        $notoc = !( $INFO['internal']['toc'] ?? true); // is true if toc should not be displayed

        $tocminheads = $this->tocminheads ?? $conf['tocminheads'];
        $toptoclevel = $this->toptoclevel ?? $conf['toptoclevel'];
        $maxtoclevel = $this->maxtoclevel ?? $conf['maxtoclevel'];

        foreach ($toc as $k => $item) {
            if (empty($item['title'])
                || ($item['level'] < $toptoclevel)
                || ($item['level'] > $maxtoclevel)
            ) {
                unset($toc[$k]);
            }
            $item['level'] = $item['level'] - $toptoclevel +1;
        }
        if ( $notoc || ($tocminheads == 0)
            || (count($toc) < $tocminheads)
        ) {
            $toc = [];
        }

        if ($event->name == 'TPL_TOC_RENDER') {
            if (in_array($this->getConf('tocPosition'), [1,2,6])) {
                $toc = [];
            }
            $event->data = $toc;
            //error_log(' '.$event->name.' toc='.var_export($toc,1));
        } elseif ($event->name == 'TPL_CONTENT_DISPLAY') {
            $html = html_TOC($toc);
            return $html;
        } else {
            return $toc;
        }
    }

    /**
     * TPL_CONTENT_DISPLAY
     * insert XHTML of auto-toc at tocPosition where
     *  0: top of the content (default)
     *  1: after the first level 1 heading
     *  2: after the first level 2 heading
     *  6: after the first heading
     */
    function _renderHtmlToc(Doku_Event $event) {
        global $ID, $ACT, $TOC;
        $debug = strtoupper(get_class($this)).' '.$event->name;  //デバッグ用

        if (!in_array($ACT, ['show', 'preview'])) {
            return;
        }

        if (strpos($event->data, '<!-- TOC_HERE -->') === false) {
            error_log($debug.' ACT='.$ACT.' TOC_HERE not found in page '.$ID);
            return;
        }

        // prepare html of table of content
        $html_toc = $this->tpl_toc($event);

        // replace PLACEHOLDER with html_toc
        $content = $event->data;
        $search  = '<!-- TOC_HERE -->';
        $replace = $html_toc;
        $content = str_replace($search, $replace, $content, $count);

        if ($count > 1) {
            error_log(' '.$s.' '.$event->name.'  wrong? count='.$count.' > 2 in page '.$ID);
            return; // something wrong?, toc must appear once in the page
        }
        $event->data = $content;
    }

}
