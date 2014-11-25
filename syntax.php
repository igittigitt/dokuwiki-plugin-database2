<?php


/**
 * Integration of database2 into DokuWiki as syntax plugin
 *
 * This file is part of DokuWiki plugin database2 and is available under
 * GPL version 2. See the following URL for a copy of this license!
 *
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 *
 * @author Thomas Urban <soletan@nihilum.de>
 * @version 0.1
 * @copyright GPLv2
 *
 */


// must be run within DokuWiki
if ( !defined( 'DOKU_INC' ) )
	die();

if ( !defined( 'DOKU_PLUGIN' ) )
	define( 'DOKU_PLUGIN', DOKU_INC . 'lib/plugins/' );

require_once( DOKU_PLUGIN . 'syntax.php' );


/**
 * Class providing extension of DokuWiki syntax to actually integrate
 * database2 plugin.
 *
 * @author Thomas Urban <soletan@nihilum.de>
 * @version 0.1
 * @copyright GPLv2
 *
 */

class syntax_plugin_database2 extends DokuWiki_Syntax_Plugin
{

	const tagName = 'database';


	protected $dbName;

	protected $tableName;

	protected $options = array();


	public function getInfo()
	{
		return confToHash(dirname(__FILE__).'/plugin.info.txt');
	}

	public function getType()
	{
		return 'formatting';
	}

	public function getSort()
	{
		return 158;
	}

	public function connectTo( $mode )
	{
		$tag = self::tagName;
		$this->Lexer->addEntryPattern( "<$tag.*?>(?=.*?</$tag>)", $mode,
									   'plugin_database2' );
	}

	public function postConnect()
	{
		$tag = self::tagName;
		$this->Lexer->addExitPattern( "</$tag>", 'plugin_database2' );
	}

	public function handle( $match, $state, $pos, &$handler )
	{

		switch ( $state )
		{

			case DOKU_LEXER_ENTER :
				// extract tag's attributes
				$temp = trim( substr( $match, strlen( self::tagName ) +1, -1 ));

				self::includeLib();

				$nameMap = array(
								'db'     => 'database',
								'dsn'    => 'database',
								'file'   => 'database',
								'host'   => 'database',
								'server' => 'database',
								'slot'   => 'auth',
								);

				// parse tag's attributes
				$pos  = 0;
				$args = array();

				while ( $pos < strlen( $temp ) )
				{

					$arg = Database2::parseAssignment( $temp, $pos );

					if ( $arg === false )
						return false;

					if ( is_array( $arg ) )
					{

						list( $name, $value ) = $arg;

						$mapped = $nameMap[$name];
						if ( $mapped )
							$name = $mapped;

						if ( ( $value === true ) && !isset( $args['table'] ) )
						{
							$args['table'] = $name;
							unset( $args[$name] );
						}
						else
							$args[$name] = $value;

					}
					else
						break;

				}

				return array( $state, $args );

			case DOKU_LEXER_UNMATCHED :
				return array( $state, $match );

			case DOKU_LEXER_EXIT :
				return array( $state, '' );

		}

		return array();

	}

	public function render( $mode, &$renderer, $data )
	{

		if ( $mode == 'xhtml' )
		{

			list( $state, $args ) = $data;

			switch ( $state )
			{

				case DOKU_LEXER_ENTER :
					$this->tableName = trim( $args['table'] );
					$this->dbName    = trim( $args['database'] );

					if ( $this->dbName === '' )
						// missing explicit selection of database
						// --> choose file according to current page's namespace
						$this->dbName = getID();

					$this->options = $args;

					break;

				case DOKU_LEXER_UNMATCHED :

					self::includeLib();

					$db = new Database2( $renderer, $this );

					if ( $db->connect( $this->dbName, $this->options['auth'] ) )
						$db->process( $this->tableName, $args, $this->options );

					break;

				case DOKU_LEXER_EXIT :
					break;

			}

			return true;

		}
		else if ( $mode === 'metadata' )
		{
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

	public static function includeLib()
	{

		if ( !class_exists( 'Database2' ) )
		{

			$libFile = dirname( __FILE__ ) . '/database2.php';

			// support working with development version if available and
			// selected to enable development in a production wiki
			// (as used on wiki.nihilum.de)
			if ( is_file( dirname( __FILE__ ) . '/database2.dev.php' ) )
			{

				@session_start();

				if ( $_GET['use_dev'] )
					$_SESSION['useDevIP'] = $_SERVER['REMOTE_ADDR'];

				if ( $_GET['use_prod'] )
					unset( $_SESSION['useDevIP'] );

				if ( $_SESSION['useDevIP'] )
					if ( $_SESSION['useDevIP'] == $_SERVER['REMOTE_ADDR'] )
						$libFile = dirname( __FILE__ ) . '/database2.dev.php';

			}

			{ include_once( $libFile ); }

		}
	}
}

