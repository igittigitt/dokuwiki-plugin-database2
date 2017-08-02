<?php
/**
 * DokuWiki Plugin database2 (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Oliver Geisen <oliver@rehkopf-geisen.de>
 * @author  Thomas Urban <soletan@nihilum.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

class syntax_plugin_database2 extends DokuWiki_Syntax_Plugin {

	protected $dbName;

	protected $tableName;

	protected $options = array();


    /**
     * @return string Syntax mode type
     */
    public function getType() {
        return 'substition';
    }

    /**
     * @return string Paragraph type
     */
    public function getPType() {
        return 'block';
    }

    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort() {
        return 158;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode) {
        $this->Lexer->addEntryPattern("<database2.*?>(?=.*?</database2>)", $mode, 'plugin_database2');
    }

    public function postConnect() {
        $this->Lexer->addExitPattern('</database2>','plugin_database2');
    }

    /**
     * Handle matches of the database2 syntax
     *
     * @param string          $match   The match of the syntax
     * @param int             $state   The state of the handler
     * @param int             $pos     The position in the document
     * @param Doku_Handler    $handler The handler
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        switch ( $state )
        {
            // extract tag's attributes
            case DOKU_LEXER_ENTER :
                $temp = trim(substr($match, strlen('database2')+1, -1)); # isolate options (if any)
                $nameMap = array(
                    'db'     => 'database',
                    'dsn'    => 'database',
                    'file'   => 'database',
                    'host'   => 'database',
                    'server' => 'database',
                    'slot'   => 'auth',
                );
                $pos  = 0;
                $args = array();
                $this->load_database2();
                while($pos < strlen($temp)) {
                    $arg = Database2::parseAssignment($temp, $pos);
                    if ($arg === false) {
                        return false;
                    }
                    if (is_array($arg)) {
                        list($name, $value) = $arg;
                        $mapped = $nameMap[$name];
                        if ($mapped) {
                            $name = $mapped;
                        }
                        if (($value === true) && ! isset($args['table'])) {
                            $args['table'] = $name;
                            unset($args[$name]);
                        } else {
                            $args[$name] = $value;
                        }
                    } else {
                        break;
                    }
                }
                return array($state, $args);

            case DOKU_LEXER_UNMATCHED :
                return array($state, $match);

            case DOKU_LEXER_EXIT :
                return array($state, '');
        }
        return array();
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string         $mode      Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer  $renderer  The renderer
     * @param array          $data      The data from the handler() function
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data) {
        if ($mode == 'xhtml') {
            list($state, $args) = $data;
            switch ($state) {
                case DOKU_LEXER_ENTER :
                    $this->tableName = trim($args['table']);
                    $this->dbName    = trim($args['database']);
                    if ($this->dbName == '') {
                        // missing explicit selection of database
                        // --> choose file according to current page's namespace
                        $this->dbName = getID();
                    }
                    $this->options = $args;
                    break;

                case DOKU_LEXER_UNMATCHED :
                    $this->load_database2();
                    $db = new Database2($renderer, $this);
                    if ($db->connect($this->dbName, $this->options['auth'])) {
                        $db->process( $this->tableName, $args, $this->options );
                    }
                    break;

                case DOKU_LEXER_EXIT :
                    break;
            }
            return true;
        }
        elseif ($mode == 'metadata') {
            // metadata renderer tries to detect change of content to
            // support page caching ... disable by providing random meta data
            /**
            * @todo implement better cache control here
            */
            $renderer->doc .= uniqid( mt_rand(), true );
            return true;
        }
        return false;
    }

    /**
     * Include library
     */
    private function load_database2() {
        global $INFO;

        if ( ! class_exists('Database2')) {
            if (isset($INFO['user']) && $this->getConf('develusers')) {
                $ua = explode(',',trim($this->getConf('develusers')));
                if (in_array($INFO['user'], $ua) && is_file(dirname(__FILE__) . '/database2.dev.php')) {
                    $libFile = dirname(__FILE__) . '/database2.dev.php';
                    msg($this->getLang('usingdevel'), 2);
                }
            }
            else {
                $libFile = dirname(__FILE__) . '/database2.php';
            }
            require_once($libFile);
        }
    }
}

// vim:ts=4:sw=4:et:
