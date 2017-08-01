<?php


/**
 * Database interface implementation
 *
 * This file is part of DokuWiki plugin database2 and is available under
 * GPL version 2. See the following URL for a copy of this license!
 *
 * http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 *
 * @author Thomas Urban <soletan@nihilum.de>
 * @version 0.2
 * @copyright GPLv2
 *
 */


if ( !defined( 'DB2_PATH' ) )
	define( 'DB2_PATH', 'lib/plugins/database2/' );


/**
 * Implementation of database access feature.
 *
 * This class is integrated into DokuWiki syntax extension in file syntax.php
 * and provides the plugin's actual features like database interaction, table
 * browsing, data lookup and input ...
 *
 * @author Thomas Urban <soletan@nihilum.de>
 * @version 0.2
 * @copyright GPLv2
 *
 */

class Database2
{

	const maxSurroundingPagesCount = 3;

	const maxUploadSize = 2097152;	// 2 MiB


	protected static $ioIndices = array();


	/**
	 * Renderer for producing any output.
	 *
	 * @var Doku_Renderer_xhtml
	 */

	public $renderer;


	/**
	 * Link to database of current namespace.
	 *
	 * @var PDO
	 */

	protected $db;


	/**
	 * Name of driver indicating type of DB currently connected to.
	 *
	 * @var string
	 */

	private $driver;


	/**
	 * Name of table managed by current instance.
	 *
	 * @var string
	 */

	protected $table = null;


	/**
	 * Meta information on/definition of current table as provided in tag value
	 *
	 * @var array
	 */

	protected $meta = array();


	/**
	 * Options additionally provided in opening tag's attributes.
	 *
	 * @var array
	 */

	protected $options = array();


	/**
	 * I/O-Index assigned for use on acting on table managed by current
	 * instance.
	 *
	 * @var integer
	 */

	protected $ioIndex = null;


	/**
	 * input data
	 *
	 * @var array
	 */

	private $input = null;


	/**
	 * DSN used to connect to database server
	 *
	 * @var string
	 */

	private $dsn = null;


	/**
	 * Name of slot in site configuration containing authentication data.
	 *
	 * @var string
	 */

	private $authSlot = null;


	/**
	 * Reference to database syntax plugin object integrating this instance of
	 * Database2.
	 *
	 * @var DokuWiki_Syntax_Plugin
	 */

	protected $integrator = null;


	/**
	 * Page ID explicitly selected to use in current instance.
	 *
	 * This is used to supercede ID returned by DokuWiki's getID() and is
	 * required in media.php using this class in a faked context.
	 */

	protected $explicitPageID = null;



	public function __construct( Doku_Renderer $renderer,
								 DokuWiki_Syntax_Plugin $integrator )
	{

		$this->renderer   = $renderer;
		$this->db         = null;
		$this->integrator = $integrator;

		$this->renderer->nocache();

	}


	/**
	 * Detects whether provided name is a valid name for a table or column.
	 *
	 * @param string $in name to test
	 * @return boolean true if provided name might be used for tables/columns
	 */

	public static function isValidName( $in )
	{
		return preg_match( '/^[_a-z][_a-z0-9]+$/i', $in );
	}


	/**
	 * Retrieves open link to current database file or null if not connected.
	 *
	 * @return PDO
	 */

	public function getLink()
	{
		return $this->db;
	}


	/**
	 * Retrieves configuration setting using integrator's interface.
	 *
	 * @param string $name name of setting to retrieve
	 * @return mixed retrieved configuration setting
	 */

	public function getConf( $name )
	{
		global $conf;


		if ( $this->integrator instanceof DokuWiki_Syntax_Plugin )
		{

			$value = $this->integrator->getConf( $name, null );
			if ( is_null( $value ) )
				if ( !is_null( $conf[$name] ) )
					$value = $conf[$name];

			return $value;

		}


		// fix for accessing configuration in media.php
		if ( isset( $conf['plugin']['database2'][$name] ) )
			return $conf['plugin']['database2'][$name];

		return $conf[$name];

	}


	/**
	 * Retrieves localized string.
	 *
	 * @param string $name name of localized string
	 * @return mixed retrieved localized string
	 */

	public function getLang( $name )
	{

		if ( $this->integrator instanceof DokuWiki_Syntax_Plugin )
			return $this->integrator->getLang( $name );


		// fix for accessing strings in media.php
		if ( !is_array( $this->integrator ) )
		{

			$lang = array();

			@include( dirname( __FILE__ ) . '/lang/en/lang.php' );
			if ( $GLOBALS['conf']['lang'] != 'en' )
				@include( dirname( __FILE__ ) . '/lang/' .
						  $GLOBALS['conf']['lang'] . '/lang.php' );

			$this->integrator = $lang;

		}


		return $this->integrator[$name];

	}


	/**
	 * Retrieves index to be used for parameters/fields passed in I/O on
	 * currently processed integration of a table in current page.
	 *
	 * The current method is quite unstable, at least on editing arrangement
	 * of database2 instances in a page, however it should work in a production
	 * environment.
	 *
	 * @return integer numeric index
	 */

	protected function getIndex()
	{

		if ( is_null( $this->table ) )
			throw new Exception( 'getIndex: missing name of managed table' );

		if ( is_null( $this->ioIndex ) )
			$this->ioIndex = self::$ioIndices[$tableName]++;

		return $this->ioIndex;

	}


	/**
	 * Retrieves ID of current DokuWiki page.
	 *
	 * @return string
	 */

	protected function getPageID()
	{

		if ( !is_null( $this->explicitPageID ) )
			return $this->explicitPageID;

		return getID();

	}


	/**
	 * Allocates separate section in session data for state of current
	 * table instance.
	 *
	 * @return array
	 */

	protected function &getSession()
	{
		if ( !is_array( $_SESSION['database2'] ) )
			$_SESSION['database2'] = array();

		$id = $this->getPageID();


		// if current page's source has changed ...
		$dates = p_get_metadata( $id, 'date' );
		if ( is_array( $_SESSION['database2'][$id] ) )
			if ( $_SESSION['database2'][$id]['onRevision'] != $dates['modified'] )
				// ... it's related session-based data is dropped
				unset( $_SESSION['database2'][$id] );


		if ( !is_array( $_SESSION['database2'][$id] ) )
			$_SESSION['database2'][$id] = array(
												'onRevision' => $dates['modified'],
												'tables'     => array(),
												);

		$index = $this->getIndex();
		if ( !is_array( $_SESSION['database2'][$id]['tables'][$index] ) )
			$_SESSION['database2'][$id]['tables'][$index] = array();


		return $_SESSION['database2'][$id]['tables'][$index];

	}


	/**
	 * Allocates separate section in session data for temporary content of
	 * single-record editor.
	 *
	 * @return array
	 */

	protected function &getEditorSession()
	{

		$session =& $this->getSession();

		if ( !is_array( $session['editors'] ) )
			$session['editors'] = array();

		return $session['editors'];

	}


	/**
	 * Renders provided HTML code replacing database tag in current Wiki page.
	 *
	 * @param string $code HTML code to render
	 */

	protected function render( $code )
	{
		$this->renderer->doc .= strval( $code );
	}


	/**
	 * Connects to database (external server, local SQLite DB file).
	 *
	 * @param string $dbPath database selector
	 * @param string $authConfigSlot name of slot in site config containing
	 *                               authentication data
	 * @return boolean true on success, false on failure
	 */

	public function connect( $dbPath, $authConfigSlot = null  )
	{

		$dbPath = trim( $dbPath );
		if ( $dbPath[0] == '@' )
			$dsn = substr( $dbPath, 1 );
		else if ( ( $dbPath[0] == '/' ) && !self::getConf( 'useslash' ) &&
				  is_dir( dirname( $dbPath ) ) &&
				  !preg_match( '#(\.\.)|(^\/(etc)\/)#', $dbPath ) )
			$dsn = 'sqlite:' . $dbPath;
		else
			$dsn = 'sqlite:' . metaFN( $dbPath, '.db' );


		try
		{

			// read username/password for authentication from optionally
			// selected slot in site's configuration

			if ( $authConfigSlot )
			{

				$username = $password = '';

				foreach ( explode( "\n", $this->getConf( 'authSlots' ) ) as $line )
				{

					$line = trim( $line );

					if ( ( $line[0] == '#' ) || ( ( $line[0] == '/' ) &&
						 ( $line[1] == '/' ) ) || ( $line === '' ) )
						// skip comments and empty lines
						continue;


					// parse assignment
					$pos  = 0;
					$temp = self::parseAssignment( $line, $pos );

					if ( !is_array( $temp ) )
						continue;

					list( $name, $value ) = $temp;

					if ( strcasecmp( $name, $authConfigSlot ) )
						// not related to current authentication slot
						continue;


					// split value into username and password
					$value = trim( $value );
					$sep   = strcspn( $value, ':' );

					$username = trim( substr( $value, 0, $sep ) );
					$password = trim( substr( $value, $sep + 1 ) );

					// done ...
					break;

				}


				if ( $username === '' )
					unset( $username, $password );
				else if ( $password === '' )
					unset( $password );

			}
			else
				unset( $username, $password );


			// connect to database
			$this->db = new PDO( $dsn, $username, $password );

			// request throwing exceptions on failure
			$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

			// cache used driver name
			$this->driver = strtolower( trim( $this->db->getAttribute(
													PDO::ATTR_DRIVER_NAME ) ) );


			if ( strpos( $this->driver, 'mysql' ) !== false )
				// ensure to use proper encoding on talking to MySQL RDBMSs
				// NOTE: according to server setup this may result in UTF-8 bytes
				//       being UTF-8 encoded, thus resulting in usual "garbage"
				// TODO: add option for selecting whether using UTF8-mapping here
				$this->db->query( 'SET NAMES UTF8' );


			// store how to connect to database for integrated retrieval of files
			$this->dsn      = $dsn;
			$this->authSlot = $authConfigSlot;


			return true;

		}
		catch ( PDOException $e )
		{

			$this->render( sprintf( $this->getLang( 'nodblink' ), $e->getMessage() ) );

			$this->db = $this->driver = null;

			return false;

		}
	}


	/**
	 * Reads all available input data extracting values related to this plugin.
	 *
	 * @return array set of input data related to current plugin
	 */

	protected function getInput()
	{

		if ( is_null( $this->input ) )
		{

			$index = $this->getIndex();

			$this->input = array();

			$matchingSecTok = ( $_REQUEST['sectok'] == getSecurityToken() );

			foreach ( $_REQUEST as $name => $value )
				if ( preg_match( '/^db2do(.+?)(_[xy])?$/i', $name, $matches ) )
					if ( $matchingSecTok || ( $_GET[$name] && preg_match( '/^(cmd|opt)/i', $matches[1] ) ) )
						if ( is_null( $this->input[$matches[1]] ) )
							if ( !is_array( $value ) || !is_null( $value[$index] ) )
								$this->input[$matches[1]] = is_array( $value ) ? $value[$index] : $value;

		}


		return $this->input;

	}


	/**
	 * Retrieves meta information on columns.
	 *
	 * @return array
	 */

	public function getColumnsMeta( $ignoreMissingMeta = false )
	{

		if ( !is_array( $this->meta ) || empty( $this->meta ) )
		{

			$session =& self::getSession();
			if ( is_array( $session['definition'] ) )
				$this->meta = $session['definition'];
			else if ( $ignoreMissingMeta )
				return array();	// don't store permanently ...
			else
				throw new Exception( $this->getLang( 'nocolmeta' ) );

		}


		return $this->meta;

	}


	/**
	 * Renders name of form element to conform with input parser above.
	 *
	 * @param string $name internal name of element
	 * @param index $rowid optional rowid element is related to
	 * @return string external name of form element
	 */

	protected function varname( $name, $rowid = null )
	{
		return 'db2do' . $name . ( $rowid ? $rowid : '' ) . '[' .
				$this->getIndex() . ']';
	}


	/**
	 * Processes occurrence of database tag in a wiki page.
	 *
	 * The tag's value (stuff between opening and closing tag) is passed in
	 * $code.
	 *
	 * @param string $table name of table to work with
	 * @param string $code code found between opening and closing tag
	 * @param array $options additional options provided in tag attributes
	 */

	public function process( $table, $code, $options )
	{

		// wrap all action on database in one exception handler
		try
		{

			// check whether or not database tags are enabled on this page
			if ( !$this->getConf( 'enableallpages' ) )
			{

				$patterns = explode( "\n", trim( $this->getConf( 'enablepages' ) ) );
				$enabled  = false;

				$pageID   = $this->getPageID();

				foreach ( $patterns as $pattern )
				{

					$pattern = trim( $pattern );
					if ( preg_match( '#^/.+/\w*$#', $pattern ) )
						$match = preg_match( $pattern, $pageID );
					else
						$match = fnmatch( $pattern, $pageID );

					if ( $match )
					{
						$enabled = true;
						break;
					}
				}

				if ( !$enabled )
				{
					// use of database tag is disabled

					$this->render( '<div class="database2-disabled">' .
								   $this->getLang( 'tagdisabled' ) . '</div>' );

					return;

				}
			}



			// normalize/validate table name
			$table = preg_replace( '/[^\w]/', '_', trim( $table ) );

			if ( in_array( $table, array( '__keys', '__locks', '__log', ) ) )
				throw new Exception( $this->getLang( 'restabnames' ) );

			$this->table = $table;

			// select subset of input parameters
			$this->ioIndex = null;		// drop to re-obtain new I/O index next
			$index = $this->getIndex();	// but ensure to obtain at all ...

			// install set of options
			$this->options = is_array( $options ) ? $options : array();

			if ( trim( $this->options['mayview'] ) === '' )
				$this->options['mayview'] = '@ALL';

			if ( trim( $this->options['mayinspect'] ) === '' )
				$this->options['mayinspect'] = '@ALL';

			$this->options['view'] = trim( $this->options['view'] );
			if ( !$this->getConf( 'customviews' ) )
				$this->options['view'] = '';
			else if ( !preg_match( '/^SELECT\s/i', trim( $this->options['view'] ) ) )
				$this->options['view'] = '';

			$this->options['wikimarkup'] = self::asBool( $this->options['wikimarkup'] );
			$this->options['simplenav']  = self::asBool( $this->options['simplenav'] );

			if ( ctype_digit( trim( $this->options['rowsperpage'] ) ) )
			{

				$state =& $this->getSession();
				if ( !is_integer( $state['num'] ) )
					$state['num'] = intval( $this->options['rowsperpage'] );

			}

			// parse code for contained definitions
			$this->parseDefinition( $code );


			try
			{

				// look for available action to perform on selected table

				// support preventing CSRF ...
				foreach ( $this->getInput() as $key => $dummy )
				{

					if ( !preg_match( '/^cmd([a-z]+)(\d*)(_x)?$/i', $key, $matches ) )
						continue;

					$action = strtolower( trim( $matches[1] ) );

					$rowid = intval( $matches[2] );
					if ( $rowid )
						$rowACL = $this->getRowACL( $rowid );
					else
						$rowid = $rowACL = null;


					if ( $action === 'reset' )
					{
						$state =& $this->getSession();
						$state = array();
						continue;
					}


					if ( !$this->isAuthorizedMulti( $rowACL, $this->options, 'may'.$action ) )
					{
						// user isn't authorized to perform this action
						$this->render( '<div class="error">' .
									   sprintf( $this->getLang( 'accessdenied' ),
									   $action, $this->table ) . '</div>' );
						continue;
					}


					if ( ( $this->getSingleNumericPrimaryKey() !== false ) ||
						 in_array( $action, array( 'drop', ) ) )
						// perform optionally requested action
						switch ( $action )
						{

							case 'inspect' :// show record details (read-only)
							case 'insert' :	// show record editor to insert
							case 'edit' :	// show record editor to adjust
								do
								{

									if ( ( $action == 'insert' ) && $rowid )
									{
										// insert record starting with duplicate
										// of existing one ...

										if ( !$this->isAuthorizedMulti( $rowACL, $this->options, 'mayinspect' ) )
										{
											// user isn't authorized to perform this action
											$this->render( '<div class="error">' .
														   sprintf( $this->getLang( 'accessdenied' ),
														   $action, $this->table ) . '</div>' );
											break;
										}

										// use duplicate of selected record
										$duplicateRowID = $rowid;

										// but don't overwrite it!
										$rowid = null;

									}
									else
										$duplicateRowID = null;


									// invoke editor/single record view
									$readonly = ( $action == 'inspect' );
									$result   = $this->editRecord( $rowid, $readonly,
																   $duplicateRowID,
																   $rowACL );
									if ( !$result )
										// skip rendering table, rendered single
										return;

									if ( is_integer( $result ) )
									{
										// switch to selected record
										$rowid = $result;
										continue;
									}

									break;

								}
								while ( true );

								break;

							case 'delete' :
								$this->deleteRecord( $rowid );
								break;

							case 'drop' :
								$this->dropTable();
								break;

							default :
								$method = array( &$this, '__handle_'.$action );
								if ( is_callable( $method ) )
									if ( !call_user_func( $method, $rowid ) )
										return;

						}
				}


				if ( !$this->exists( $this->table ) )
					// (re-)create table as it is missing (e.g. after dropping)
					$this->createTable();



				/*
				 * finally render table
				 */

				// check user's authorization to view table
				if ( $this->isAuthorized( $this->options['mayview'] ) )
					// user may view table
					$this->showTable( true, false, false, $this->options['view'] );

			}
			catch ( PDOException $e )
			{
				throw new Exception( sprintf( $this->getLang( 'badinteraction' ),
									 $e->getMessage(), $e->getLine() ) );
			}

		}
		catch ( Exception $e )
		{

			$this->render( '<div class="error">' .
							sprintf( $this->getLang( 'deferror' ),
							$e->getMessage(), $e->getLine(), $e->getFile() ) .
							'</div>' );

			$resetCmd = $this->varname( 'cmdreset' );
			$viewCmd  = $this->varname( 'view' );

			$btnSession = $this->getLang( 'btnResetSession' );
			$btnTable   = $this->getLang( 'btnViewTable' );

			$this->render( $this->wrapInForm( <<<EOT
<input type="submit" name="$resetCmd" value="$btnSession" />
<input type="submit" name="$viewCmd" value="$btnTable" />
EOT
							) );

		}
	}


	protected function getACLCol()
	{

		foreach ( $this->meta as $name => $def )
			if ( $def['isColumn'] && ( $def['format'] == 'acl' ) )
				return $name;

		return null;

	}


