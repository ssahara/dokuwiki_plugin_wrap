<?php
/**
 * Div Syntax Component of the Wrap Plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Anika Henke <anika@selfthinker.org>
 */

if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');

class syntax_plugin_wrap_div extends DokuWiki_Syntax_Plugin {
    protected $special_pattern = '<div\b[^>\r\n]*?/>';
    protected $entry_pattern   = '<div\b.*?>(?=.*?</div>)';
    protected $exit_pattern    = '</div>';

    function getType(){ return 'formatting';}
    function getAllowedTypes() { return array('container', 'formatting', 'substition', 'protected', 'disabled', 'paragraphs'); }
    function getPType(){ return 'stack';}
    function getSort(){ return 195; }
    // override default accepts() method to allow nesting - ie, to get the plugin accepts its own entry syntax
    function accepts($mode) {
        if ($mode == substr(get_class($this), 7)) return true;
        return parent::accepts($mode);
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
        $this->Lexer->addSpecialPattern($this->special_pattern,$mode,'plugin_wrap_'.$this->getPluginComponent());
        $this->Lexer->addEntryPattern($this->entry_pattern,$mode,'plugin_wrap_'.$this->getPluginComponent());
    }

    function postConnect() {
        $this->Lexer->addExitPattern($this->exit_pattern, 'plugin_wrap_'.$this->getPluginComponent());
        $this->Lexer->addPattern('[ \t]*={2,}[^\n]+={2,}[ \t]*(?=\n)', 'plugin_wrap_'.$this->getPluginComponent());
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, Doku_Handler $handler) {
        global $conf;
        switch ($state) {
            case DOKU_LEXER_ENTER:
            case DOKU_LEXER_SPECIAL:
                $data = strtolower(trim(substr($match,strpos($match,' '),-1)," \t\n/"));
                return array($state, $data);

            case DOKU_LEXER_UNMATCHED:
                $handler->base($match, $state, $pos);
                break;

            case DOKU_LEXER_MATCHED:
                // we have a == header ==, use the core header() renderer
                // (copied from core header() in inc/parser/handler.php)
                $title = trim($match);
                $level = 7 - min(strspn($title, '='), 6);
                $title = trim($title,'=');
                $title = trim($title);

                // write header without opening or closing section
                $data = array($title, $level, $pos);
                $handler->plugin($data, 'header', $pos, 'wrap_div');

                // close the section edit the header could open
                if ($title && $level <= $conf['maxseclevel']) {
                    $data = null;
                    $handler->plugin($data, 'finishSectionEdit', $pos, 'wrap_div');
                }
                break;

            case DOKU_LEXER_EXIT:
                return array($state, '');

            case 'finishSectionEdit':
                return array($state, '');

            case 'header':
                // $match is array set by handle called with DOKU_LEXER_MATCHED state.
                // The plugin instruction must be converted to call header()
                // during PARSER_HANDLER_DONE event handler.
                return $data = (array)$match;
        }
        return false;
    }

    /**
     * Create output
     */
    function render($mode, Doku_Renderer $renderer, $indata) {
        static $type_stack = array ();

        if (empty($indata)) return false;
        list($state, $data) = $indata;

        if($mode == 'xhtml'){
            /** @var Doku_Renderer_xhtml $renderer */
            switch ($state) {
                case DOKU_LEXER_ENTER:
                    $sectionEditStartData = ['target' => 'plugin_wrap_start'];
                    $sectionEditEndData = ['target' => 'plugin_wrap_end'];
                    if (!defined('SEC_EDIT_PATTERN')) {
                        // backwards-compatibility for Frusterick Manners (2017-02-19)
                        $sectionEditStartData = 'plugin_wrap_start';
                        $sectionEditEndData = 'plugin_wrap_end';
                    }
                    // add a section edit right at the beginning of the wrap output
                    $renderer->startSectionEdit(0, $sectionEditStartData);
                    $renderer->finishSectionEdit();
                    // add a section edit for the end of the wrap output. This prevents the renderer
                    // from closing the last section edit so the next section button after the wrap syntax will
                    // include the whole wrap syntax
                    $renderer->startSectionEdit(0,  $sectionEditEndData);
                    // no break

                case DOKU_LEXER_SPECIAL:
                    $wrap = $this->loadHelper('wrap');
                    $attr = $wrap->buildAttributes($data, 'plugin_wrap');

                    $renderer->doc .= '<div'.$attr.'>';
                    if ($state == DOKU_LEXER_SPECIAL) $renderer->doc .= '</div>';
                    break;

                case DOKU_LEXER_EXIT:
                    $renderer->doc .= '</div>';
                    // no break;

                case 'finishSectionEdit':
                    $renderer->finishSectionEdit();
                    break;
            }
            return true;
        }
        if($mode == 'odt'){
            switch ($state) {
                case DOKU_LEXER_ENTER:
                    $wrap = plugin_load('helper', 'wrap');
                    array_push ($type_stack, $wrap->renderODTElementOpen($renderer, 'div', $data));
                    break;

                case DOKU_LEXER_EXIT:
                    $element = array_pop ($type_stack);
                    $wrap = plugin_load('helper', 'wrap');
                    $wrap->renderODTElementClose ($renderer, $element);
                    break;
            }
            return true;
        }
        return false;
    }
}
