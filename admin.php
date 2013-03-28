<?php


if ( !defined( 'DOKU_INC' ) )
	define( 'DOKU_INC', realpath( dirname( __FILE__ ) . '/../../' ) . '/' );

if ( !defined( 'DOKU_PLUGIN' ) )
	define( 'DOKU_PLUGIN', DOKU_INC . 'lib/plugins/' );

require_once( DOKU_PLUGIN . 'admin.php' );



/**
 * All DokuWiki plugins to extend the admin function
 * need to inherit from this class
 */

class admin_plugin_database2 extends DokuWiki_Admin_Plugin
{


	/**
	 * instance providing functionality stuff.
	 *
	 * @var Database2_Admin
	 */

	protected $db;



	/**
	 * return some info
	 */

	public function getInfo()
	{
		return array(
					'author' => 'Thomas Urban',
					'email'  => 'soletan@nihilum.de',
					'date'   => '2009-11-18',
					'name'   => 'database2',
					'desc'   => 'Provides console for querying SQL commands ' .
								'to local SQLite database file',
					'url'    => 'http://wiki.nihilum.de/software:database2',
					);
	}


	/**
	 * handle user request
	 */

	public function handle()
	{
	}


	/**
	 * output appropriate html
	 */

	public function html()
	{

		if ( !$this->getConf( 'console' ) )
		{
			ptln( $this->getLang( 'consoleoff' ) );
			return;
		}



		if ( $this->getConf( 'consoleforcehistory') )
		{
			@session_start();
			$useHistory = true;
		}
		else if ( $useHistory = !headers_sent() )
			session_start();
		else
			ptln( $this->getLang( 'consolesession' ) );



		$db = $this->connect( $_REQUEST['dbfile'] );
		if ( $db )
		{

			if ( $_GET['sectok'] != getSecurityToken() )
				$query = '';
			else
				$query = trim( $_GET['q'] );

			$queryEsc = strtr( $query, array( '<' => '&lt;' ) );


			if ( $useHistory && ( $query !== '' ) )
			{

				if ( !is_array( $_SESSION['DATABASE2_CONSOLE_HISTORY'] ) )
					$_SESSION['DATABASE2_CONSOLE_HISTORY'] = array();

				$HISTORY =& $_SESSION['DATABASE2_CONSOLE_HISTORY'];


				$index = array_search( $query, $HISTORY );
				if ( $index !== false )
					unset( $HISTORY[$index] );

				array_unshift( $HISTORY, $query );

				$HISTORY = array_slice( $HISTORY, 0, 20 );

			}



			$btn = $this->getLang( 'consolebtn' );
			$sqliteLabel = $this->getLang( 'consolesqlitedoc' );

			$dbSelectorLabel = $this->getLang( 'consoledbselector' );

			$helperShortcutsLabel = $this->getLang( 'consolehelpershortcuts' );
			$helperKeys = $this->getLang( 'consolehelperkeys' );
			$helperLocks = $this->getLang( 'consolehelperlocks' );
			$helperLog = $this->getLang( 'consolehelperlog' );
			$helperTables = $this->getLang( 'consolehelpertables' );
			$helperVac = $this->getLang( 'consolehelpervac' );

			$helperTemplatesLabel = $this->getLang( 'consolehelpertemplates' );
			$helperRead = $this->getLang( 'consolehelperread' );
			$helperReadSQL = $this->getLang( 'consolehelperreadsql' );
			$helperEdit = $this->getLang( 'consolehelperedit' );
			$helperEditSQL = $this->getLang( 'consolehelpereditsql' );
			$helperDelete = $this->getLang( 'consolehelperdelete' );
			$helperDeleteSQL = $this->getLang( 'consolehelperdeletesql' );
			$helperAdd = $this->getLang( 'consolehelperadd' );
			$helperAddSQL = $this->getLang( 'consolehelperaddsql' );

			if ( $useHistory )
			{

				$helperHistoryLabel = $this->getLang( 'consolehelperhistory' );

				$history = array();
				if ( is_array( $HISTORY ) )
					foreach ( $HISTORY as $q )
					{
						$qesc = strtr( $q, array( '<' => '&lt;' ) );
						$q    = strtr( $q, array( '"' => '&quot;' ) );
						$history[] = '<option value="' . $q . '">' . $qesc . '&nbsp;&nbsp;</option>';
					}

				$history = implode( "\n", $history );
				$history = <<<EOT
 <div>
  $helperHistoryLabel
  <select name="history" onchange="return db2_console_load(this.options[this.selectedIndex].value);">
   $history
  </select>
 </div>
EOT;

			}

			$sectok = getSecurityToken();



			echo <<<EOT
<script type="text/javascript"><!--
function db2_console_load(query)
{
	with ( document.database2_console.q )
	{
		value = query;
		focus();
	}

	return false;
}
//--></script>
<form action="$_SERVER[PHP_SELF]" method="GET" name="database2_console" id="database2_console">
 <input type="hidden" name="do" value="$_REQUEST[do]" />
 <input type="hidden" name="page" value="$_REQUEST[page]" />
 <input type="hidden" name="id" value="$_REQUEST[id]" />
 <input type="hidden" name="sectok" value="$sectok" />
 <div>
  $dbSelectorLabel <input type="text" name="dbfile" id="dbfile" value="$_REQUEST[dbfile]" size="40" />
 </div>
 <div>
  $helperShortcutsLabel
  <a href="" onclick="return db2_console_load('SELECT * FROM __keys');">$helperKeys</a>
  |
  <a href="" onclick="return db2_console_load('SELECT * FROM __locks');">$helperLocks</a>
  |
  <a href="" onclick="return db2_console_load('SELECT * FROM __log');">$helperLog</a>
  |
  <a href="" onclick="return db2_console_load('SELECT * FROM SQLITE_MASTER');">$helperTables</a>
  |
  <a href="" onclick="return db2_console_load('VACUUM');">$helperVac</a>
 </div>
 <div>
  $helperTemplatesLabel
  <a href="" onclick="return db2_console_load('$helperReadSQL');">$helperRead</a>
  |
  <a href="" onclick="return db2_console_load('$helperEditSQL');">$helperEdit</a>
  |
  <a href="" onclick="return db2_console_load('$helperDeleteSQL');">$helperDelete</a>
  |
  <a href="" onclick="return db2_console_load('$helperAddSQL');">$helperAdd</a>
 </div>
 $history
 <textarea name="q" rows="3" cols="60" style="width: 100%;">$queryEsc</textarea>
 <div>
  <input type="submit" value="$btn" />
  |
  <a href="http://sqlite.org/lang.html" target="sqliteLangDoc">
   $sqliteLabel
  </a>
 </div>
</form>
<script type="text/javascript"><!--
document.database2_console.q.select();
document.database2_console.q.focus();
//--></script>
EOT;

			if ( $query !== '' )
			{

				echo <<<EOT
<div style="padding-top: 1em; margin-top: 1em; border-top: 1px solid #888888;">
EOT;

				try
				{

					$result = $db->getLink()->query( $query );

					if ( $result instanceof PDOStatement )
					{

						$rows  = $result->fetchAll( PDO::FETCH_ASSOC );

						if ( count( $rows ) )
						{

							$first = array_slice( $rows, 0, 1 );
							$cols  = empty( $first ) ? array() : array_keys( $first );

							echo $db->__renderTable( null, $cols, $rows,
													 count( $rows ), count( $rows ),
													 0, null, array(), false,
													 true );

						}
						else
							echo $this->getLang( 'consolegoodresult' );

					}
					else
						var_dump( $result );

				}
				catch ( PDOException $e )
				{
					echo '<div class="error">' . $e->getMessage() . '</div>';
				}

				echo '</div>';

			}
		}
	}


	/**
	 * Connects to local database.
	 *
	 * @return Database2_Admin
	 */

	public function connect( $explicitDBPathname = null )
	{

		if ( !( $this->db instanceof Database2_Admin ) )
		{

			self::includeLib();

			$db = new Database2_Admin( $this );

			$dbFile = trim( $explicitDBPathname );
			if ( $dbFile === '' )
				$dbFile = $_REQUEST['id'];

			if ( $db->connect( $dbFile ) )
				$this->db = $db;

		}

		return $this->db;

	}


	public static function includeLib()
	{

		if ( !class_exists( 'Database2_Admin' ) )
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