	protected function getRowACL( $rowid )
	{

		$session =& $this->getSession();

		if ( !is_array( $session['rowACLs'] ) )
			$session['rowACLs'] = array();

		if ( !isset( $session['rowACLs'][$rowid] ) )
		{

			$aclName  = $this->getACLCol();
			$idColumn = $this->getSingleNumericPrimaryKey();

			if ( $aclName && $idColumn )
			{

				$sql = sprintf( 'SELECT %s FROM %s WHERE %s=?', $aclName,
								$this->table, $idColumn );

				$st = $this->db->prepare( $sql );
				if ( !$st )
					throw new PDOException( $this->getLang( 'aclprepare' ) );

				if ( !$st->execute( array( $rowid ) ) )
					throw new PDOException( $this->getLang( 'aclexecute' ) );

				$row = $st->fetch( PDO::FETCH_NUM );

				$st->closeCursor();


				$session['rowACLs'][$rowid] = trim( $row[0] );

			}
		}


		return $session['rowACLs'][$rowid] ? $session['rowACLs'][$rowid] : null;

	}


	protected function dropRowACL( $rowid )
	{

		$session =& $this->getSession();

		if ( $session['rowACLs'][$rowid] )
			unset( $session['rowACLs'] );

	}


	/**
	 * Creates managed table on demand.
	 *
	 */

	protected function createTable()
	{

		if ( empty( $this->meta ) )
			throw new Exception( $this->getLang( 'defmissing' ) );

		// extract all column definitions
		$cols = array_map( create_function( '$a','return $a[definition];' ),
						   $this->meta );

		// compile CREATE TABLE-statement using linebreaks as some versions
		// of SQLite engines cache it for schema representation, thus improving
		// human-readability ...
		$sql = "CREATE TABLE {$this->table}\n(\n\t" . implode( ",\n\t", $cols ).
				"\n)";


		if ( $this->db->query( $sql ) === false )
			throw new PDOException( sprintf( $this->getLang( 'nocreatetable' ),
									$this->table ) );


		$this->log( 'create', $this->table );

	}


	/**
	 * Renders single record for editing (or inspecting if $readOnly is true).
	 *
	 * @param integer $rowid unique numeric ID of record to edit/inspect
	 * @param boolean $readOnly if true, the record is rendered read-only
	 * @param integer $duplicateOf unique numeric ID of record to duplicate
	 * @return boolean if true, the table/list shouldn't be rendered
	 */

	protected function editRecord( $rowid, $readOnly, $duplicateOf = null,
								   $rowACL = null )
	{

		$ioIndex  = $this->getIndex();
		$input    = $this->getInput();
		$idColumn = $this->getSingleNumericPrimaryKey();

		$isNew    = !$rowid || $duplicateOf;



		/**
		 * Obtain lock for exclusively accessing selected record
		 */

		if ( $rowid && !$readOnly && !$this->obtainLock( $this->table, $rowid ) )
		{
			$this->render( '<div class="error">' . $this->getLang( 'reclocked' ) . '</div>' );
			return true;
		}



		/*
		 * prepare session to contain data specific to this editor
		 */

		$state =& $this->getSession();
		$store =& $this->getEditorSession();

		$errors = array();



		/*
		 * process input data updating record and handle contained commands
		 */

		if ( $input && ( $input['____single'] === md5( $rowid ) ) )
		{

			// select result to return depending on selected navigation mode
			if ( $input['____nav'] )
			{

				if ( $input['____nav'][0] == 'P' )
					$state['nav'] = 'previous';
				else
					$state['nav'] = 'next';

				$result = intval( substr( $input['____nav'], 1 ) );
				if ( !$result )
					$result = true;

			}
			else
			{
				unset( $state['nav'] );
				$result = true;
			}



			if ( $input['____cancel'] )
			{
				// cancel editing record

				if ( $rowid && !$readOnly && !$this->releaseLock( $this->table, $rowid ) )
					$this->render( '<div class="error">' . $this->getLang( 'editnorelease' ) . '</div>' );

				// drop content of current editor session
				$store = array();

				return $result;

			}



			/*
			 * validate input data and store in session
			 */

			if ( !$readOnly )
				foreach ( $this->meta as $column => $def )
					if ( $def['isColumn'] && ( $column != $idColumn ) )
					{

						$mayEdit = !$def['options']['mayedit'] || $this->isAuthorizedMulti( $rowACL, $def['options'], 'mayedit' );
						if ( !$mayEdit )
							continue;

						// user may edit this column ...
						$mayView = !$def['options']['mayview'] || $this->isAuthorizedMulti( $rowACL, $def['options'], 'mayview' );
						if ( !$mayView )
							// ... but mustn't view it ...
							if ( $rowid )
								// it's an existing record -> reject editing field
								continue;
							// ELSE: editing new records doesn't actually imply
							//       viewing something existing in this field

						$error = $this->checkValue( $rowid, $column, $input['data'.$column], $store[$column], $def );
						if ( $error && $column )
						{
							// something's wrong, but if it's a typo it's better
							// user may change his previous input next rather
							// than starting it all over again ...
							// --> store even malformed input in editor session
							$store[$column]  = $input['data'.$column];
							$errors[$column] = $error;
						}

					}



			if ( !$readOnly && empty( $errors ) && $input['____save'] )
			{

				/*
				 * write changed record to database
				 */

				if ( !$this->db->beginTransaction() )
					$this->render( '<div class="error">' . $this->getLang( 'editnotransact' ) . '</div>' );
				else try
				{

					// convert record to be written to database next
					$record = array();
					foreach ( $store as $column => $value )
						if ( $column !== $idColumn )
							if ( $this->meta[$column]['isColumn'] &&
								 !is_string( $this->meta[$column]['options']['aliasing'] ) )
							{

								$value = $this->valueToDB( $rowid, $column, $value, $this->meta[$column] );

								if ( $value !== false )
									$record[$column] = $value;

							}


					if ( $isNew )
					{

						if ( !( $record[$idColumn] = $this->nextID( $this->table, true ) ) )
							throw new PDOException( $this->getLang( 'editnoid' ) );

						$sql = sprintf( 'INSERT INTO %s (%s) VALUES (%s)',
										$this->table,
										implode( ',', array_keys( $record ) ),
										implode( ',', array_pad( array(),
												 count( $record ), '?' ) ) );

						$log = array( $record[$idColumn], 'insert' );

					}
					else
					{

						$assignments = array();
						foreach ( array_keys( $record ) as $column )
							$assignments[] = $column . '=?';

						$sql = sprintf( 'UPDATE %s SET %s WHERE %s=?',
										$this->table, implode( ',', $assignments ),
										$idColumn );

						$record[$idColumn] = $rowid;

						$log = array( $rowid, 'update' );

					}


					$st = $this->db->prepare( $sql );
					if ( !$st )
						throw new PDOException( $this->getLang( 'editprepare' ) );

					if ( !$st->execute( array_values( $record ) ) )
						throw new PDOException( $this->getLang( 'editexecute' ) );


					$this->log( $log[1], $this->table, $log[0] );


					if ( !$this->db->commit() )
						throw new PDOException( $this->getLang( 'editcommit' ) );



					/*
					 * release lock on record
					 */

					if ( $rowid && !$readOnly && !$this->releaseLock( $this->table, $rowid, true ) )
						throw new PDOException( $this->getLang( 'editnorelease' ) );

					$store = array();

					return $result;

				}
				catch ( PDOException $e )
				{

					$this->render( '<div class="error">' .
									sprintf( $this->getLang( 'editcantsave' ),
									$e->getMessage() ) . '</div>' );

					if ( !$this->db->rollBack() )
						$this->render( '<div class="error">' .
										$this->getLang( 'editrollback' ) .
										'</div>' );


				}
			}
		}
		else
		{

			/*
			 * editor started ... load from DB or initialize
			 */

			if ( $isNew )
				if ( $readOnly )
					return;



			if ( $isNew && !$duplicateOf )
			{

				$store = array();

				foreach ( $this->meta as $column => $def )
					if ( $def['isColumn'] && ( $column != $idColumn ) )
						$store[$column] = $this->getInitialValue( $column, $def );

			}
			else
			{
				// load record from table

				$cols = $this->__columnsList( false );
				$cols = implode( ',', $cols );

				//  - get raw record
				if ( $this->options['view'] )
					$sql = sprintf( '%s WHERE %s=?',
									$this->options['view'], $idColumn );
				else
					$sql = sprintf( 'SELECT %s FROM %s WHERE %s=?',
									$cols, $this->table, $idColumn );

				$st = $this->db->prepare( $sql );
				if ( !$st )
					throw new PDOException( $this->getLang( 'editloadprepare' ) );

				if ( !$st->execute( array( $duplicateOf ? $duplicateOf : $rowid ) ) )
					throw new PDOException( $this->getLang( 'editloadexecute' ) );

				$record = $st->fetch( PDO::FETCH_ASSOC );
				if ( !is_array( $record ) || empty( $record ) )
					throw new PDOException( $this->getLang( 'notarecord' ) );

				$st->closeCursor();


				// drop contained ID column
				unset( $record[$idColumn] );


				// on duplicating record reset some of the original record's
				// values current user isn't authorized to view
				foreach ( $record as $name => $value )
					if ( !$this->isAuthorizedMulti( $rowACL, $this->meta[$name]['options'], 'mayview', null, true ) )
						// user mustn't view this value of original record
						// --> reset to defined default value
						$record[$name] = $this->getInitialValue( $column, $def );




				// transfer to temporary storage converting accordingly
				$store = $this->__sortRecord( $record );


				// convert values from DB format to internal one
				foreach ( $store as $column => $value )
					$store[$column] = $this->valueFromDB( $rowid, $column, $value, $this->meta[$column] );

			}
		}



		/*
		 * prepare to support navigation
		 */

		$nav = array();

		if ( !$isNew )
		{

			if ( !is_integer( $input['____idx'] ) )
				$input['____idx'] = $this->recordI2X( $rowid );


			if ( $input['____idx'] )
				$nav[] = array( 'P' . $this->recordX2I( $input['____idx'] - 1 ),
								$this->getLang( 'navprevious' ),
								( $state['nav'] === 'previous' ) );

			$nextID = $this->recordX2I( $input['____idx'] + 1 );
			if ( $nextID )
				$nav[] = array( 'N' . $nextID, $this->getLang( 'navnext' ),
								( $state['nav'] === 'next' ) );

			if ( count( $nav ) )
				array_unshift( $nav, array( 0, $this->getLang( 'navreturn' ) ) );

		}

		if ( empty( $nav ) )
			$nav = null;



		/*
		 * Render single editor
		 */

		// compile form
		$elements = array();

		$elements[] = $this->renderField( true, null, null, array(), null, $readOnly, $rowACL );

		foreach ( $store as $column => $value )
			$elements[] = $this->renderField( $rowid, $column, $value,
											  $this->meta[$column],
											  $errors[$column], $readOnly, $rowACL );

		$elements[] = $this->renderField( false, $nav, null, array(), null, $readOnly, $rowACL );


		if ( $readOnly && $rowid )
			$cmdName = 'inspect' . $rowid;
		else if ( $rowid )
			$cmdName = 'edit' . $rowid;
		else
			$cmdName = 'insert0';

		// ensure to come back here on submitting form data
		$this->render( $this->wrapInForm( implode( '', $elements ), array(
							$this->varname( 'cmd' . $cmdName ) => '1',
							$this->varname( '____single' ) => md5( $rowid ),
							$this->varname( '____idx' ) => $input['____idx'],
							), self::maxUploadSize, true ) );


		// return and mark to prevent rendering data list
		return false;

	}


	/**
	 * Sorts record according to tabindex order provided in definition.
	 *
	 * @param array $record unsorted record
	 * @return array sorted record
	 */

	private function __sortRecord( $record )
	{

		$in    = $record;
		$index = array();

		foreach ( $in as $column => $value )
		{

			$tabindex = $this->meta[$column]['options']['tabindex'];
			if ( $tabindex > 0 )
			{
				$index[$column] = intval( $tabindex );
				unset( $in[$column] );
			}
		}

		foreach ( $in as $column => $value )
			$index[$column] = empty( $index ) ? 1 : ( max( $index ) + 1 );


		// sort columns according to explicit/implicit tabindex
		asort( $index );


		// sort record according to that index
		$out = array();
		foreach ( $index as $column => $dummy )
			if ( $this->meta[$column]['isColumn'] )
				$out[$column] = $record[$column];


		return $out;

	}


	/**
	 * Deletes selected record from managed table.
	 *
	 * @param integer $rowid ID of row to delete
	 */

	protected function deleteRecord( $rowid )
	{

		if ( !$rowid || !ctype_digit( trim( $rowid ) ) )
			throw new Exception( $this->getLang( 'notarecord' ) );


		if ( !$this->db->beginTransaction() )
			throw new PDOException( $this->getLang( 'notransact' ) );

		try
		{

			if ( !$this->obtainLock( $this->table, $rowid, true, true ) )
			{
				$this->render( '<div class="error">' . $this->getLang( 'reclocked' ) . '</div>' );
				$this->db->rollback();
				return true;
			}


			$idColumn = $this->getSingleNumericPrimaryKey();

			$st = $this->db->prepare( 'DELETE FROM ' . $this->table . ' WHERE '.
									  $idColumn . '=?' );
			if ( !$st )
				throw new PDOException( $this->getLang( 'delprepare' ) );

			if ( !$st->execute( array( $rowid ) ) )
				throw new PDOException( $this->getLang( 'delexecute' ) );

			$this->log( 'delete', $this->table, $rowid );


			if ( !$this->db->commit() )
				throw new PDOException( $this->getLang( 'delcommit' ) );

		}
		catch ( PDOException $e )
		{

			$this->db->rollback();

			throw $e;

		}
	}


	/**
	 * Drops whole table.
	 *
	 */

	protected function dropTable()
	{

		if ( !$this->db->beginTransaction() )
			throw new PDOException( $this->getLang( 'notransact' ) );

		try
		{

			if ( !$this->obtainLock( $this->table, null, true, true ) )
			{
				$this->render( '<div class="error">' . $this->getLang( 'tablelocked' ) . '</div>' );
				$this->db->rollback();
				return true;
			}


			if ( $this->db->query( 'DROP TABLE ' . $this->table ) === false )
				throw new PDOException( sprintf( $this->getLang( 'nodrop' ), $this->table ) );

			$this->log( 'drop', $this->table );


			if ( !$this->db->commit() )
				throw new PDOException( $this->getLang( 'dropcommit' ) );

		}
		catch ( PDOException $e )
		{

			$this->db->rollback();

			throw $e;

		}
	}


	/**
	 * Retrieves list of columns (optionally reduced to the set marked as
	 * visible) in current table.
	 *
	 * @param boolean $visibleOnly if true return visible columns, only
	 * @param boolean $printable if true, obey marks on columns being printable
	 * @return array list of columns' names
	 */

	protected function __columnsList( $visibleOnly = true, $printable = false )
	{

		$meta  = $this->getColumnsMeta();

		$idCol = $this->getSingleNumericPrimaryKey();
		$cols  = array();

		if ( $visibleOnly )
			foreach ( $meta as $colName => $def )
				if ( $def['isColumn'] )
				{

					if ( $def['options']['visible'] === 1 )
						$use = true;
					else if ( $printable )
						$use = $def['options']['print'] ||
							 ( $def['options']['visible'] &&
							  !$def['options']['noprint'] );
					else
						$use = $def['options']['visible'];

					if ( $use )
						$cols[] = $colName;

				}

		if ( !$visibleOnly || empty( $cols ) )
			foreach ( $meta as $colName => $def )
				if ( $def['isColumn'] )
					$cols[] = $colName;

		foreach ( $cols as $index => $name )
			if ( is_string( $meta[$name]['options']['aliasing'] ) )
				$cols[$index] = $meta[$name]['options']['aliasing'] . ' AS ' .
								$name;


		if ( $idCol )
			array_unshift( $cols, $idCol );


		return $cols;

	}


	/**
	 * Retrieves count of records in table obeying current state of filter.
	 *
	 * @return integer number of available records in table matching filter
	 */

	protected function __recordsCount( $customQuery = null )
	{

		// get compiled filter
		list( $filter, $parameters ) = $this->getFilter();


		$customQuery = trim( $customQuery );
		if ( $customQuery === '' )
			$query = "SELECT COUNT(*) FROM {$this->table}";
		else
		{

			$query = preg_replace( '/^SELECT .+ (FROM .+)$/i',
								   'SELECT COUNT(*) \1', $customQuery );

			if ( stripos( $query, ' WHERE ' ) !== false )
			{
				$filter      = '';
				$parameters = array();
			}
		}

		$st = $this->db->prepare( $query . $filter );
		if ( !$st )
			throw new PDOException( $this->getLang( 'countprepare' ) );

		if ( !$st->execute( $parameters ) )
			throw new PDOException( $this->getLang( 'countexecute' ) );

		$count = $st->fetch( PDO::FETCH_NUM );

		$st->closeCursor();


		return intval( array_shift( $count ) );

	}


	/**
	 * Retrieves records from table.
	 *
	 * @param array/string $columns list of records to retrieve, "*" for all
	 * @param boolean $obeyFilter if true, current state of filter is used to
	 *                            retrieve matching records, only
	 * @param string $sortColumn name of column to optionally sort by
	 * @param boolean $sortAscendingly if true, request to sort ascendingly
	 * @param integer $offset optional number of matching records to skip
	 * @param integer $limit maximum number of matching records to retrieve
	 * @return array excerpt of matching records according to given parameters
	 */

	public function __recordsList( $columns = '*', $obeyFilter = true,
//								   $sortColumn = null, $sortAscendingly = true,
								   $offset = 0, $limit = null, $customQuery = null )
	{

		$config = $this->__configureSelect();
		if ( is_array( $config ) )
		{

			list( $filter, $parameters, $order, $cols ) = $config;

		}
		else
		{

/*
			// prepare filter
			if ( $obeyFilter )
				list( $filter, $parameters ) = $this->getFilter();
			else
			{
				$filter     = '';
				$parameters = array();
			}

			// prepare sorting
			if ( $sortColumn )
				if ( !$this->meta[$sortColumn]['isColumn'] )
					$sortColumn = null;

			if ( $sortColumn )
				$order = ' ORDER BY ' . $sortColumn .
						 ( $sortAscendingly ? ' ASC' : ' DESC' );
			else
				$order = '';


*/

			$cols       = array();
			$filter     = '';
			$parameters = array();
			$order      = '';

		}


		// prepare limits
		if ( ( $offset > 0 ) || ( $limit > 0 ) )
		{

			$limit = ' LIMIT ' . ( ( $limit > 0 ) ? $limit : '10' );

			if ( $offset > 0 )
				$limit .= ' OFFSET ' . $offset;

		}

		// prepare columns selected for retrieval
		if ( is_array( $columns ) )
			$cols   = array_merge( $columns, $cols );
		else
			$cols[] = $columns;

		$columns = implode( ',', $cols );


		if ( trim( $customQuery ) === '' )
			$query = 'SELECT ' . $columns . ' FROM ' . $this->table;
		else
		{

			$query = $customQuery;

			if ( stripos( $query, ' WHERE ' ) !== false )
			{
				$filter     = '';
				$parameters = array();
			}

		}


		// query for records returning whole resultset
		$st = $this->db->prepare( $query . $filter . $order . $limit );
		if ( !$st )
			throw new PDOException( $this->getLang( 'listprepare' ) );

		if ( !$st->execute( $parameters ) )
			throw new PDOException( $this->getLang( 'listexecute' ) );

		return $st->fetchAll( PDO::FETCH_ASSOC );

	}


	/**
	 * Renders (excerpt of) managed table.
	 *
	 * @param boolean $expectInput if true, input is processed and controls
	 *                             (filter+commands) are rendered
	 * @param boolean $returnOutput if true, rendered table is returned rather
	 *                              than enqueued for rendering in DokuWiki page
	 * @param boolean $listAll if true, paging is disabled and all available/
	 *                         matching records are rendered
	 * @param string $customQuery a custom query to use instead of managed one
	 */

	protected function showTable( $expectInput = true, $returnOutput = false,
								  $listAll = false, $customQuery = null,
								  $isPrintVersion = false )
	{

		$customQuery = trim( $customQuery );

		$meta = $this->getColumnsMeta( ( $customQuery !== '' ) );



		/*
		 * count all matching records in table
		 */

		$count = $this->__recordsCount( $customQuery );



		/*
		 * update view state according to available input data
		 */

		$state =& $this->getSession();

		if ( trim( $state['sort'] ) === '' )
			$state['sort'] = $this->options['sort'];


		$updated = array(
						'skip' => intval( $state['skip'] ),
						'num'  => intval( $state['num'] ),
						'sort' => trim( $state['sort'] ),
						);

		if ( $expectInput )
		{

			$input = $this->getInput();

			foreach ( $input as $name => $dummy )
				if ( preg_match( '/^(skip|num|sort)(.+)$/i', $name, $m ) )
				{

					$name = strtolower( $m[1] );

					$updated[$name] = ( $name == 'sort' ) ? trim( $m[2] )
														  : intval( $m[2] );

				}
		}


		// keep values in range
		$updated['num']  = max( 10, $updated['num'] );
		$updated['skip'] = max( 0, min( $count - $updated['num'], $updated['skip'] ) );

		// save updated view state in session
		$state = array_merge( $state, $updated );

		// load view state values for easier access
		extract( $updated );



		/*
		 * prepare information on requested sorting
		 */

/*
		if ( $sort )
		{

			$sortDescendingly = ( $sort[0] == '!' );
			$sortCol = $sortDescendingly ? strtok( substr( $sort, 1 ), ',' )
										 : $sort;
			$sortCol = trim( $sortCol );

		}

*/


		/*
		 * query to list matching records
		 */

		if ( $listAll )
			unset( $skip, $num );

		$cols  = $this->__columnsList( true, $isPrintVersion );
		$rows  = $this->__recordsList( $cols, true, $skip, $num, $customQuery );


		$idCol = $this->getSingleNumericPrimaryKey();
		$code  = $this->__renderTable( $idCol, $cols, $rows, $count, $num,
									   $skip, $sort, $meta, $expectInput,
									   $listAll );

		if ( $returnOutput )
			return $code;

		$this->render( $code );

	}


	public function __renderTable( $idCol, $cols, $rows, $count, $num, $skip,
								   $sort, $meta, $expectInput, $listAll )
	{

		// required to check whether $idCol column has to be rendered or not
		$visibleIDCol = null;

		foreach ( $meta as $colName => $def )
			if ( $def['isColumn'] )
			{

				if ( is_null( $visibleIDCol ) && $def['options']['visible'] )
					$visibleIDCol = ( $colName == $idCol );
				else if ( $colName == $idCol )
					$visibleIDCol = ( $def['options']['visible'] != false );

			}

		if ( is_null( $visibleIDCol ) )
			$visibleIDCol = $meta[$idCol] && !$meta[$idCol]['auto_id'];



		/*
		 * - collect header row according to listed rows
		 * - transform all listed rows to properly defined HTML table cells
		 */

		$headers = array();
		$counter = $skip;

		foreach ( $rows as $nr => $row )
		{

			// get record's rowid
			$rowid = $idCol ? intval( $row[$idCol] ) : 0;

			if ( !$visibleIDCol )
				unset( $row[$idCol] );


			// convert all values in current row to table cells
			$i = 0;

			if ( $this->options['aclColumn'] )
			{
				$rowACL = $row[$this->options['aclColumn']];
				if ( $meta[$this->options['aclColumn']]['options']['visible'] === 1 )
					unset( $row[$this->options['aclColumn']] );
			}
			else
				$rowACL = null;


			$clicks = array();

			foreach ( $row as $column => $value )
			{

				if ( !is_array( $meta[$column] ) )
					$meta[$column] = array(
										'readonly' => true,
										'isColumn' => true,
										'format'   => 'text',
										'label'    => $column,
										);


				$def = $meta[$column];

				$headers[$column] = $def['label']  ? $def['label']  : $column;
				$class            = $def['format'] ? $def['format'] : 'na';
				$class           .= ' col' . ++$i;

				$value = $this->valueFromDB( $rowid, $column, $value, $def );

				$cell = $this->renderValue( $rowid, $column, $value, $def,
											false, false, $rowACL );

				switch ( $clickAction = $def['options']['onclick'] )
				{

					case 'edit' :
						if ( $this->options['view'] ||
							 !$this->isAuthorizedMulti( $rowACL, $this->options,
													   'may' . $clickAction ) )
							$clickAction = 'inspect';

					case 'inspect' :
						if ( $this->isAuthorizedMulti( $rowACL, $this->options,
													   'may' . $clickAction ) )
						{
							$cell = '<a href="#" onclick="return !!document.getElementById(\'' .
									$this->varname( 'cmd' . $clickAction, $rowid ) .
									'\').click();">' . $cell . '</a>';
							$clicks[] = $clickAction;
						}
						break;

					default :
						$cell = $this->convertToLink( $clickAction, $cell,
													  array( 'value' => $cell ) );

				}

				$row[$column] = "<td class=\"$class\">" . $cell . "</td>\n";

			}

			// prepend cell for counter
			array_unshift( $row, '<td class="counter col0 rightalign">'.++$counter."</td>\n" );

			if ( $expectInput )
				// append cell for row-related commands
				$row[] = '<td class="commands col' . ++$i . '">' .
						 $this->getRecordCommands( $rowid, $rowACL, $clicks ) .
						 '</td>';


			// convert set of values into HTML table row
			$classes = array();

			if ( $nr == 0 )
				$classes[] = 'first';
			if ( $nr == count( $rows ) - 1 )
				$classes[] = 'last';

			$classes[] = ( $nr % 2 ) ? 'even' : 'odd';
			$classes[] = 'row' . ( $nr + 1 );

			$classes = implode( ' ', $classes );

			$rows[$nr] = '<tr class="' . $classes . '">'.
						 implode( '', $row ) . "</tr>\n";

		}

		// finally convert all HTML table rows into single HTML table body
		$rows = implode( '', $rows );



		/*
		 * compile header row
		 */

		// ensure to have row of headers (missing on an empty list of rows)
		if ( empty( $headers ) )
			foreach ( $cols as $column )
			{

				unset( $def );

				if ( is_array( $meta[$column] ) )
					$def = $meta[$column];
				else
				{
					// missing meta information on current "column name"
					// --> might be an alias definition
					//     --> extract originally selected column name from that

					$pos = strripos( $column, ' AS ' );
					if ( $pos !== false )
					{

						$temp = substr( $column, $pos + 4 );
						if ( $meta[$temp] )
						{
							// found definition on extracted column name

							$def    = $meta[$temp];
							$column = $temp;

						}
					}
				}


				$headers[$column] = $def['label'] ? $def['label'] : $column;

			}


		// next transform headers into table header cells including proper
		// controls for sorting etc.
		$sortDescendingly = ( $sort[0] == '!' );
		if ( $sortDescendingly )
			$sort = substr( $sort, 1 );

		$sort = trim( strtok( $sort, ',' ) );


		foreach ( $headers as $column => $label )
		{

			if ( $meta[$column]['options']['headerlabel'] )
				$label = trim( $meta[$column]['options']['headerlabel'] );

			if ( ( $href = trim( $meta[$column]['options']['headerlink'] ) ) !== '' )
				$label = $this->convertToLink( $href, $label );


			if ( ( $sort == $column ) && $sortDescendingly )
			{
				$name = $column;
				$icon = 'down';
				$title = $this->getLang( 'hintsortasc' );
			}
			else if ( $sort == $column )
			{
				$name = '!' . $column;
				$icon = 'up';
				$title = $this->getLang( 'hintsortdesc' );
			}
			else
			{
				$name = $column;
				$icon = 'none';
				$title = $this->getLang( 'hintsortasc' );
			}

			if ( $expectInput )
				$sorter = "&nbsp;<input " .
								'type="image" name="' . $this->varname( 'sort' . $name ).
								'" src="' . DOKU_BASE . DB2_PATH .
								"icons/$icon.gif\" title=\"$title\"/>";
			else
				$sorter = '';

			$headers[$column] = "<th class=\"label\">$label$sorter</th>\n";

		}

		// compile row of header cells
		$headers = implode( '', $headers );



		/*
		 * check for available filter
		 */

		if ( $this->isAuthorized( $this->options['mayfilter'] ) && $expectInput )
		{

			$filter = $this->renderFilter();
			if ( $filter != '' )
				$filter = '<tr class="filter"><td colspan="3">' . $filter . '</td></tr>';

		}
		else
			$filter = '';



		/*
		 * compile pager
		 */

		list( $flipDown, $flipUp, $pages, $sizes, $stat ) = $this->getPagerElements( $skip, $num, $count );

		if ( !$expectInput )
			unset( $sizes );

		$sepStat = $sizes ? ' &mdash; ' . $stat : $stat;



		/*
		 * retrieve all available commands operating on whole table
		 */

		$globalCmds = $expectInput ? $this->getGlobalCommands() : '';


		/*
		 * render list of rows as HTML table
		 */

		$width     = intval( $this->options['width'] ) ? ' width="' . $this->options['width'] . '"' : '';
		$cmdHeader = $expectInput ? '<th class="commands"></th>' : '';

		$trClass    = $this->options['wikistyle'] ? '' : ' class="data-list"';
		$tableClass = $this->options['wikistyle'] ? ' class="inline"' : '';


		$table = <<<EOT
   <table width="100%"$tableClass>
    <thead>
     <tr class="row0">
      <th class="counter"></th>
      $headers
      $cmdHeader
     </tr>
    </thead>
    <tbody>
     $rows
    </tbody>
    <caption>
     $sizes$sepStat
    </caption>
   </table>
EOT;

		if ( $expectInput || !$listAll )
			$table     = <<<EOT
<table class="database2"$width>
 <tbody>
  $filter
  <tr class="upper-navigation">
   <td align="left" width="33.3%">
    $flipDown
   </td>
   <td align="center" width="33.3%">
    $pages
   </td>
   <td align="right" width="33.3%">
    $flipUp
   </td>
  </tr>
  <tr$trClass>
   <td colspan="3">
    $table
   </td>
  </tr>
  <tr class="lower-navigation">
   <td colspan="3">
    $globalCmds
   </td>
  </tr>
 </tbody>
</table>
EOT;
		else
			$table     = <<<EOT
<table class="database2"$width>
 <tbody>
  <tr$trClass>
   <td>
    $table
   </td>
  </tr>
 </tbody>
</table>
EOT;

		return $expectInput ? $this->wrapInForm( $table ) : $table;

	}


	public function __csvLine( $fields )
	{

		foreach ( $fields as &$field )
			$field = '"' . strtr( $field, array( '"' => '""' ) ) . '"';

		return implode( ';', $fields ) . "\n";

	}


	protected function button( $name, $label )
	{

		$args = func_get_args();
		$args = array_filter( array_slice( $args, 2 ), create_function( '$a', 'return trim($a)!=="";' ) );

		$disabled = in_array( 'disabled', $args ) ? ' disabled="disabled"' : '';

		if ( !$icon )
			$args[] = 'pure-text';

		$classes = implode( ' ', $args );

		return '<input type="submit" name="' . $this->varname( $name ) .
				'" value="' . htmlentities( $label ) . '" class="' .
				$classes . '"' . $disabled . ' />';

	}

	protected function imgbutton( $name, $label, $icon )
	{

		$args = func_get_args();
		$args = array_filter( array_slice( $args, 3 ), create_function( '$a', 'return trim($a)!=="";' ) );

		$disabled = in_array( 'disabled', $args ) ? ' disabled="disabled"' : '';

		$classes = implode( ' ', $args );

		return '<input type="image" name="' . $this->varname( $name ) .
				'" title="' . htmlentities( $label, ENT_COMPAT, 'UTF-8' ) .
				'" class="' . $classes . '"' . $disabled .' src="' .
				DOKU_BASE . DB2_PATH . 'icons/' . $icon . '.gif" />';

	}


	/**
	 * Compiles elements for flipping/selecting page and number of records per
	 * page in listing table according to current context.
	 *
	 * @param integer $skip number of records to skip on listing
	 * @param integer $num number of records to list per page at most
	 * @param integer $count number of records in table
	 * @return array five-element array containing buttons for flipping down,
	 *               flipping up, selecting page, selecting number of records
	 *               per page and for showing number of records and pages.
	 */

	protected function getPagerElements( $skip, $num, $count )
	{

		// build list of skip-values for all pages
		$skips = array();

		if ( $num > 0 )
		{

			for ( $i = $skip; $i > 0; $i -= $num )
				array_unshift( $skips, $i );

			array_unshift( $skips, 0 );

			for ( $i = $skip + $num; $i < $count - $num; $i += $num )
				array_push( $skips, $i );

			if ( $i < $count )
				array_push( $skips, $count - $num );

		}


		// detect index of currently visible page
		$page = array_search( $skip, $skips );

		$minPage = max( 0, $page - self::maxSurroundingPagesCount );
		$maxPage = min( count( $skips ), $page + self::maxSurroundingPagesCount );



		/*
		 * compile pager elements for ...
		 */

		if ( count( $skips ) <= 1 )
			$backward = $forward = $pages = '';
		else
		{

			// ... flipping down
			$backward = $pages = $forward = array();

			if ( $page > 1 )
				$backward[] = $this->imgbutton( 'skip0', $this->getLang( 'hintflipfirst' ), 'first' );

			if ( $page > 0 )
				$backward[] = $this->imgbutton( 'skip' . $skips[$page-1], $this->getLang( 'hintflipprevious' ), 'previous' );

			$backward = implode( "\n", $backward );


			// ... switching to near page
			for ( $i = $minPage; $i < $maxPage; $i++ )
				$pages[] = $this->button( 'skip' . $skips[$i], $i + 1,
										  ( $i == $page ? 'selected' : '' ) );

			if ( $minPage > 0 )
				array_unshift( $pages, '...' );

			if ( $maxPage < count( $skips ) - 1 )
				array_push( $pages, '...' );

			$pages = implode( "\n", $pages );


			// ... flipping up
			if ( $page < count( $skips ) - 1 )
				$forward[] = $this->imgbutton( 'skip' . $skips[$page+1], $this->getLang( 'hintflipnext' ), 'next' );

			if ( $page < count( $skips ) - 2 )
				$forward[] = $this->imgbutton( 'skip' . ( $count - $num ), $this->getLang( 'hintfliplast' ), 'last' );

			$forward = implode( "\n", $forward );

		}


		// ... showing number of records/pages
		if ( $count === 1 )
			$stat = sprintf( $this->getLang( 'recnumsingle' ), $count );
		else if ( count( $skips ) <= 1 )
			$stat = sprintf( $this->getLang( 'recnummulti' ), $count );
		else
			$stat = sprintf( $this->getLang( 'recnummultipage' ), $count, count( $skips ) );


		// ... selecting number of records per page
		foreach ( array( 10, 20, 50, 100, 200 ) as $size )
		{
			$sizes[] = $this->button( 'num' . $size, $size,
									  ( $size == $num ? 'selected' : '' ) );
			if ( $size >= $count )
				break;
		}

		$sizes = count( $sizes ) == 1 ? '' : implode( "\n", $sizes );



		// return set of pager elements to caller
		return array( $backward, $forward, $pages, $sizes, $stat );

	}


	/**
	 * Wraps provided HTML code in a form sending form data to current wiki
	 * page.
	 *
	 * @param string $code HTML code to embed
	 * @param array $hiddens set of hidden values to be added
	 * @param integer $maxUploadSize maximum size of supported uploads in bytes
	 * @param boolean $isSingle set true on calling to render single-record editor
	 * @return string
	 */

	protected function wrapInForm( $code, $hiddens = null,
									$maxUploadSize = null, $isSingle = false )
	{
		include_once( DOKU_INC . 'inc/form.php' );

		ob_start();

		$id   = 'database2_' . ( $isSingle ? 'single' : 'table' ) . '_' .
				$this->table . '_' . $this->getIndex();

		if ( $maxUploadSize > 0 )
		{
			$form = new Doku_Form( $id, false, 'POST', 'multipart/form-data' );
			$form->addHidden( 'MAX_FILE_SIZE', intval( $maxUploadSize ) );
		}
		else
			$form = new Doku_Form( $id );

		$form->addHidden( 'id', $this->getPageID() );

		if ( is_array( $hiddens ) )
			foreach ( $hiddens as $name => $value )
				$form->addHidden( $name, $value );

		$form->addElement( $code );

		$form->printForm();


		return ob_get_clean();

	}


	/**
	 * Checks if current user is authorized according to given rule.
	 *
	 * The rule is a comma-separated list of usernames and groups (after
	 * preceeding @ character), e.g.
	 *
	 *   admin,@user
	 *
	 * authorizing user admin and every user in group "user".
	 *
	 * @param string $rule rule describing authorizations
	 * @return boolean true if current user is authorized, false otherwise
	 */

	protected function isAuthorized( $rule )
	{
		global $USERINFO;


		if ( auth_isadmin() )
			return true;

		if ( $rule )
		{

			$granted = true;

			foreach ( explode( ',', $rule ) as $role )
			{

				$role = trim( $role );
				if ( $role === '' )
					continue;

				if ( !strcasecmp( $role, '@ALL' ) )
					return true;

				if ( !strcasecmp( $role, '@NONE' ) )
					return false;


				if ( $_SERVER['REMOTE_USER'] )
				{

					if ( $role[0] == '!' )
					{
						$role  = substr( $role, 1 );
						$match = false;
					}
					else
						$match = true;

					if ( $role[0] == '@' )
					{
						if ( in_array( substr( $role, 1 ), $USERINFO['grps'] ) )
						{
							if ( $match && $granted )
								return true;
							if ( !$match )
								$granted = false;
						}
					}
					else if ( $role == $_SERVER['REMOTE_USER'] )
					{
						if ( $match && $granted )
							return true;
						if ( !$match )
							$granted = false;
					}

				}
			}
		}


		return false;

	}


	/**
	 * Takes ACL rule set related to a single row and related to whole table
	 * testing them either of them preferring the first one for authorizing
	 * current user to do one of up to two sorts of access preferring the first
	 * one again.
	 *
	 * @param string|array $rowACL row-related ACL rule set
	 * @param array $tableACL table-related ACL rule set, used if $rowACL isn't
	 *                        managing any of the given sorts of access
	 * @param string $ruleName preferred sort of access to authorize for
	 * @param string $optRuleName optional fallback sort of access if first
	 *                            isn't managed in selected rule set
	 * @param boolean $defaultGrant true to select granting access if neither
	 *                              rule set is managing any selected sort of access
	 * @return boolean true if user is authorized, false otherwise
	 */

	protected function isAuthorizedMulti( &$rowACL, $tableACL, $ruleName,
										  $optRuleName = null,
										  $defaultGrant = false )
	{

		if ( is_string( $rowACL ) )
			$rowACL = $this->parseACLRule( $rowACL, false, true );

		// use row-related rule set if it's managing any given sort of access
		if ( $rowACL[$ruleName] )
			$rule = $rowACL[$ruleName];
		else if ( $rowACL[$optRuleName] )
			$rule = $rowACL[$optRuleName];
		else
			$rule = null;

		if ( $rule )
			return $this->isAuthorized( $rule );


		// use table-related rule set if it's managin any given sort of access
		if ( $tableACL[$ruleName] )
			$rule = $tableACL[$ruleName];
		else if ( $tableACL[$optRuleName] )
			$rule = $tableACL[$optRuleName];
		else
			// neither of them is managing given sorts of access
			// --> grant or revoke access depending on fifth argument
			if ( $defaultGrant )
				return true;
			else
				$rule = '';


		return $this->isAuthorized( $rule );

	}


	/**
	 * Gets HTML code of global commands current user is authorized to invoke.
	 *
	 * @return string HTML code
	 */

	protected function getGlobalCommands()
	{

		if ( $this->options['view'] )
			return '';


		$globalCmds = array(
							'insert'     => array( 'add', $this->getLang( 'cmdadd' ) ),
							'drop'       => array( 'drop', $this->getLang( 'cmddrop' ), true ),
							'print'      => array( 'print', $this->getLang( 'cmdprint' ), 'print', true, 'print' ),
							'export.csv' => array( 'exportcsv', $this->getLang( 'cmdcsv' ), 'csv' ),
//							'export.xml' => array( 'exportxml', $this->getLang( 'cmdxml' ), 'xml' ),
							'viewlog'    => array( 'exportlog', $this->getLang( 'cmdlog' ), 'log' ),
							);


		if ( !$this->getSingleNumericPrimaryKey() )
			unset( $globalCmds['insert'] );


		foreach ( $globalCmds as $name => $def )
			if ( $this->isAuthorized( $this->options['may'.($authz=strtok( $name, '.' ))] ) )
			{

				if ( is_string( $def[2] ) )
				{

					$href = $this->attachmentLink( $def[2], $authz, !$def[3] );
					$globalCmds[$name] = '<a href="' . $href . '" class="icon-cmd" target="' . $def[4] .
										 '"><img src="' .
										 DOKU_BASE . DB2_PATH . 'icons/' . $def[0] .
										 '.gif" title="' . $def[1] . '" alt="' .
										 $def[1] . '" /></a>';

				}
				else
					$globalCmds[$name] = '<input type="image" class="icon-cmd" name="' .
										 $this->varname( 'cmd' . $name ) .
										 '" title="' . $def[1] . '" src="' .
										 DOKU_BASE . DB2_PATH . 'icons/' . $def[0] .
										 '.gif" onclick="' . ( $def[2] ? "return confirm('" . $this->getLang( 'confirmdrop' ) . "');" : '' ) . '" />';

			}
			else
				unset( $globalCmds[$name] );


		return implode( "\n", $globalCmds );

	}


	/**
	 * Gets HTML code of commands related to selected record current user is
	 * authorized to invoke.
	 *
	 * @param integer $rowid ID of record
	 * @return string HTML code
	 */

	protected function getRecordCommands( $rowid, $rowACL = null, $clickActions = null )
	{

		$rowid = intval( $rowid );
		if ( !$rowid )
			// don't provide record management on complex/non-integer
			// primary keys
			return '';


		if ( !$this->getSingleNumericPrimaryKey() )
			return '';


		$recordCmds = array(
							'inspect' => array( 'view', $this->getLang( 'cmdview' ) ),
							'edit'    => array( 'edit', $this->getLang( 'cmdedit' ) ),
							'insert'  => array( 'copy', $this->getLang( 'cmdcopy' ) ),
//							'insert'  => array( 'insert', $this->getLang( 'cmdinsert' ) ),
							'delete'  => array( 'delete', $this->getLang( 'cmddelete' ), true ),
							);


		if ( $this->options['view'] )
			// it's a read-only view thus exclude commands for adjusting data
			unset( $recordCmds['edit'], $recordCmds['insert'],
				   $recordCmds['delete'] );


		if ( !is_array( $clickActions ) )
			$clickActions = array();

		foreach ( $recordCmds as $name => $def )
			if ( $this->isAuthorizedMulti( $rowACL, $this->options, 'may' . $name ) )
			{

				$idName = $this->varname( 'cmd' . $name, $rowid );
				$class  = ( !in_array( $name, $clickActions ) ||
							$this->options['addonclick'] ) ? '' : ' hidden';

				$recordCmds[$name] = '<input type="image" class="icon-cmd' .
									 $class . '" name="' . $idName . '" id="' .
									 $idName . '" title="' . $def[1] . '" src="' .
									 DOKU_BASE . DB2_PATH . 'icons/' . $def[0] .
									 '.gif" onclick="' . ( $def[2] ? "return confirm('" . $this->getLang( 'confirmdelete' ) . "');" : '' ) . '" />';

			}
			else
				unset( $recordCmds[$name] );


		return implode( "\n", $recordCmds );

	}


	protected function __configureSelect()
	{

		$idCol = $this->getSingleNumericPrimaryKey();
		if ( $idCol === false )
			return false;


		$cols = array( $idCol );


		// prepare filter
		list( $filter, $parameters ) = $this->getFilter();


		// prepare sorting
		$state =& $this->getSession();

		$sort  = preg_split( '/[,\s]+/', trim( $state['sort'] ) );

		foreach ( $sort as $key => $desc )
		{

			$dir = ( $desc[0] == '!' );

			$col = $dir ? substr( $desc, 1 ) : $desc;
			$col = trim( $col );

			if ( $this->meta[$col]['isColumn'] )
			{

				if ( is_string( $this->meta[$col]['options']['aliasing'] ) )
					$cols[] = $this->meta[$col]['options']['aliasing'] . ' AS ' . $col;
				else
					$cols[] = $col;

				$sort[$key] = $col . ( $dir ? ' DESC' : ' ASC' );

			}
			else
				unset( $sort[$key] );

		}

		$order = count( $sort ) ? ' ORDER BY ' . implode( ', ', $sort ) : '';



		return array( $filter, $parameters, $order, $cols );

	}

	protected function recordI2X( $rowid )
	{

		$config = $this->__configureSelect();
		if ( !is_array( $config ) )
			return false;

		list( $filter, $parameters, $order, $cols ) = $config;


		// query for records returning whole resultset
		$st = $this->db->prepare( 'SELECT ' . implode( ',', $cols ) .
								  ' FROM ' . $this->table . $filter . $order );
		if ( !$st )
			throw new PDOException( $this->getLang( 'listprepare' ) );

		if ( !$st->execute( $parameters ) )
			throw new PDOException( $this->getLang( 'listexecute' ) );


		$index = 0;
		while ( ( $record = $st->fetch( PDO::FETCH_NUM ) ) !== false )
			if ( $record[0] == $rowid )
			{
				$st->closeCursor();
				return $index;
			}
			else
				$index++;


		return null;

	}


	protected function recordX2I( $index )
	{

		if ( !is_integer( $index ) || ( $index < 0 ) )
			return false;


		$config = $this->__configureSelect();
		if ( !is_array( $config ) )
			return false;

		list( $filter, $parameters, $order, $cols ) = $config;


		// query for records returning whole resultset
		$st = $this->db->prepare( 'SELECT ' . implode( ',', $cols ) .
								  ' FROM ' . $this->table . $filter . $order .
								  ' LIMIT 1 OFFSET ' . $index );
		if ( !$st )
			throw new PDOException( $this->getLang( 'listprepare' ) );

		if ( !$st->execute( $parameters ) )
			throw new PDOException( $this->getLang( 'listexecute' ) );


		$record = $st->fetch( PDO::FETCH_NUM );

		if ( is_array( $record ) && count( $record ) )
			return intval( $record[0] );

		return null;

	}


	/**
	 * Parses provided string for filter description consisting of one or more
	 * components.
	 *
	 * @param string $in string containing filter definition
	 * @return array list of successfully parsed filter components
	 */

	protected function parseFilterCode( $in )
	{

		$in       = trim( $in );
		$out      = array();

		$prevMode = false;

		while ( $in !== '' )
		{

			if ( preg_match( '/^(\w+)\s+(\w+)(.*)$/i', $in, $matches ) )
			{

				// extract argument to current filter rule
				$tail = trim( $matches[3] );
				if ( ( $tail[0] == '"' ) || ( $tail[0] == "'" ) )
				{
					// argument is enclosed in quotes

					$pos      = 0;
					$argument = $this->parseString( $tail, $pos );

				}
				else
				{
					// argument take everything up to next space or separator

					$pos = strcspn( $tail, " \r\n\t\f&|" );
					if ( $pos )
						$argument = trim( substr( $tail, 0, $pos ) );
					else
						$argument = '';

				}


				$new = array(
							'col' => $matches[1],
							'op'  => $matches[2],
							'arg' => $this->replaceMarkup( $argument ),
							);

				if ( $prevMode === '&' )
					$new['mode'] = 'AND';
				else if ( $prevMode === '|' )
					$new['mode'] = 'OR';
				else if ( $prevMode !== false )
					// invalid pattern separator --> break parsing filter code
					break;

				$out[] = $new;

				$in    = ltrim( substr( $tail, $pos ) );

			}
			// invalid filter element --> break parsing filter code
			else break;


			$prevMode = $in[0];
			$in       = substr( $in, 1 );

		}


		return $out;

	}


	/**
	 * Manages current state of table's filter in session processing optional
	 * modifications in current input data.
	 *
	 * @return array description of current state of filter
	 */

	protected function getFilterInput()
	{

		$input   =  $this->getInput();
		$session =& $this->getSession();


		if ( $input['searchdrop'] )
			$session['search'] = array();
		else
		{

			if ( !is_array( $session['search'] ) )
			{

				$session['search'] = array();

				if ( $this->options['basefilter'] )
					// initialize filter using provided code
					return ( $session['search'] = $this->parseFilterCode( $this->options['basefilter'] ) );

			}


			// parse filter input and transfer it to session
			foreach ( $input as $key => $value )
				if ( preg_match( '/^search(col|op|arg)(\d*)$/', $key, $matches ) )
				{

					$index = intval( $matches[2] );

					if ( !is_array( $session['search'][$index] ) )
						$session['search'][$index] = array();

					$session['search'][$index][$matches[1]] = $value;

				}


			// drop incomplete filter components
			foreach ( $session['search'] as $index => $filter )
				if ( !is_string( $filter['col'] ) ||
					 !is_string( $filter['op'] ) ||
					 !is_string( $filter['arg'] ) )
					unset( $session['search'][$index] );
				else
					if ( !in_array( $filter['mode'], array( 'AND', 'OR' ) ) )
						if ( $index )
							unset( $session['search'][$index] );

		}


		return $session['search'];

	}


	/**
	 * Gets WHERE clause and contained parameters to filter records in table.
	 *
	 * @return array two-element array, SQL-WHERE-clause with initial WHERE and
	 *               array with all values to be bound as parameters to clause
	 */

	protected function getFilter()
	{

		$filters   = $this->getFilterInput();
		$opMap     = array(
							'like'    => ' %s ( %s like ? )',
							'nlike'   => ' %s ( %s not like ? )',
							'lt'      => ' %s ( %s < ? )',
							'eq'      => ' %s ( %s = ? )',
							'gt'      => ' %s ( %s > ? )',
							'ne'      => ' %s ( %s <> ? )',
							'le'      => ' %s ( %s <= ? )',
							'ge'      => ' %s ( %s >= ? )',
							'isset'   => ' %s ( ( %2$s = ? ) AND %2$s IS NOT NULL )',
							'isclear' => ' %s ( ( %2$s <> ? ) OR %2$s IS NULL )',
							);


		$meta = $this->getColumnsMeta();
		$out  = array( '', null );

		foreach ( $filters as $index => $filter )
		{

			$mode   = ( $out[0] !== '' ) ? $filter['mode'] : 'WHERE';

			$column = $meta[$filter['col']];
			if ( $column && $column['isColumn'] )
			{
				// 1) filter operates on valid column

				if ( $column['type'] == 'bool' )
				{

					if ( in_array( $filter['op'], array( 'like', 'eq', 'le', 'ge', 'isset' ) ) )
						$filter['op'] = 'isset';
					else
						$filter['op'] = 'isclear';

					switch ( $column['options']['booltype'] )
					{
						case 'xmark' : $argument = 'x'; break;
						case 'yesno' : $argument = 'y'; break;
						case 'int'   : $argument = '1'; break;
					}
				}
				else
					$argument = trim( $filter['arg'] );

				if ( $argument !== '' )
					// 2) filter operates with non-empty argument
					if ( $opMap[$filter['op']] )
					{
						// 3) filter uses valid operation
						// ----> include it.

						if ( in_array( $filter['op'], array( 'like', 'nlike' ) ) )
							if ( strpos( $argument, '%' ) === false )
								$argument = '%' . $argument . '%';

						$out[0] .= sprintf( $opMap[$filter['op']], $mode,
											$filter['col'] );

						if ( is_array( $out[1] ) )
							$out[1][] = $argument;
						else
							$out[1]   = array( $argument );

					}
			}
		}


		return $out;

	}


	/**
	 * Renders filter on current table.
	 *
	 * @return string HTML-code representing filter on selected table
	 */

	protected function renderFilter()
	{

		/*
		 * prepare entries for selecting column
		 */

		$meta    = $this->getColumnsMeta();

		$columns = array();
		$mapType = array(
						'integer' => 'numeric',
						'real'    => 'numeric',
						'decimal' => 'numeric',
						'text'    => 'text',
						'date'    => 'date',
						'enum'    => 'enum',
						'bool'    => 'bool',
						);

		$allVisible = true;
		foreach ( $meta as $column => $def )
			if ( !is_null( $def['options']['visible'] ) ||
				 !is_null( $def['options']['filter'] ) )
			{
				$allVisible = false;
				break;
			}

		foreach ( $meta as $column => $def )
			if ( $def['isColumn'] && ( $allVisible || $def['options']['visible'] || $def['options']['filter'] ) )
			{

				if ( $def['format'] == 'acl' )
					continue;

				$class = $mapType[$def['type']];
				if ( !$class )
					continue;

				$label = $def['label'] ? $def['label'] : $column;
				$label = strtr( $label, array( '<' => '&lt;' ) );

				$head  = "<option value=\"$column\" class=\"$class\"";
				$tail  = ">$label</option>";

				$columns[$column] = strtr( $head, array( '%' => '%%' ) ) . '%s'.
									strtr( $tail, array( '%' => '%%' ) );

			}

		if ( empty( $columns ) )
			// not supporting to filter any column -> don't render filter at all
			return '';



		/*
		 * prepare entries for selecting operator
		 */

		$operators = array(
						'like'    => array( $this->getLang( 'oplike' ), 'text', ),
						'nlike'   => array( $this->getLang( 'opnotlike' ), 'text', ),
						'lt'      => array( '<', 'text', 'numeric', 'date', ),
						'le'      => array( '<=', 'text', 'numeric', 'date', ),
						'eq'      => array( '=', 'text', 'numeric', 'date', 'enum', ),
						'ne'      => array( '<>', 'text', 'numeric', 'date', 'enum', ),
						'ge'      => array( '>=', 'text', 'numeric', 'date', ),
						'gt'      => array( '>', 'text', 'numeric', 'date', ),
						'isset'	  => array( $this->getLang( 'opset' ), 'bool', ),
						'isclear' => array( $this->getLang( 'opclear' ), 'bool', ),
						);

		foreach ( $operators as $op => $def )
		{

			$label = array_shift( $def );
			$class = implode( ' ', $def );

			$head  = "<option value=\"$op\" class=\"$class\"";
			$tail  = ">$label</option>";

			$operators[$op] = strtr( $head, array( '%' => '%%' ) ) . '%s' .
							  strtr( $tail, array( '%' => '%%' ) );

		}



		/*
		 * separately render used filter components
		 */

		$filters = $this->getFilterInput();
		$input   = $this->getInput();

		$modeMap = array(
						'AND' => '<span class="mode-and">' . $this->getLang( 'opand' ) . '</span>',
						'OR'  => '<span class="mode-or">' . $this->getLang( 'opor' ) . '</span>',
						);

		if ( empty( $filters ) || $input['searchand'] || $input['searchor'] )
		{
			// add new empty filter rule

			$newFilter = array(
								'col' => '',
								'op'  => '',
								'arg' => '',
								);

			if ( $input['searchand'] )
				$newFilter['mode'] = 'AND';
			else if ( $input['searchor'] )
				$newFilter['mode'] = 'OR';

			$session             =& $this->getSession();
			$session['search'][] = $newFilter;
			$filters[]           = $newFilter;

		}


		foreach ( $filters as $index => $filter )
		{

			// update columns selector entries marking currently selected one
			$optColumns   = $columns;
			foreach ( $optColumns as $column => $code )
				$optColumns[$column] = sprintf( $code, ( $column == $filter['col'] ) ? ' selected="selected"' : '' );

			// update operators selector entries marking currently selected one
			$optOperators = $operators;
			foreach ( $optOperators as $operator => $code )
				$optOperators[$operator] = sprintf( $code, ( $operator == $filter['op'] ) ? ' selected="selected"' : '' );


			// prepare stuff for rendering code
			$optColumns   = implode( "\n", $optColumns );
			$optOperators = implode( "\n", $optOperators );

			$argument = strtr( $filter['arg'], array( '"' => '&quot;' ) );

			$colname  = $this->varname( 'searchcol' . $index );
			$opname   = $this->varname( 'searchop' . $index );
			$argname  = $this->varname( 'searcharg' . $index );

			$mode     = $index ? $modeMap[$filter['mode']] : '';

			$mark     = ( trim( $filter['arg'] ) === '' ) ? ' unused' : '';


			// render code for single filter component
			$filters[$index] = <<<EOT
<span class="filter-component$mark">
 $mode
 <select name="$colname" class="column" onchange="return database2_searchCol(this);">$optColumns</select>
 <select name="$opname" class="operator">$optOperators</select>
 <input type="text" name="$argname" size="10" value="$argument" class="argument text numeric date enum" />
</span>
EOT;

		}



		$cmds = array();

		$cmds[] = $this->imgbutton( 'searchgo', $this->getLang( 'cmdfilterapply' ), 'filter' );
		$cmds[] = $this->imgbutton( 'searchand', $this->getLang( 'cmdfilterintersect' ), 'filter-and' );
		$cmds[] = $this->imgbutton( 'searchor', $this->getLang( 'cmdfilterunion' ), 'filter-or' );

		if ( ( count( $filters ) > 1 ) || ( trim( $argument ) !== '' ) ||
			 ( $filter['op'] && ( $filter['op'] !== 'like' ) ) )
			$cmds[] = $this->imgbutton( 'searchdrop', 'Reset filter',
										'filter-drop' );

		$commands = '<span class="commands">' . implode( "\n", $cmds ) . '</span>';


		$class = ( count( $filters ) > 1 ) ? 'multi-filter' : 'single-filter';


		return '<div class="' . $class . '">' .
			   implode( "\n", $filters ) . $commands . '</div>';

	}


	/**
	 * Extracts quoted string starting with arbitrary quoting character at given
	 * index.
	 *
	 * The provided index in $first is updated on return to point to first
	 * character after extracted string.
	 *
	 * @param string $in haystack containing quoted string
	 * @param integer $first index of character starting quoted string
	 * @return string extracted string on success, false on error
	 */

	public static function parseString( $in, &$first )
	{

		$pos = $first;

		do
		{

			// find next matching quote character marking end of string
			$end = strpos( $in, $in[$first], $pos + 1 );
			if ( $end === false )
				// didn't find any --> malformed string
				return false;

			$count = 0;
			for ( $idx = $end - 1; $idx > $pos; $idx-- )
				if ( $in[$idx] == '\\' )
					$count++;
				else
					break;

			if ( $count & 1 )
				$pos = $end;
			else
			{

				$string = substr( $in, $first + 1, $end - $first - 1 );

				$first  = $end + 1;

				return stripcslashes( $string );

			}

		}
		while ( true );

	}


	public static function parseAssignment( $in, &$first )
	{

		$pos  = $first;


		// skip any leading whitespace
		$pos += strspn( $in, " \t", $pos );



		// read and normalize name
		$end  = $pos + strcspn( $in, " \t=", $pos );
		$name = substr( $in, $pos, $end - $pos );

		if ( $name === '' )
			// there is no (further) assignment in $in
			return null;

		if ( ctype_digit( $name ) )
			$name = intval( $name );



		// skip any whitespace found between name and assignment operator
		$pos = $end + strspn( $in, " \t", $end );

		if ( $in[$pos] !== '=' )
			// option does not use assignment operator
			// --> it's a "shortcut option"
			$value = true;
		else
		{
			// expecting assigned value next


			// skip whitespace between assignment operator and value
			$pos += strspn( $in, " \t", $pos + 1 ) + 1;
			$end  = $pos;

			if ( $in[$pos] === '"' )
			{
				// value is enclosed in quotes

				$temp = self::parseString( $in, $end );
				if ( $temp === false )
					return false;

				$value = $temp;

			}
			else
			{

				$end  += strcspn( $in, " \t", $end );

				$value = substr( $in, $pos, $end - $pos );

			}
		}

		$first = $end;


		return array( $name, $value );

	}


	public static function stripTags( $in, $tags = null )
	{

		if ( !is_array( $tags ) )
			$tags = array( 'script', 'form', 'link', 'html', 'body', 'head', );


		$pos = 0;

		do
		{

			// fast search for next opening tag
			$tag = strpos( $in, '<', $pos );
			if ( $tag === false )
				return $in;

			if ( $in[$tag+1] == '?' )
			{
				// detected start of PI ... skip completely

				$end = strpos( $in, '?>', $tag + 2 );
				if ( $end !== false )
					$in = substr_replace( $in, '', $tag, $end - $tag + 2 );
				else
					$in = substr_replace( $in, '', $tag );

				// fix for properly updating $pos below
				$tag--;

			}
			else
			{
				// got tag ... check its name

				$name = strtok( substr( $in, $tag + 1, 20 ), ' >' );
				$name = strtolower( trim( $name ) );

				if ( array_search( $name, $tags ) !== false )
				{
					// tag is marked for dropping

					// slow, but convenient: find next end of tag and drop everything in between
					if ( preg_match( "#.+?</\s*$name\s*>#i", $in, $m, null, $tag ) )
						$in = substr_replace( $in, '', $tag, strlen( $m[0] ) );
					else
						$in = substr_replace( $in, '', $tag );

					$tag--;

				}
			}

			// update $pos to omit all previously processed part of $in
			$pos = $tag + 1;

		}
		while ( true );

	}


	public static function splitDefinitionLine( $line )
	{

		$line   = trim( $line );

		$parts  = array();
		$part   = '';

		$pos    = 0;
		$length = strlen( $line );


		while ( ( $pos < $length ) && ( count( $parts ) < 3 ) )
		{

			$pos += strspn( $line, " \t", $pos );
			$end  = $pos;

			if ( $line[$pos] === '"' )
			{

				$temp = self::parseString( $line, $end );
				if ( $temp === false )
					return false;

				if ( $part !== '' )
					$part .= ' ';

				$part .= $temp;

			}
			else if ( $line[$pos] === ',' )
			{

				$parts[] = $part;
				$part    = '';

				$end++;

			}
			else
			{

				$end += strcspn( $line, " \t,", $end );

				if ( $part !== '' )
					$part .= ' ';

				$part .= trim( substr( $line, $pos, $end - $pos ) );

			}

			$pos = $end;

		}


		if ( $part !== '' )
			$parts[] = $part;


		$parts = array_pad( $parts, 3, '' );


		$options = array();

		if ( $pos < $length )
		{

			$name    = '';
			$value   = '';

			while ( $pos < $length )
			{

				$temp = self::parseAssignment( $line, $pos );
				if ( $temp === false )
					return false;

				if ( is_null( $temp ) )
					break;

				list( $name, $value ) = $temp;

				$options[$name] = $value;

			}
		}

		$parts[] = $options;


		return $parts;

	}


	protected function convertToLink( $href, $label, $varspace = array() )
	{

		$href = trim( $href );
		if ( $href === '' )
			return $label;


		if ( is_array( $varspace ) && count( $varspace ) )
			$href = $this->replaceMarkup( $href, $varspace );


		if ( strpos( $href, '://' ) !== false )
		{
			// embed external link in header

			// externallink() is adding to renderer->doc() ...
			// --> remove from doc afterwards, thus store its length now
			$length = strlen( $this->renderer->doc );

			$this->renderer->externallink( $href, $label );

			// --> now extract rendered link from doc
			$label = substr( $this->renderer->doc, $length );
			$this->renderer->doc = substr_replace( $this->renderer->doc, '',
												   $length );

		}
		else
		{
			// embed internal link in header

			resolve_pageid( getNS( self::getPageID() ), $href, $exists );
			$label = $this->renderer->internallink( $href, $label, NULL, true );

		}


		return $label;

	}


	/**
	 * Parses the code between opening and closing tag for data definition of
	 * table to be managed/provided by tag.
	 *
	 * @throws Exception
	 *
	 * @param string $code data definition found in Wiki code
	 */

	protected function parseDefinition( $code )
	{

		$failed = $out = $primaries = $uniques = $visibles = array();
		$aclColumn = null;

		// parse line by line
		foreach ( explode( "\n", $code ) as $index => $line )
		{

			// skip empty lines and comments
			$line = trim( $line );
			if ( ( $line === '' ) || ( $line[0] == '#' ) ||
				 ( ( $line[0] == '/' ) && ( $line[1] == '/' ) ) )
				// comment or empty line -> skip
				continue;


			// split line into at most 4 comma-separated fields with last
			// containing optional set of attributes/options
			$parsed = $this->splitDefinitionLine( $line );
			if ( $parsed === false )
				throw new Exception( sprintf( $this->getLang( 'definline' ), $index ) );

			list( $colName, $rawType, $label, $attributes ) = $parsed;


			// validate and normalize fields
			try
			{

				// ***** 1st field: the column name *****
				// normalize column name dropping invalid all invalid characters
				$colName = preg_replace( '/[^\w]/', '_', $colName );

				if ( $out[$colName] )
					throw new Exception( sprintf( $this->getLang( 'defdouble' ), $colName ) );


				// ***** 4th field: additional options *****
				$options = array();

				foreach ( $attributes as $name => $value )
				{

					// process option
					$name = strtolower( $name );
					switch ( $name )
					{

						// marks to demand a non-empty value on editing
						// (column in table is defined as NOT NULL)
						case 'required' :
						case 'req' :
							$options['required'] = self::asBool( $value );
							break;

						// marks column to be included in listing records
						// (if no column is marked visible this way, all columns
						//  are visible by default)
						case 'visible' :
							if ( self::asBool( $value ) )
							{
								$visibles[] = $colName;
								$options['visible'] = true;
							}
							break;

						// selects column to be (part of) primary key index
						case 'primary' :
							if ( self::asBool( $value ) )
							{
								$primaries[] = $colName;
								$options['primary'] = $options['required'] = true;
							}
							break;

						// selects explicit index in order of fields/columns
						// (this is used on inspecting/editing records, only)
						case 'tabindex' :
							if ( ctype_digit( trim( $value ) ) )
								$options['tabindex'] = intval( $value );
							break;

						// selects how to handle this column being defined as
						// boolean in 2nd field:
						//  yesno - column is CHAR(1) with values 'y' or 'n'
						//  xmark - column is CHAR(1) with values 'x' or ' '
						//  int   - column is TINYINT with values 1 or 0
						// default is "yesno" ... selected below!
						case 'booltype' :
							$value = strtolower( $value );
							if ( !in_array( $value, array( 'yesno', 'int', 'xmark' ) ) )
								throw new Exception( $this->getLang( 'invalidbool' ) );
							$options['booltype'] = $value;
							break;

						case 'readonly' :
							// mark column as read-only
							// (so even admin mustn't edit it)
							$options['readonly'] = self::asBool( $value );
							break;

						case 'aliasing' :
							if ( !is_string( $value ) ||
								 ( trim( $value ) === '' ) )
								throw new Exception( $this->getLang( 'noaliased' ) );

							// mark column as read-only
							// (so even admin mustn't edit it)
							$options['aliasing'] = $value;

							// changing aliased term isn't expected to work
							// --> so implicitly mark column as read-only
							$options['readonly'] = true;
							break;

						default :
							// support shortcurt for tabindex-definition:
							// "@<integer>" is same as "tabindex=<integer>"
							if ( preg_match( '/^@(\d+)$/', $name, $matches ) )
								$options['tabindex'] = intval( $matches[1] );
							else if ( substr( $name, 0, 6 ) == 'unique' )
							{
								// column is (part of) one of several unique
								// indices
								// --> an optional integer after name "unique"
								//     selects group of columns being part of
								//     same unique index

								$group = trim( substr( $name, 6 ) );
								if ( ctype_digit( $group ) || ( $group === '' ))
								{

									if ( !is_array( $uniques[$group] ) )
										$uniques[$group] = array();

									$uniques[$group][] = $colName;

								}
							}
							else if ( ctype_digit( trim( $name ) ) )
								// raw digits as token are selecting length of
								// column (e.g. maximum length of stored text)
								$options['length'] = intval( $name );
							else
								// all else single tokens are handled like
								// assigning boolean value true ...
								$options[$name] = $value;

					}
				}

				if ( $this->options['view'] )
					$options['readonly'] = true;


				// ***** 2nd field: the column's type *****
				$sqldef = $format = null;

				// derive basic column type and its format from type definition
				$rawType  = trim( $rawType );
				$typeName = strtolower( trim( strtok( $rawType, ' ' ) ) );

				switch ( $typeName )
				{

					case 'int' :
					case 'integer' :
						$type   = 'integer';
						$format = 'integer';
						break;

					case 'image' :
						$type   = 'data';
						$format = 'image';
						break;

					case 'blob' :
					case 'binary' :
					case 'file' :
					case 'data' :
						$type   = 'data';
						$format = 'file';
						break;

					case 'real' :
					case 'float' :
					case 'double' :
						$type   = 'real';
						$format = 'real';
						break;

					case 'money' :
					case 'monetary' :
						$type   = 'decimal';
						$format = 'monetary';
						break;

					case 'numeric' :
					case 'decimal' :
						$type   = 'decimal';
						$format = 'real';
						break;

					case 'time' :
						$type   = $rawType;
						$format = $typeName;
						break;

					case 'date' :
					case 'datetime' :
						$type   = $options['unixts'] ? 'integer' : $rawType;
						$format = $typeName;
						break;

					case 'url' :
					case 'link' :
					case 'href' :
						$type   = 'text';
						$format = 'url';
						break;

					case 'email' :
					case 'mail' :
						$type   = 'text';
						$format = 'email';
						break;

					case 'phone' :
					case 'fax' :
						$type   = 'text';
						$format = $typeName;
						break;

					case '' :
					case 'string' :
					case 'text' :
					case 'name' :
					case 'char' :
						$type   = 'text';
						$format = 'text';
						break;

					case 'acl' :
						if ( !is_null( $aclColumn ) )
							throw new Exception( $this->getLang( 'multiacl' ) );

						$type      = 'text';
						$format    = 'acl';
						$aclColumn = $colName;
						break;

					case 'check' :
					case 'mark' :
					case 'boolean' :
					case 'bool' :
						if ( !$options['booltype'] )
							$options['booltype'] = 'yesno';

						if ( $options['booltype'] == 'int' )
						{
							$type   = 'integer';
							$sqldef = 'tinyint';
						}
						else
						{
							$type   = 'bool';
							$sqldef = 'char';
							$options['length'] = 1;
						}

						$format = 'bool';
						break;

					case 'enum' :
						// get set of selectable enumeration elements provided
						// after type name separated by slash or semicolon
						$options['selectables'] = preg_split( '#[/;]+#', strtok( '' ) );

						$max = 0;
						foreach ( $options['selectables'] as &$selectable )
						{

							$selectable = trim( $selectable );

							$max = max( $max, strlen( $selectable ) );

						}

						if ( !$max )
							throw new Exception( $this->getLang( 'emptyenum' ) );

						if ( !isset( $options['length'] ) )
							$options['length'] = $max;

						$type   = 'enum';
						$format = 'enum';
						$sqldef = ( $max > 1 ) ? 'varchar' : 'char';
						break;

					case 'related' :
						// get statement for listing selectable options
						$readerSQL = trim( strtok( '' ) );
						if ( !$this->getConf( 'customviews' ) )
							throw new Exception( $this->getLang( 'readerdisabled' ) );
						if ( !preg_match( '/^SELECT\s/i', $readerSQL ) )
							throw new Exception( $this->getLang( 'invalidreader' ) );


						// read selectable options querying provided statement
						$selectables = array();

						$resultset = $this->db->query( $readerSQL );
						if ( $resultset )
							while ( is_array( $related = $resultset->fetch( PDO::FETCH_NUM ) ) )
							{

								if ( !ctype_digit( trim( $related[0] ) ) )
									throw new Exception( $this->getLang( 'invalidreader' ) );

								$selectables[intval( $related[0] )] = trim( $related[1] );

							}

						// workaround for bug in PHP prior to 5.2.10
						// see http://bugs.php.net/bug.php?id=35793
						$resultset->closeCursor();
						$resultset = null;

						if ( empty( $selectables ) )
							throw new Exception( $this->getLang( 'emptyenum' ) );

						$options['selectables'] = $selectables;


						$type   = 'related';
						$format = 'related';
						$sqldef = 'integer';
						break;

					default :
						throw new Exception( sprintf( $this->getLang( 'badtype' ),
											  $typeName ) );

				}

				// derive SQL type definition from parsed type of column
				switch ( $type )
				{

					case 'data' :
						if ( $options['length'] > 0 )
							$sqldef = 'varbinary';
						else if ( $this->driver == 'mssql' )
							// untested: is this proper name of driver??
							$sqldef = 'varbinary';
						else
							$sqldef = 'longblob';
						break;

					case 'text' :
						$sqldef = ( $options['length'] > 0 ) ? 'varchar' : 'text';
						break;

					case 'decimal' :
						$sqldef = ( $this->driver == 'sqlite' ) ? 'real'
																: 'decimal';
						break;

					case 'date' :
					case 'datetime' :
					case 'time' :
					default :
						if ( is_null( $sqldef ) )
							$sqldef = $type;

				}


				$sqldef  = $colName . ' ' . strtoupper( $sqldef );

				if ( $options['length'] > 0 )
					if ( in_array( $type, array( 'text', 'enum', 'integer', 'related' ) ) )
						$sqldef .= '(' . $options['length'] . ')';

				$sqldef .= $options['required'] ? ' NOT NULL' : ' NULL';



				// add parsed definition to resulting set
				if ( $this->getConf( 'aliasing' ) ||
					 !is_string( $options['aliasing'] ) )
					$out[$colName] = array(
											'column'     => trim( $colName ),
											'type'       => $type,
											'format'     => $format,
											'definition' => $sqldef,
											'options'    => $options,
											'label'      => trim( $label ),
											'isColumn'   => true,
											);

			}
			catch ( Exception $e )
			{
				$failed[] = sprintf( $this->getLang( 'baddef' ),
									 $index + 1, $e->getMessage() );
			}
		}


		if ( empty( $failed ) )
		{
			// post-process column definitions

			if ( empty( $out ) )
				throw new Exception( $this->getLang( 'emptydef' ) );


			if ( empty( $visibles ) )
				// no column is explicitly marked visible
				// --> make them all visible
				foreach ( $out as &$def )
					$def['options']['visible'] = ( $def['format'] == 'acl' ) ? 1 : true;


			// append primary key - either as defined or automatically
			if ( empty( $primaries ) )
			{
				// missing explicit definition of primary key

				if ( $out['id'] )
				{
					// choose column "id" and turn it into primary key

					if ( !$out['id']['options']['required'] )
						// declare it as NOT NULL explicitly
						$out['id']['definition'] .= ' NOT NULL';

					$out['id']['definition'] .= ' PRIMARY KEY';

				}
				else
					// there is no column "id"
					// --> PREPEND one automatically
					$out = array_merge( array( 'id' => array(
											'column'     => 'id',
											'type'       => 'integer',
											'format'     => 'integer',
											'definition' => 'id INTEGER NOT ' .
															'NULL PRIMARY KEY',
											'options'    => array(),
											'label'      => '#',
											'isColumn'   => true,
											'auto_id'    => true,
											) ), $out );

			}
			else
				// append definition of defined primary key index
				$out['.PRIMARY_KEYS'] = array(
											'definition' => 'PRIMARY KEY ( ' .
															implode( ', ',
															$primaries ) . ' )',
											'primaries'  => $primaries
											);


			// next ensure to properly include all uniqueness constraints
			if ( count( $uniques ) )
			{

				foreach ( $uniques as $i => $group )
					if ( count( $group ) == 1 )
					{
						// apply uniqueness constraint on single column
						$col = array_shift( $group );
						$out[$col]['definition'] .= ' UNIQUE';
					}
					else
						// append separate unique index on joined columns
						$out['.UNIQUE-' . $i] = array(
											'definition' => 'UNIQUE ( ' .
															implode( ', ',
															$group ) . ' )',
											);

			}


			$this->meta = $out;

			$session =& self::getSession();
			$session['definition'] = $this->meta;

		}
		else
			// encountered one or more parser errors
			// --> throw exception
			throw new Exception( implode( "<br />\n", $failed ) );


		$this->options['aclColumn'] = $aclColumn;

	}


	/**
	 * Parses provided value for containing some human-readable form of a
	 * boolean value.
	 *
	 * @param mixed $in value to parse
	 * @param boolean $nullIfUnparseable if true, method returns null if $in
	 *                                   can't be parsed as boolean value
	 * @return boolean boolean counterpart of provided value
	 */

	protected static function asBool( $in, $nullIfUnparseable = false )
	{

		if ( is_numeric( $in ) )
			return ( $in != 0 );

		if ( is_string( $in ) )
		{

			if ( preg_match( '/^(n|no|f|false|off)$/i', trim( $in ) ) )
				return false;

			if ( preg_match( '/^(y|yes|t|true|on)$/i', trim( $in ) ) )
				return true;

		}

		if ( ctype_digit( trim( $in ) ) )
			return ( intval( $in ) != 0 );

		if ( ( $in === true ) || ( $in === false ) )
			return $in;

		return $nullIfUnparseable ? null : (bool) $in;

	}


	/**
	 * Retrieves list of columns included in table's primary key.
	 *
	 * @return array list of column names
	 */

	protected function getPrimaryKeyColumns()
	{

		if ( !$this->meta )
			return array();

		if ( $this->meta['.PRIMARY_KEYS'] )
			return $this->meta['.PRIMARY_KEYS']['primaries'];

		return array( 'id' );

	}


	/**
	 * Retrieves column name of single-column integer primary key or false.
	 *
	 * The method returns false if
	 *  - none or multiple columns are set as primary key
	 *  - single column isn't of type integer
	 *
	 * @return string/false name of column, false if condition does not match
	 */

	protected function getSingleNumericPrimaryKey()
	{

		$primaries = $this->getPrimaryKeyColumns();

		if ( count( $primaries ) != 1 )
			return false;

		$column = array_shift( $primaries );

		if ( isset( $this->meta[$column]['type'] ) )
			if ( $this->meta[$column]['type'] != 'integer' )
				return false;


		return $column;

	}


	/**
	 * Detects if either a table or a single column in a table exists or not.
	 *
	 * @param string $table name of table to test
	 * @param string $column optional name of single column in table to test
	 * @return boolean true if test succeeds, false otherwise
	 */

	protected function exists( $table, $column = null )
	{

		if ( is_null( $column ) )
			$sql  = 'SELECT COUNT(*) FROM ' . $table;
		else
			$sql  = 'SELECT COUNT(' . $column . ') FROM ' . $table;

		try
		{

			$s = $this->db->query( $sql );

			if ( $s instanceof PDOStatement )
				$s->closeCursor();

			return true;

		}
		catch ( PDOException $e )
		{

			if ( in_array( $e->getCode(), array( '42S02' ) ) )
				return false;

			if ( stripos( $e->getMessage(), 'no such table' ) !== false )
				return false;

			if ( !is_null( $column ) )
				if ( stripos( $e->getMessage(), 'no such column' ) !== false )
					return false;

			throw $e;

		}
	}


	/**
	 * Obtains next ID for use in an "auto-incrementing ID" column.
	 *
	 * On every call this method provides another, recently unused ID for the
	 * given table. This is achieved by using a separate table in current DB.
	 *
	 * @throws Exception
	 *
	 * @param string $table name of table
	 * @param boolean $nestedTransaction set true, if you call in a transaction
	 * @return integer next available ID for assigning
	 */

	protected function nextID( $table, $nestedTransaction = false )
	{

		// automatically create pool for tracking auto-incrementing row IDs
		if ( !$this->exists( '__keys' ) )
			if ( $this->db->query( <<<EOT
CREATE TABLE __keys (
	tablename CHAR(64) NOT NULL PRIMARY KEY,
	recent INTEGER NOT NULL
)
EOT
									) === false )
				throw new PDOException( $this->getLang( 'idnotable' ) );



		if ( !$nestedTransaction && !$this->db->beginTransaction() )
			throw new PDOException( $this->getLang( 'notransact' ) );

		try
		{

			// read recently assigned auto-incrementing row ID on table
			$st = $this->db->prepare('SELECT recent FROM __keys WHERE tablename=?');
			if ( !$st )
				throw new PDOException( $this->getLang( 'idreadprepare' ) );

			if ( !$st->execute( array( $table ) ) )
				throw new PDOException( $this->getLang( 'idreadexecute' ) );


			$row = $st->fetch( PDO::FETCH_NUM );
			if ( is_array( $row ) )
			{
				// got record -> assigned ID before --> increment and update
				$sql    = 'UPDATE __keys SET recent=? WHERE tablename=?';
				$nextID = ++$row[0];
			}
			else
			{
				// no record -> assigning ID for the first time --> start with 1
				$sql    = 'INSERT INTO __keys (recent,tablename) VALUES (?,?)';
				$nextID = 1;
			}

			$st->closeCursor();


			// write new/updated track of auto-incrementing ID on current table
			$st = $this->db->prepare( $sql );
			if ( !$st )
				throw new PDOException( $this->getLang( 'idwriteprepare' ) );

			if ( !$st->execute( array( $nextID, $table ) ) )
				throw new PDOException( $this->getLang( 'idwriteexecute' ) );



			if ( !$nestedTransaction && !$this->db->commit() )
				throw new PDOException( $this->getLang( 'idcommit' ) );


			return $nextID;

		}
		catch ( PDOException $e )
		{

			if ( !$nestedTransaction && !$this->db->rollBack() )
				throw new PDOException( $this->getLang( 'idrollback' ) );

			throw new Exception( $this->getLang( 'idnoid' ) );

		}
	}


	/**
	 * Retrieves name of current "user" (providing temporary name for guests)
	 *
	 * @throws Exception
	 * @return string
	 */

	protected static function currentUser()
	{

		$currentUser = $_SERVER['REMOTE_USER'];
		if ( !$currentUser )
		{
			// there is no authenticated user ...
			// --> try using user's sesion ID instead

			if ( !session_id() )
				throw new Exception( $this->getLang( 'userunknown' ) );

			$currentUser = '|' . session_id();

		}


		return $currentUser;

	}


	/**
	 * Adds entry to log of changes on a table and record.
	 *
	 * Omit $rowid to mark change of a whole table.
	 *
	 * @throws Exception
	 *
	 * @param string $action name of change action
	 * @param string $table name of table
	 * @param integer $rowid ID of record changed
	 */

	protected function log( $action, $table, $rowid = null )
	{

		// automatically create log table in DB
		if ( !$this->exists( '__log' ) )
			if ( $this->db->query( <<<EOT
CREATE TABLE __log (
	tablename CHAR(64) NOT NULL,
	rowid INTEGER NULL,
	action CHAR(8) NOT NULL,
	username CHAR(64) NOT NULL,
	ctime INTEGER NOT NULL
)
EOT
									) === false )
				throw new PDOException( $this->getLang( 'lognotable' ) );



		// add entry to log
		$st = $this->db->prepare( 'INSERT INTO __log (tablename,rowid,action,' .
								  'username,ctime) VALUES (?,?,?,?,?)' );
		if ( !$st )
			throw new PDOException( $this->getLang( 'logprepare' ) );

		if ( !$st->execute( array( $table, intval( $rowid ), $action,
								   self::currentUser(), time() ) ) )
			throw new PDOException( $this->getLang( 'logexecute' ) );


		// in a local SQLite database: drop all log records older than 30 days
		if ( $this->driver == 'sqlite' )
			$this->db->query( 'DELETE FROM __log WHERE ctime<'.(time()-30*86400));

	}


	/**
	 * Obtains a lock.
	 *
	 * The lock is either related to a whole table (if $rowid is omitted or
	 * null) or a single record in that table selected by its unique (!!)
	 * numeric ID. Obtaining record-related lock is rejected if whole table is
	 * currently locked by some other user.
	 *
	 * NOTE! Locking records basically works with unique (!!) numeric IDs, only.
	 *
	 * @param string $table name of table lock is related to
	 * @param integer $rowid unique (!!) ID of record lock is related to, omit
	 *                       or set 0/null for a table-related lock
	 * @param boolean $inTransaction if true, the caller started transaction
	 * @param boolean $checkOnly if true, an available lock isn't obtained
	 *                           actually
	 * @return boolean true on success, false on failure
	 */

	protected function obtainLock( $table, $rowid = null, $inTransaction = false, $checkOnly = false, $innerTest = false )
	{

		// automatically create DB's pool of obtained locks
		if ( !$this->exists( '__locks' ) )
			if ( $this->db->query( <<<EOT
CREATE TABLE __locks (
	tablename CHAR(64) NOT NULL,
	record INTEGER NOT NULL,
	username CHAR(64) NOT NULL,
	obtained INTEGER NOT NULL,
	PRIMARY KEY ( tablename, record )
)
EOT
								) === false )
				return false;



		// get "name" of current user (supporting guests as well)
		$currentUser = self::currentUser();

		// normalize $rowid selecting single record or whole table (==0)
		$rowid = intval( $rowid );


		if ( !$inTransaction && !$this->db->beginTransaction() )
			return false;

		try
		{

			if ( !$innerTest )
			{

				if ( $rowid )
				{
					// obtaining lock on record is rejected if whole table is locked

					if ( !$this->obtainLock( $table, null, true, true, true ) )
						throw new PDOException( $this->getLang( 'locksuperlocked' ) );

				}
				else
				{
					// obtaining lock on whole table is rejected if some other user
					// has locked at least one record

					$st = $this->db->prepare( 'SELECT COUNT(*) FROM __locks ' .
											  'WHERE tablename=? AND record<>0 ' .
											  'AND username<>?' );
					if ( !$st )
						throw new PDOException( $this->getLang( 'locksubprepare' ) );

					if ( !$st->execute( array( $table, $currentUser ) ) )
						throw new PDOException( $this->getLang( 'locksubexecute' ) );


					$count = $st->fetch( PDO::FETCH_NUM );
					if ( $count && ( $count[0] > 0 ) )
						throw new PDOException( $this->getLang( 'locksublocked' ) );

					$st->closeCursor();

				}
			}



			// check for existing lock on selected entity
			$st = $this->db->prepare( 'SELECT username,obtained FROM __locks ' .
									  'WHERE tablename=? AND record=?' );
			if ( !$st )
				throw new PDOException( $this->getLang( 'lockreadprepare' ) );

			if ( !$st->execute( array( $table, $rowid ) ) )
				throw new PDOException( $this->getLang( 'lockreadexecute' ) );

			$lock = $st->fetchAll( PDO::FETCH_NUM );
			if ( is_array( $lock ) && count( $lock ) )
			{
				// there is a lock

				$lock = array_shift( $lock );

				$user = trim( $lock[0] );
				if ( $user !== $currentUser )
				{
					// lock is obtained by different user

					// - check whether it's outdated (1 hour) or not
					if ( time() - intval( $lock[1] ) < $this->getConf( 'locktime' ) )
						// no -> reject to obtain
						throw new PDOException( $this->getLang( 'locklocked' ) );

				}

				$sql = 'UPDATE __locks SET username=?,obtained=? ' .
						'WHERE tablename=? AND record=?';

			}
			else
				// resource isn't locked -> obtain lock now
				$sql = 'INSERT INTO __locks (username,obtained,tablename,record) ' .
					   'VALUES (?,?,?,?)';


			if ( !$checkOnly )
			{

				$st = $this->db->prepare( $sql );
				if ( !$st )
					throw new PDOException( $this->getLang( 'lockwriteprepare' ) );

				if ( !$st->execute( array( $currentUser, time(), $table, $rowid ) ))
					throw new PDOException( $this->getLang( 'lockwriteexecute' ) );

			}


			if ( !$inTransaction && !$this->db->commit() )
				throw new PDOException( $this->getLang( 'lockcommit' ) );

			return true;

		}
		catch ( PDOException $e )
		{

			if ( !$inTransaction && !$this->db->rollBack() )
				throw new PDOException( $this->getLang( 'lockrollback' ) );

			return false;

		}
	}


	/**
	 * Releases recently obtained lock.
	 *
	 * The lock is either related to a whole table (if $rowid is omitted or
	 * null) or a single record in that table selected by its numeric ID.
	 *
	 * NOTE! Locking records basically works with unique (!!) numeric IDs, only.
	 *
	 * @param string $table name of table lock is related to
	 * @param integer $rowid unique (!!) ID of record lock is related to, omit
	 *                       or set 0/null for a table-related lock
	 * @param boolean $inTransaction if true, the caller started transaction
	 * @return boolean true on successfully releasing lock, false on failure
	 */

	protected function releaseLock( $table, $rowid = null, $inTransaction = false )
	{

		if ( !$this->exists( '__locks' ) )
			// didn't create pool of locks before
			// --> succeed to release without hassle
			return true;


		// get "name" of current user (supporting guests)
		$currentUser = self::currentUser();

		// $rowid is non-zero or zero for obtaining lock on whole table
		$rowid = intval( $rowid );


		if ( !$inTransaction && !$this->db->beginTransaction() )
			return false;

		try
		{

			if ( !$this->obtainLock( $table, $rowid, true, true, true ) )
				// user didn't obtain that lock before ... succeed to release
				return true;


			// check for existing lock on selected entity
			$st = $this->db->prepare( 'DELETE FROM __locks WHERE tablename=? AND ' .
									  'record=? AND username=?' );
			if ( !$st )
				throw new PDOException( $this->getLang( 'releaseprepare' ) );

			if ( !$st->execute( array( $table, $rowid, $currentUser ) ) )
				throw new PDOException( $this->getLang( 'releaseexecute' ) );


			if ( !$inTransaction && !$this->db->commit() )
				throw new PDOException( $this->getLang( 'releasecommit' ) );


			return true;

		}
		catch ( PDOException $e )
		{

			if ( !$inTransaction && !$this->db->rollBack() )
				throw new PDOException( $this->getLang( 'releaserollback' ) );

			return false;

		}
	}


	/**
	 * Provides link for retrieving media data in selected record's column.
	 *
	 * @throws Exception
	 *
	 * @param integer $rowid unique numeric ID of selected record
	 * @param string $column name of column in record containing media to retrieve
	 * @param boolean $forDownload if true the media is requested for download
	 * @return string URL for retrieving media
	 */

	final protected function mediaLink( $rowid, $column, $forDownload = false,
										$rowACL = null )
	{

		// validate media selected for external retrieval
		$rowid  = intval( $rowid );
		$column = trim( $column );

		if ( !$rowid || ( $this->meta[$column]['type'] != 'data' ) )
			throw new Exception( sprintf( $this->getLang( 'medianomedia' ), $column ) );


		$idColumn = $this->getSingleNumericPrimaryKey();

		if ( !$idColumn )
			throw new Exception( $this->getLang( 'mediana' ) );


		// gain access on pool of hashing salts in session space
		$session =& $this->getSession();

		if ( !is_array( $session['linkedMediaSalts'] ) )
			$session['linkedMediaSalts'] = array();


		// compile selector describing media to be retrieved
		$selector = array( '@'.$this->dsn, $this->authSlot, $this->table,
						   $column, $idColumn, $rowid, $this->getPageID(),
						   $this->getIndex(), self::currentUser(),
						   $_SERVER['REMOTE_ADDR'] );

		// use unsalted hash to find salt in internal pool for salted hash
		$hash = sha1( implode( '/', $selector ) );


		// check authorization to download file first
		if ( !$this->isAuthorizedMulti( $rowACL, $this->options, 'maydownload', 'mayview', true ) )
		{
			// lacking authorization
			// --> drop salt used in media frontend to proof authorization
			unset( $session['linkedMediaSalts'][$hash] );
			throw new Exception( $this->getLang( 'mediadenied' ) );
		}


		// create salt on requesting media for the first time ...
		if ( !$session['linkedMediaSalts'][$hash] )
		{
			mt_srand( intval( microtime( true ) * 1000 ) );
			$session['linkedMediaSalts'][$hash] = uniqid( mt_rand(), true );
		}


		// derive URL components
		$source = urlencode( base64_encode( gzcompress( $selector = serialize( $selector ) ) ) );
		$hash   = urlencode( base64_encode( self::ssha( $selector, $session['linkedMediaSalts'][$hash] ) ) );


		// return URL for retrieving media
		return DOKU_BASE . DB2_PATH . "media.php?a=$source&b=$hash&d=" . ( $forDownload ? 1 : 0 );

	}


	/**
	 * Provides link for retrieving files virtually attached to table
	 * (e.g. CSV exports).
	 *
	 * @throws Exception
	 *
	 * @param boolean $forDownload if true the media is requested for download
	 * @return string URL for retrieving media
	 */

	final protected function attachmentLink( $mode, $authorization, $forDownload = true, $rowACL = null )
	{

		// gain access on pool of hashing salts in session space
		$session =& $this->getSession();

		if ( !is_array( $session['linkedMediaSalts'] ) )
			$session['linkedMediaSalts'] = array();


		// compile selector describing media to be retrieved
		$selector = array( '@'.$this->dsn, $this->authSlot, $this->table,'fake',
						   'id', 1, $this->getPageID(), $this->getIndex(),
						   self::currentUser(), $_SERVER['REMOTE_ADDR'] );

		// use unsalted hash to find salt in internal pool for salted hash
		$hash = sha1( implode( '/', $selector ) );


		// check authorization to request attached file
		if ( !$this->isAuthorizedMulti( $rowACL, $this->options, 'may' . $authorization ) )
		{
			// lacking authorization
			// --> drop salt used in media frontend to proof authorization
			unset( $session['linkedMediaSalts'][$hash] );
			throw new Exception( $this->getLang( 'mediadenied' ) );
		}


		// create salt on requesting media for the first time ...
		if ( !$session['linkedMediaSalts'][$hash] )
		{
			mt_srand( intval( microtime( true ) * 1000 ) );
			$session['linkedMediaSalts'][$hash] = uniqid( mt_rand(), true );
		}


		// derive URL components
		$source = urlencode( base64_encode( gzcompress( $selector = serialize( $selector ) ) ) );
		$hash   = urlencode( base64_encode( self::ssha( $selector, $session['linkedMediaSalts'][$hash] ) ) );


		// return URL for retrieving media
		return DOKU_BASE . DB2_PATH . "media.php?a=$source&b=$hash&m=$mode&d=" . ( $forDownload ? 1 : 0 );

	}


	/**
	 * Provides external link for retrieving media in session
	 *
	 * @param string $sessionFileKey name of section in session containing file
	 * @param boolean $forDownload if true, the link requests file for download
	 * @return string URL for retrieving file
	 */

	public function editorSessionMediaLink( $column, $forDownload = false )
	{

		$session =& $this->getEditorSession();

		if ( !is_array( $session[$column] ) )
			throw new Exception( $this->getLang( 'medianoeditor' ) );


		// compile selector describing media to be retrieved
		$selector = array( $this->getPageID(), $this->getIndex(), $column );

		// derive URL components
		$source = urlencode( base64_encode( serialize( $selector ) ) );


		// return URL for retrieving media
		return DOKU_BASE . DB2_PATH . "media.php?s=$source&d=" . ( $forDownload ? 1 : 0 );

	}


	/**
	 * Gets "SSHA1" hash without including the salt (in opposition to what is
	 * usually done in RFC-conforming SSHA1 algorithm).
	 *
	 * @param string $data data to hash
	 * @param string $salt salt to use on hashing
	 * @return string salted hash on $data
	 */

	final public static function ssha( $data, $salt )
	{
		return sha1( $salt . $data . sha1( $data . $salt, true ) );
	}





	/**
	 * Parses provided string for SQL-like Date/Time representation.
	 *
	 * @param string $in representation of Date/Time in SQL format
	 * @param boolean $skipTime if true, the time information is dropped/skipped
	 * @return integer UNIX timestamp for parsed date/time
	 */

	protected static function parseDBDateTime( $in, $skipTime = false )
	{

		list( $date, $time ) = preg_split( '/t|(\s+)/i', trim( $in ) );

		list( $year, $month, $day )     = explode( '-', trim( $date ) );
		list( $hour, $minute, $second ) = explode( ':', trim( $time ) );

		if ( ( intval( $hour ) == 0 ) && ( intval( $minute ) == 0 ) &&
			 ( intval( $second ) == 0 ) && ( intval( $year ) == 0 ) &&
			 ( intval( $month ) == 0 ) && ( intval( $day ) == 0 ) )
			return 0;

		if ( $skipTime )
		{
			$hour   = 12;
			$minute = $second = 0;
		}

		return mktime( intval( $hour ), intval( $minute ), intval( $second ),
					   intval( $month ), intval( $day ), intval( $year ) );

	}


	protected static function parseInternalDate( $in )
	{

		$formats = array(
						'#^(\d{4})/(\d+)/(\d+)$#' => array( 'year', 'month', 'day' ),
						'#^(\d+)/(\d+)/(\d+)$#'   => array( 'month', 'day', 'year' ),
						'/^(\d+)-(\d+)-(\d+)$/'   => array( 'year', 'month', 'day' ),
						'/^(\d+)\.(\d+)\.(\d+)$/' => array( 'day', 'month', 'year' ),
						);

		$in = preg_replace( '/\s+/', '', $in );

		foreach ( $formats as $pattern => $order )
			if ( preg_match( $pattern, $in, $matches ) )
			{

				$out = array();

				foreach ( $order as $key => $value )
					$out[$value] = intval( $matches[$key+1] );

				if ( $out['year'] < 100 )
				{
					if ( $out['year'] > 40 )
						$out['year'] += 1900;
					else
						$out['year'] += 2000;
				}

				return $out;

			}


		return false;

	}


	protected static function parseInternalTime( $in )
	{

		$formats = array(
						'/^(\d+):(\d+)(:(\d+))?$/'   => array( 'hour', 'minute', 3 => 'second' ),
						);

		$in = preg_replace( '/\s+/', '', $in );

		foreach ( $formats as $pattern => $order )
			if ( preg_match( $pattern, $in, $matches ) )
			{

				$out = array();

				foreach ( $order as $key => $value )
					$out[$value] = sprintf( '%02d', intval( $matches[$key+1] ) );

				return $out;

			}


		return false;

	}


	/**
	 * Parses provided ACL rules definition returning contained rules as array.
	 *
	 */

	protected function parseACLRule( $in, $mayThrow = false, $useLabels = false )
	{

		$out = array();

		$rules = preg_split( '/\s*;\s*/', trim( $in ) );
		foreach ( $rules as $major => $rule )
			if ( !preg_match( '/^(may\S+)\s*=\s*(\S.*)$/i', $rule, $matches ) )
			{

				if ( $mayThrow )
					throw new Exception( $this->getLang( 'badaclrule' ) );

				continue;

			}
			else
			{

				$objects = preg_split( '/\s*,\s*/', trim( $matches[2] ) );

				foreach ( $objects as $minor => $object )
					if ( !preg_match( '/^(!?)\s*(\S+)$/', $object, $subs ) )
					{

						if ( $mayThrow )
							throw new Exception( $this->getLang( 'badaclrule' ) );

						unset( $objects[$minor] );

					}
					else
						$objects[$minor] = $subs[1] . $subs[2];

				if ( empty( $objects ) )
					unset( $rules[$major] );
				else if ( $useLabels )
					$out[strtolower($matches[1])] = implode( ',', $objects );
				else
					$rules[$major] = strtolower( $matches[1] ) . '=' .
									 implode( ',', $objects );

			}


		return $useLabels ? $out : $rules;

	}


	/**
	 * Serves in processing method replaceMarkup by replacing single occurrence
	 * of markup sequence.
	 *
	 * An empty string is returned if markup sequence isn't detected.
	 *
	 * @internal
	 *
	 * @param array $matches set of matches according to used PCRE pattern
	 * @return string replacement string
	 */

	public function __replaceMarkupCB( $matches )
	{

		$keyword = strtolower( $matches[1] );
		switch ( $keyword )
		{

			case 'wiki.user' :
				return $_SERVER['REMOTE_USER'];
			case 'wiki.groups' :
				return implode( ',', $GLOBALS['USERINFO']['grps'] );
			case 'wiki.page' :
				return $this->getPageID();

			default :
				$group = trim( strtok( $matches[1], '.' ) );
				$arg   = trim( strtok( '' ) );

				switch ( strtolower( $group ) )
				{

					case 'date' :
						return ( $arg !== '' ) ? date( $arg ) : '';

					default :
						if ( is_array( $this->__replaceMarkupVarspace ) )
						{

							if ( isset( $this->__replaceMarkupVarspace[$keyword] ) )
								return $this->__replaceMarkupVarspace[$keyword];

							if ( isset( $this->__replaceMarkupVarspace[$group][$arg] ) )
								$this->__replaceMarkupVarspace[$group][$arg];

						}

						return '';

				}

		}
	}


	/**
	 * Replaces all occurrences of %{whatever} by a value actually related to
	 * the internally defined keyword "whatever".
	 *
	 * @param string $in string to parse for markup sequences to be replaced
	 * @return string string with all markup replaced
	 */

	public function replaceMarkup( $in, $varspace = array() )
	{

		if ( strpos( $in, '%{' ) !== false )
		{

			$this->__replaceMarkupVarspace = $varspace;

			$in = preg_replace_callback( '/%{([^}]+)}/', array( &$this,
										 '__replaceMarkupCB' ), $in );

		}

		return $in;

	}


	/**
	 * Provides initial value of a column used on creating new record.
	 *
	 * @param string $column name of column
	 * @param array $def definition of column
	 * @return mixed value in internal format
	 */

	protected function getInitialValue( $column, $def )
	{

		if ( $def['type'] == 'data' )
			return null;

		if ( $def['options']['nodefault'] )
			return null;


		$default = $this->replaceMarkup( trim( $def['options']['default'] ) );

		switch ( $def['format'] )
		{

			case 'bool' :
				return self::asBool( $default );

			case 'enum' :
			case 'related' :
				$value = array_search( $default, $def['options']['selectables'] );
				if ( $value === false )
				{

					$value = null;

					if ( ctype_digit( $default ) )
					{

						$default = intval( $default );

						if ( ( $default > 0 ) && ( $default <= count( $def['options']['selectables'] ) ) )
						{

							if ( $def['format'] == 'enum' )
								$value = $default - 1;
							else
								$value = $default;

						}
					}
				}

				return $value;

			default :
				try
				{
					return $this->inputToInternal( $default, $def );
				}
				catch ( Exception $e )
				{
					return null;
				}

		}
	}


	/**
	 * Converts value from format used in DB into format used internally.
	 *
	 * @param integer $rowid ID of row containing given value
	 * @param string $column name of column
	 * @param mixed $value value in DB
	 * @param array $def definition of column
	 * @return mixed value in internal format
	 */

	protected function valueFromDB( $rowid, $column, $value, $def )
	{

		switch ( $def['format'] )
		{

			case 'image' :
			case 'file' :
				if ( is_null( $value ) )
					return null;

				if ( $value === '||' )
					return null;

				// parse file for internally used structure
				$a = strpos( $value, '|' );
				if ( !$a )
					// externally provided file --> don't touch
					return ( strlen( $value ) > 0 );

				$b = strpos( $value, '|', $a + 1 );
				if ( !$b )
					// externally provided file --> don't touch
					return true;

				$temp = array(
							'mime' => substr( $value, 0, $a ),
							'name' => substr( $value, $a + 1, $b - $a - 1 ),
							'file' => substr( $value, $b + 1 ),
							);

				if ( !preg_match( '#^[a-z0-9-]+/[+a-z0-9-]+$#i', $temp['mime'] ))
					// externally provided file --> don't touch
					return true;

				if ( trim( $temp['name'] ) === '' )
					// externally provided file --> don't touch
					return true;

				return $temp;

			case 'date' :
				if ( $def['options']['unixts'] )
					return $value;

				if ( ( trim( $value ) === '' ) || ( $value == '0000-00-00' ) )
					return 0;

				return self::parseDBDateTime( $value, true );

			case 'time' :
				return $value;

			case 'datetime' :
				if ( $def['options']['unixts'] )
					return $value;

				$value = substr( $value, 0, 19 );
				if ( ( trim( $value ) === '' ) ||
					 ( $value == '0000-00-00T00:00:00' ) ||
					 ( $value == '0000-00-00 00:00:00' ) )
					return 0;

				return self::parseDBDateTime( $value, false );

			case 'bool' :
				$value = trim( $value );
				switch ( $def['options']['booltype'] )
				{

					case 'int' :
						return ( intval( $value ) != 0 );

					case 'xmark' :
						return ( strtolower( $value[0] ) == 'x' );

					case 'yesno' :
					default :
						return ( strtolower( $value[0] ) == 'y' );

				}

			case 'enum' :
				$value = trim( $value );
				$value = array_search( $value, $def['options']['selectables'] );
				if ( $value === false )
					$value = null;
				else
					$value = intval( $value );

				break;

			case 'related' :
				if ( is_numeric( $value ) )
					$value = intval( $value );

				break;

			case 'monetary' :
			case 'real' :
				/** @todo manage decimal point conversions */

			case 'url' :
			case 'email' :
			case 'phone' :
			case 'fax' :
			case 'text' :
			case 'integer' :
			case 'acl' :
				// keep value as is ...

		}

		return $value;

	}


	/**
	 * Converts value from format used internally into format used in DB.
	 *
	 * @param integer $rowid ID of row containing given value
	 * @param string $column name of column
	 * @param mixed $value value in internal format
	 * @param array $def definition of column
	 * @return mixed value in DB format, false to omit this value on writing
	 *               back to database, null to store NULL
	 */

	protected function valueToDB( $rowid, &$column, $value, $def )
	{

		if ( $def['options']['readonly'] )
			// always omit writing columns marked as read-only
			return false;


		if ( is_null( $value ) && !$def['options']['notnull'] &&
			 ( $def['format'] != 'bool' ) )
			return null;



		switch ( $def['format'] )
		{

			case 'image' :
			case 'file' :
				if ( is_bool( $value ) )
					// don't change this file ...
					return false;

				if ( is_string( $value ) || is_null( $value ) )
					// got a raw file or nothing ... write as is
					return strval( $value );

				if ( !is_array( $value ) || !is_string( $value['file'] ) )
					throw new Exception( $this->getLang( 'fileinvalid' ) );

				// internally managed files are serialized prior to saving
				return "$value[mime]|$value[name]|$value[file]";

			case 'date' :
				if ( !$value )
				{

					if ( $def['options']['unixts'] )
						return 0;

					return $def['options']['notnull'] ? '0000-00-00' : null;

				}

				return $def['options']['unixts'] ? $value : date( 'Y-m-d',intval( $value ) );

			case 'time' :
				$time = self::parseInternalTime( $value );
				return $time ? "$time[hour]:$time[minute]:$time[second]" : '';

			case 'datetime' :
				if ( !$value )
				{

					if ( $def['options']['unixts'] )
						return 0;

					return $def['options']['notnull'] ? '0000-00-00T00:00:00' : null;

				}

				return $def['options']['unixts'] ? $value : date( 'Y-m-d\TH:i:s', intval( $value ) );

			case 'bool' :
				switch ( $def['options']['booltype'] )
				{

					case 'xmark' :
						return $value ? 'x' : ' ';

					case 'int' :
						return $value ? 1 : 0;

					case 'yesno' :
					default :
						return $value ? 'y' : 'n';

				}

			case 'enum' :
				$value = $def['options']['selectables'][$value];
				break;

			case 'monetary' :
			case 'real' :
				/** @todo manage decimal point conversions */
				if ( is_null( $value ) )
					$value = '0.00';
				break;

			case 'acl' :
				$this->dropRowACL( $rowid );

			case 'url' :
			case 'email' :
			case 'phone' :
			case 'fax' :
			case 'text' :
				if ( is_null( $value ) )
					$value = '';
				break;

			case 'related' :
			case 'integer' :
				$value = intval( $value );
				break;

		}

		return $value;

	}


	/**
	 * Processes and validates input value on selected column.
	 *
	 * Set $column false to skip transferring this input value into session
	 * storage, e.g. to skip overwriting mark on externally provided file for
	 * keeping it untouched.
	 *
	 * @param integer $rowid ID of row containing given value
	 * @param string $column name of column
	 * @param mixed $value input value, optionally adjusted on return
	 * @param mixed $inStore value stored in editor's session
	 * @param array $def definition of column
	 * @return string error message to be rendered next to field, null if okay
	 */

	protected function checkValue( $rowid, &$column, $value, &$inStore, $def )
	{

		// pre-validate some selected formats
		switch ( $def['format'] )
		{

			case 'image' :
			case 'file' :
				if ( is_bool( $inStore ) )
					// don't touch externally provided files
					return;

				if ( trim( $value ) !== '' )
				{
					// handle request for dropping file here
					// --> reset value in store prior to processing any upload
					$inStore = null;
					$value   = null;
				}


				// check for available upload

				$upload = $_FILES['db2dodata'.$column];

				// reduce array (shared by all currently open single-record editors)
				$in  = array();
				$idx = $this->getIndex();

				if ( is_array( $upload ) )
					foreach ( $upload as $key => $list )
						if ( is_array( $list ) && isset( $list[$idx] ) )
							$in[$key] = $list[$idx];


				if ( !is_array( $in ) || ( $in['error'] == UPLOAD_ERR_NO_FILE ) )
				{
					// there is no upload for current field
					// --> keep existing value/state
					$value = $inStore;
					break;
				}

				if ( $in['error'] !== UPLOAD_ERR_OK )
					return sprintf( $this->getLang( 'filebadupload' ), $in['error'] );

				if ( $in['size'] === 0 )
					return $this->getLang( 'filenoupload' );

				if ( $def['options']['accept'] )
				{

					if ( !preg_match( $def['options']['accept'], $in['type'] ) )
						return $this->getLang( 'filebadmime' );

				}
				else if ( $def['format'] == 'image' )
				{

					list( $major, $minor ) = explode( '/', $in['type'] );

					if ( strtolower( trim( $major ) ) !== 'image' )
						return $this->getLang( 'filenoimage' );

				}


				$data = file_get_contents( $in['tmp_name'] );
				if ( strlen( $data ) != $in['size'] )
					return $this->getLang( 'fileincomplete' );

				// finally accept upload replacing any existing file in session
				$value = array(
								'name' => $in['name'],
								'mime' => $in['type'],
								'file' => $data,
								);

				break;

			case 'bool' :
				$value = ( $value != false );

				if ( !$value && $def['options']['required'] )
					return $this->getLang( 'markrequired' );

				break;

			case 'enum' :
			case 'related' :
				$value = trim( $value );

				// options of an enumeration are selected by
				//  a) an integer index starting at 1 for first defined option
				//  b) the option's label requiring case-sensitive match

				if ( $value === '' )
					$value = null;
				else
				{
					// test for selection by an option's label, first

					if ( ctype_digit( trim( $value ) ) )
						$new = false;
					else
						$new = array_search( $value, $def['options']['selectables'] );

					if ( $new !== false )
						$value = $new;
					else
					{
						// no match -> test for selection by integer index, then

						if ( !ctype_digit( $value ) )
							return $this->getLang( 'badenum' );

						$value = intval( $value );


						if ( $def['format'] == 'related' )
						{
							// integers select related by unique ID

							if ( !array_key_exists( $value, $def['options']['selectables'] ) )
								return $this->getLang( 'badenum' );

						}
						else if ( intval( $value ) > 0 )
						{
							// integers select option by 1-based index

							if ( intval( $value ) > count( $def['options']['selectables'] ) )
								// index out of range
								return $this->getLang( 'badenum' );

							$value--;

						}
						else
							// not matching any defined option's label or index
							return $this->getLang( 'badenum' );

					}
				}

				break;

		}



		try
		{

			// transform value to internally used format
			$value = $this->inputToInternal( $value, $def );


			// enforce to get value if required
			if ( $def['options']['required'] )
				if ( is_null( $value ) )
					throw new Exception( $this->getLang( 'required' ) );


			// save input value in editor's session
			$inStore = $value;

		}
		catch ( Exception $e )
		{
			return $e->getMessage();
		}
	}


	public function inputToInternal( $value, $def )
	{

		switch ( $def['format'] )
		{

			case 'image' :
			case 'file' :
				if ( !is_bool( $value ) )
					if ( !is_array( $value ) || ( trim( implode( '', $value ) ) === '' ) )
						$value = null;

				break;

			case 'integer' :
				$value = trim( $value );
				if ( $value === '' )
					$value = null;
				else
				{

					if ( $value !== '' )
						if ( !preg_match( '/^[+-]?\d+$/', $value ) )
							throw new Exception( $this->getLang( 'badinteger' ) );

					$value = intval( $value );

				}
				break;

			case 'date' :
				$value = strtolower( trim( $value ) );
				if ( $value === '' )
					$value = null;
				else if ( in_array( $value, array( $this->getLang( 'today' ), $this->getLang( 'now' ) ) ) )
					$value = time();
				else
				{

					$value = self::parseInternalDate( $value );
					if ( $value === false )
						throw new Exception( $this->getLang( 'baddate' ) );

					$value = mktime( 12, 0, 0, $value['month'], $value['day'], $value['year'] );

				}
				break;

			case 'time' :
				$value = strtolower( trim( $value ) );
				if ( $value !== '' )
				{

					if ( $value == $this->getLang( 'now' ) )
						$value = date( 'H:i:s' );
					else if ( !self::parseInternalTime( $value ) )
						throw new Exception( $this->getLang( 'badtime' ) );

				}
				else
					$value = null;

				break;

			case 'datetime' :
				$value = strtolower( trim( $value ) );
				if ( $value === '' )
					$value = null;
				else if ( $value === $this->getLang( 'now' ) )
					$value = time();
				else
				{

					list( $date, $time, $tail ) = preg_split( '/[\s,;]+/', $value );

					$date = trim( $date );
					$time = trim( $time );
					$tail = trim( $tail );

					if ( ( $date === '' ) || ( $time === '' ) )
						throw new Exception( $this->getLang( 'baddatetime' ) );
					if ( $tail !== '' )
						throw new Exception( $this->getLang( 'baddatetimetail' ) );

					if ( trim( $date ) === $this->getLang( 'today' ) )
					{
						$date = array(
										'year'  => idate( 'Y' ),
										'month' => idate( 'm' ),
										'day'   => idate( 'd' ),
										);
					}
					else
					{
						$date = self::parseInternalDate( $date );
						if ( $date === false )
							throw new Exception( $this->getLang( 'baddate' ) );
					}

					$time = self::parseInternalTime( $time );
					if ( $time === false )
						throw new Exception( $this->getLang( 'badtime' ) );

					$value = mktime( $time['hour'], $time['minute'],
									 $time['second'], $date['month'],
									 $date['day'], $date['year'] );

				}
				break;

			case 'phone' :
			case 'fax' :
				$value = trim( $value );
				if ( $value !== '' )
				{

					$temp = preg_replace( '/\s+/', '', $value );
					$temp = preg_replace( '/\(([^)]+)\)/', '\1', $temp );

					if ( !preg_match( '#^\+?(\d+(([-/]|/-)\d+)*)+$#', $temp ) )
						throw new Exception( $this->getLang( 'badphonefax' ) );

				}
				else
					$value = null;

				break;

			case 'monetary' :
				$value = trim( $value );
				if ( $value !== '' )
				{

					$valuePattern = '/[+-]?\d+([.,]\d)?/';

					if ( !preg_match( $valuePattern, $value ) )
						throw new Exception( $this->getLang( 'badmoney' ) );

					// validate to have one out of these formats:
					//  0,34 or "USD 34,00" or "5 EUR" ...
					$temp = preg_split( $valuePattern, $value );

					if ( trim( $temp[1] ) === '' ) unset( $temp[1] );
					if ( trim( $temp[0] ) === '' ) unset( $temp[0] );

					if ( count( $temp ) > 1 )
						throw new Exception( $this->getLang( 'badmoneytail' ) );

				}
				else
					$value = null;

				break;

			case 'real' :
				$value = trim( $value );
				if ( $value === '' )
					$value = null;
				else
				{

					if ( !preg_match( '/^[+-]?\d+([.,]\d+)?$/', $value ) )
						throw new Exception( $this->getLang( 'badfloat' ) );

					$value = doubleval( strtr( $value, ',', '.' ) );

				}

				break;

			case 'url' :
				$value = trim( $value );
				if ( $value !== '' )
				{

					$info  = parse_url( $value );
					if ( !is_array( $info ) )
						throw new Exception( $this->getLang( 'badurl' ) );
					if ( ( $value !== '' ) && !$info['scheme'] )
						throw new Exception( $this->getLang( 'badurlnoabs' ) );

				}
				else
					$value = null;

				break;

			case 'email' :
				$value = trim( $value );
				if ( $value !== '' )
				{

					if ( !mail_isvalid( $value ) )
						throw new Exception( $this->getLang( 'badmail' ) );

					if ( $this->getConf( 'checkmaildomains' ) != false )
					{

						list( $box, $domain ) = explode( '@', $value );
						$ip = gethostbyname( $domain );
						if ( ( $ip === $domain ) || ( ip2long( $ip ) === false ) )
							if ( !getmxrr( $domain, $dummy ) )
								throw new Exception( $this->getLang( 'badmailunknown' ) );

					}
				}
				else
					$value = null;

				break;

			case 'acl' :
				// row-based ACL rule
				if ( $this->isAuthorized( $this->options['mayadmin'] ) )
					$value = implode( ';', $this->parseACLRule( trim( $value ), true ) );
				else
					$value = null;

				break;

			case 'text' :
				// everything's fine here

			default :
				if ( trim( $value ) === '' )
					$value = null;

		}

		return $value;

	}


	/**
	 * Renders field for single-record editor.
	 *
	 * To keep rendering form in a single place this method is called to render
	 * code for opening ($rowid === true) and closing ($rowid === false) form,
	 * as well. All other arguments contain no useful information then.
	 *
	 * On closing form two buttons should be included for saving and cancelling.
	 * The buttons' names are ____save and ____cancel accordingly.
	 *
	 * @param integer $rowid ID of row containing given value
	 * @param string $column name of column
	 * @param mixed $value input value
	 * @param array $def definition of column
	 * @param string $error optional error message to be rendered next to field
	 * @param boolean $readOnly if true, the field should be rendered read-only
	 * @return string HTML code representing single form field
	 */

	protected function renderField( $rowid, $column, $value, $def, $error, $readOnly, &$rowACL )
	{

		if ( $rowid === true )
		{
			// opening form

			return <<<EOT
<table class="database2-single-editor">
 <tbody>
EOT;

		}
		else if ( $rowid === false )
		{
			// close form
			if ( $readOnly && $this->input['optnoctl'] )
			{
				// caller requested to hide controls

				$buttons = '';

			}
			else
			{

				$buttons = array();
				if ( !$readOnly )
					$buttons[] = '<input type="submit" name="' .
								 $this->varname( '____save' ) .
								 '" value="' . $this->getLang( 'cmdsave' ) . '" />';

				if ( is_array( $column ) && count( $column ) && !$this->options['simplenav'] )
				{

					foreach ( $column as $k => $option )
						$column[$k] = '<option value="' . $option[0] . '"' .
									  ( $option[2] ? ' selected="selected"' : '' ) .
									  '>' . $option[1] . '</option>';

					$mode = '<select name="' . $this->varname( '____nav' ) .
							'">' . implode( "\n", $column ) . '</select>';

					$name = $this->getLang( 'cmdokay' );

				}
				else
				{
					$mode = null;
					$name = $this->getLang( 'cmdreturn' );
				}


				$buttons[] = '<input type="submit" name="' .
							 $this->varname( '____cancel' ) .
							 '" value="' . ( $readOnly ? $name : ( $mode ? $this->getLang( 'cmdnosave' ) : $this->getLang( 'cmdcancel' ) ) ) .
							 '" />';

				if ( $mode )
					$buttons[] = $mode;


				$buttons = implode( ' ', $buttons );

				$buttons = <<<EOT
  <tr class="buttons">
   <td class="label"></td>
   <td class="field">
    $buttons
   </td>
  </tr>
EOT;

			}

			return <<<EOT
  $buttons
 </tbody>
</table>
EOT;

		}
		else if ( $def['options']['readonly'] || $readOnly )
		{

			$code = $this->renderValue( $rowid, $column, $value, $def, true, true, $rowACL );
			if ( is_null( $code ) && ( !$def['options']['alwaysshow'] ||
									   ( $def['format'] == 'acl' ) ) )
				return null;

			$label = $def['label'] ? $def['label'] : $column;

			return <<<EOT
  <tr>
   <td class="label">$label:</td>
   <td class="field">$code</td>
  </tr>$error
EOT;

		}
		else
		{
			// single form element

			$mayView = $this->isAuthorizedMulti( $rowACL, $def['options'], 'mayview', null, true );

			if ( $rowACL['mayedit'] || $def['options']['mayedit'] )
				if ( !$this->isAuthorizedMulti( $rowACL, $def['options'], 'mayedit' ) )
					return $this->renderField( $rowid, $column, $value, $def, '', true, $rowACL );

			// user may edit this column ...
			if ( !$mayView )
				// ... but mustn't view it ...
				if ( $rowid )
					// it's an existing record -> reject editing field
					return null;
				// ELSE: editing new records doesn't actually imply
				//       viewing something existing in this field



			$name     = $this->varname( 'data' . $column );
			$selector = '';
			$code     = null;

			if ( $error )
				$error = <<<EOT
  <tr class="local-error">
   <td class="label"></td>
   <td class="field local-error">
    $error
   </td>
  </tr>
EOT;


			switch ( $def['format'] )
			{

				case 'image' :
				case 'file' :
					if ( ( $value === true ) || ( $value === false ) )
						$code = '<span class="disabled">' . $this->getLang( 'fileexternal' ) . '</span>';
					else
					{

						$current = $this->renderValue( $rowid, $column, $value, $def, true, true, $rowACL );
						if ( !is_null( $current ) )
							$current = "<input type=\"submit\" name=\"$name\" value=\"" . $this->getLang( 'cmddropfile' ) . "\" onclick=\"return confirm('" . strtr( $this->getLang( 'confirmdropfile' ), array( '"' => '&quot;' ) ) . "');\"/></td></tr><tr><td class=\"label\" /><td class=\"field\">$current";

						$code = "<input type=\"file\" name=\"$name\" />$current";

					}
					break;

				case 'acl' :
					if ( !$this->isAuthorized( $this->options['mayadmin'] ) )
						return null;

				case 'text' :
					if ( !isset( $def['options']['length'] ) ||
						 ( $def['options']['length'] > 255 ) )
					{
						$value = strtr( $value, array( '<' => '&lt;' ) );
						$code  = "<textarea name=\"$name\" class=\"text\" rows=\"5\" cols=\"50\">$value</textarea>";
					}
					break;

				case 'bool' :
					$checked = $value ? ' checked="checked"' : '';
					$code    = "<input name=\"$name\" type=\"checkbox\" value=\"1\"$checked />";
					break;

				case 'enum' :
				case 'related' :
					$options     = $def['options']['selectables'];
					$selectedAny = false;

					foreach ( $options as $index => $option )
					{

						$selected     = ( $index === $value ) ? ' selected="selected"' : '';
						$selectedAny |= $selected;

						if ( $def['format'] == 'enum' )
							$key = $index + 1;
						else
							$key = $index;

						$options[$index] = "<option value=\"$key\"$selected>$option</option>";

					}

					if ( !$def['options']['required'] || !$selectedAny )
						array_unshift( $options, '<option value="">' . $this->getLang( 'enumunselected' ) . '</option>' );

					$options = implode( "\n", $options );

					$code    = "<select name=\"$name\">$options</select>";

					break;

				case 'date' :
					if ( !$value )
						$value = '';
					else if ( preg_match( '/-?\d+/', trim( $value ) ) )
						$value = strftime( strtok( trim( $this->getConf( 'dformat' ) ), ' ' ), intval( $value ) );
					break;

				case 'datetime' :
					if ( !$value )
						$value = '';
					else if ( preg_match( '/-?\d+/', trim( $value ) ) )
						$value = strftime( $this->getConf( 'dformat' ), intval( $value ) );
					break;

				case 'time' :

				case 'url' :
				case 'email' :
				case 'phone' :
				case 'fax' :
				case 'monetary' :
				case 'real' :
				case 'integer' :
				default :

			}

			if ( is_null( $code ) )
			{

				$value = strtr( $value, array( '"' => '&quot;' ) );


				$defaultSizes = array(
									'date'     => strlen( strftime( $this->getConf( 'dformat' ) ) ),
									'datetime' => strlen( strftime( $this->getConf( 'dformat' ) ) ),
									'time'     => 8,
									'url'      => 255,
									'email'    => 255,
									'phone'    => 16,
									'fax'      => 16,
									'monetary' => 16,
									'real'     => 16,
									'integer'  => 10,
									);

				if ( $def['options']['length'] )
					$maxlen = $def['options']['length'];
				else if ( $defaultSizes[$def['format']] )
					$maxlen = $defaultSizes[$def['format']];
				else
					$maxlen = 30;

				$size = min( $maxlen, 50 );
				$code = "<input name=\"$name\" type=\"text\" value=\"$value\" size=\"$size\" maxlength=\"$maxlen\" class=\"text $def[format]\" />$selector";

			}


			$label = $def['label'] ? $def['label'] : $column;

			return <<<EOT
  <tr>
   <td class="label">$label:</td>
   <td class="field">$code</td>
  </tr>$error
EOT;

		}

	}


	/**
	 * Renders internally formatted value for read-only output (e.g. on listing
	 * table).
	 *
	 * @param integer $rowid ID of row containing given value
	 * @param string $column name of column
	 * @param mixed $in value returned from DB
	 * @param string $type defined type of value
	 * @param boolean $mayBeSkipped if true, the method should return null if
	 *                              value is unset
	 * @param boolean $inEditor if true, the value comes from an editor's
	 *                          session (important for rendering attached
	 *                          files/images)
	 * @return string code describing value for read-only output
	 */

	protected function renderValue( $rowid, $column, $value, $def,
									$mayBeSkipped = false, $inEditor = false, $rowACL = null )
	{

		if ( !$this->isAuthorizedMulti( $rowACL, $def['options'], 'mayview', null, true ) )
			return $mayBeSkipped ? null : '<em>' . $this->getLang( 'hidden' ) . '</em>';

		if ( is_null( $value ) && ( $def['type'] != 'data' ) )
			return $mayBeSkipped ? null : '';


		switch( $def['format'] )
		{

			case 'text' :
				if ( ( trim( $value ) === '' ) && $mayBeSkipped )
					return null;

				if ( $this->options['wikimarkup'] || $def['options']['wikimarkup'] )
				{
					// apply processing of contained wiki markup

					$value = p_render( 'xhtml', p_get_instructions( $value ), $info );

					if ( preg_match( '#^<p>((.|\n)+)</p>$#i', trim( $value ), $matches ) )
						$value = $matches[1];

				}

				// strip some special tags from text (preventing some XSS attacks)
				$value = $this->stripTags( $value );

				return trim( $value );

			case 'image' :
			case 'file' :
				if ( $value === true )
					return '<span class="info">' . $this->getLang( 'fileexternalfound' ) . '</span>';
				else if ( $value === false )
					return '<span class="info">' . $this->getLang( 'fileexternalnotfound' ) . '</span>';
				else
				{

					$temp = is_array( $value ) ? trim( implode( '', $value ) )
											   : '';
					if ( $temp === '' )
						return $mayBeSkipped ? null : '<em>' . $this->getLang( 'none' ) . '</em>';

					if ( $inEditor )
						$url = $this->editorSessionMediaLink( $column, ( $def['format'] != 'image' ) );
					else
						$url = $this->mediaLink( $rowid, $column, ( $def['format'] != 'image' ) );

					if ( !$inEditor )
						$url .= '&thumb=150';

					if ( $def['format'] === 'image' )
						return "<img src=\"$url\" alt=\"" . sprintf( $this->getLang( 'fileimagealt' ), $column, $value['mime'] ) . "\" />";

					$mayDownload = $this->isAuthorizedMulti( $rowACL, $this->options, 'maydownload' );
					if ( !$mayDownload )
						return '<em>' . $this->getLang( 'filedenied' ) . '</em>';

					return "<a href=\"$url\" title=\"" .
							$this->getLang( 'filedlhint' ) . '">' .
							$this->getLang( 'cmddl' ) . '</a>';

				}
				break;

			case 'email' :
				if ( ( trim( $value ) === '' ) && $mayBeSkipped )
					return null;

				return DokuWiki_Plugin::email( $value, $email );

			case 'url' :
				if ( ( trim( $value ) === '' ) && $mayBeSkipped )
					return null;

				return DokuWiki_Plugin::external_link( $value );

			case 'phone' :
			case 'fax' :
				if ( ( trim( $value ) === '' ) && $mayBeSkipped )
					return null;

				return $value;

			case 'bool' :
				if ( !$value && $mayBeSkipped )
					return null;
				return $value ? '&#10007;' : '&ndash;';

			case 'date' :
				if ( !$value )
					return $mayBeSkipped ? null : '';

				return strftime( strtok( trim( $this->getConf( 'dformat' ) ), ' ' ), $value );

			case 'datetime' :
				if ( !$value )
					return $mayBeSkipped ? null : '';

				return strftime( $this->getConf( 'dformat' ), $value );

			case 'time' :
				if ( $value && ( substr( $value, -3 ) == ':00' ) &&
					 ( strlen( $value ) > 5 ) )
					$value = substr( $value, 0, -3 );

				return $value;

			case 'integer' :
			case 'monetary' :
			case 'real' :
				if ( !$value && $mayBeSkipped )
					return null;

				return $value;

			case 'enum' :
			case 'related' :
				if ( is_integer( $value ) )
					$value = $def['options']['selectables'][$value];

				return $value;

			case 'acl' :
				if ( !$this->isAuthorized( $this->options['mayadmin'] ) )
					// admins may see this value, only
					return null;

				return $value;

			default :
				return $value;

		}
	}
}


class Database2_Admin extends Database2
{

	public function __construct( DokuWiki_Admin_Plugin $integrator )
	{
		$this->renderer   = '';
		$this->integrator = $integrator;
	}


	protected function getIndex()
	{
		return 0;
	}


	public function getColumnsMeta( $ignoreMissingMeta = false )
	{
		return array();
	}


	protected function render( $code )
	{
		$this->renderer .= $code;
	}


	public function getCode()
	{
		return $this->renderer;
	}


	public function getDB()
	{
		return $this->db;
	}
}

// vim:ts=4:sw=4:et:
