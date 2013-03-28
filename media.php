<?php


/**
 * Frontend for retrieving images and files attached to a database record or
 * available in an editor session.
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


/*
 * initialize DokuWiki
 */

if ( !defined( 'DOKU_INC' ) )
{

	foreach ( array( dirname( __FILE__ ), $_SERVER['SCRIPT_FILENAME'] ) as $path )
	{

		while ( $path != '/' )
			if ( is_file( $path . '/doku.php' ) && is_dir( $path . '/lib' ) )
				break;
			else
				$path = dirname( $path );

		if ( $path != '/' )
			break;

	}

	if ( $path && ( $path != '/' ) )
		define( 'DOKU_INC', $path . '/' );
	else
		die( "Failed to locate DokuWiki installation folder!");

}


ob_start();	// capture and flush all initial output of DokuWiki

include_once( DOKU_INC . 'inc/init.php' );
include_once( DOKU_INC . 'inc/common.php' );
include_once( DOKU_INC . 'inc/events.php' );
include_once( DOKU_INC . 'inc/pageutils.php' );
include_once( DOKU_INC . 'inc/html.php' );
include_once( DOKU_INC . 'inc/auth.php' );
include_once( DOKU_INC . 'inc/actions.php' );
include_once( DOKU_INC . 'lib/plugin.php' );

ob_end_clean();




try
{

	if ( $_SERVER['REQUEST_METHOD'] != 'GET' )
		throw new Exception( 'invalid request method', 400 );


	@session_start();


	if ( $_GET['s'] )
	{
		// read media from session

		$source = @unserialize( @base64_decode( $_GET['s'] ) );
		if ( !$source )
			throw new Exception( 'invalid request', 400 );


		list( $pageID, $ioIndex, $column ) = $source;

		$session = $_SESSION['database2'][$pageID]['tables'][$ioIndex]['editors'][$column];
		if ( !is_array( $session ) )
			throw new Exception( 'no such media', 404 );

		$data = $session['file'];
		$mime = $session['mime'];
		$name = $session['name'];

		if ( !$data )
			throw new Exception( 'file is empty', 404 );

		if ( !$name )
			$_GET['d'] = false;

		if ( !$mime )
			throw new Exception( 'invalid media', 403 );

	}
	else
	{
		// read media from database

		$source = @unserialize( @gzuncompress( @base64_decode( $_GET['a'] ) ) );
		$hash   = trim( @base64_decode( $_GET['b'] ) );

		if ( !is_array( $source ) || ( $hash === '' ) )
			throw new Exception( 'invalid request parameter', 400 );

		list( $dsn, $authSlot, $table, $column, $idColumn, $rowid, $pageID,
			  $ioIndex, $user, $addr ) = $source;

		if ( $addr !== $_SERVER['REMOTE_ADDR'] )
			throw new Exception( 'invalid request context', 400 );



		$t    = sha1( implode( '/', $source ) );
		$salt = $_SESSION['database2'][$pageID]['tables'][$ioIndex]['linkedMediaSalts'][$t];

		if ( !is_string( $salt ) || ( trim( $salt ) === '' ) )
			throw new Exception( 'access denied', 403 );



		// include Database2 ...
		$libFile = '/database2.php';

		// support working with development version if available and
		// selected to enable development in a production wiki
		// (as used on wiki.nihilum.de)
		if ( is_file( dirname( __FILE__ ) . '/database2.dev.php' ) )
			if ( $_SESSION['useDevIP'] )
				if ( $_SESSION['useDevIP'] == $_SERVER['REMOTE_ADDR'] )
					$libFile = '/database2.dev.php';

		include_once( dirname( __FILE__ ) . $libFile );

		// ... and derive it to gain access on internals to
		class Database2_media extends Database2
		{

			public function __construct( $dsn, $authSlot, $table, $ioIndex,
										 $pageId )
			{

				if ( !$this->connect( $dsn, $authSlot ) )
					throw new Exception( 'database not available/not found', 404 );

				$table = trim( $table );
				if ( !self::isValidName( $table ) )
					throw new Exception( 'invalid media selector (table)', 400 );

				$this->table   = $table;
				$this->ioIndex = $ioIndex;

				$this->explicitPageID = $pageId;

			}

			final public function getMedia( $column, $idColumn, $rowid )
			{

				$column   = trim( $column );
				$idColumn = trim( $idColumn );
				$rowid    = intval( $rowid );

				if ( !self::isValidName( $column ) ||
					 !self::isValidName( $idColumn ) || !$rowid )
					throw new Exception( 'invalid media selector', 400 );


				$st = $this->getLink()->prepare( 'SELECT ' . $column .
												 ' FROM ' . $this->table .
												 ' WHERE ' . $idColumn . '=?' );
				if ( !$st )
					throw new Exception( 'failed to prepare retrieval', 500 );

				if ( !$st->execute( array( $rowid ) ) )
					throw new Exception( 'failed to retrieve', 500 );


				$media = $st->fetch( PDO::FETCH_NUM );
				if ( !is_array( $media ) )
					throw new Exception( 'no such media', 404 );

				$st->closeCursor();

				return $media[0];

			}

			final public function printTable()
			{
				return $this->showTable( false, true, true, null, true );
			}
		}




		// check salted hash provided in URL (used to proove authorization)
		$providedHash = @base64_decode( $_GET['b'] );
		if ( !$providedHash )
			throw new Exception( 'access denied, missing valid hash', 403 );

		$source = serialize( $source );
		if ( Database2::ssha( $source, $salt ) !== $providedHash )
			throw new Exception( 'access denied, invalid hash', 403 );



		// query database for selected media file
		$db = new Database2_media( $dsn, $authSlot, $table, $ioIndex, $pageID );

		switch ( $_GET['m'] )
		{

			case 'print' :
				$data = $db->printTable();

				$title = sprintf( $db->getLang( 'printtitle' ), $table );

				$data = <<<EOT
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<title>$title</title>
<link rel="stylesheet" type="text/css" href="./print.css" />
</head>
<body class="print" onload="print()">
$data
</body>
</html>
EOT;

				// add meta information for proper download
				$mime = 'text/html; charset=utf-8';
				$name = $table . '_print_' . date( 'Y-m-d_H-i' ) . '.html';

				break;

			case 'xml' :
			case 'csv' :

				// get list of all columns not containing binary data
				$meta = $db->getColumnsMeta();
				$cols = array();
				foreach ( $meta as $column => $def )
					if ( $def['isColumn'] && ( $def['type'] != 'data' ) )
						$cols[$column] = $column;

				// retrieve records
				$rows   = $db->__recordsList( $cols );

				// extract separate header row
				$header = array_shift( array_slice( $rows, 0, 1 ) );
				foreach ( $header as $column => $data )
					$header[$column] = $meta[$column]['label'] ? $meta[$column]['label'] : $column;

				// write header row to CSV
				$data = $db->__csvLine( $header );

				// write all fetched rows to CSV
				foreach ( $rows as $row )
					$data .= $db->__csvLine( $row );


				// add meta information for proper download
				$mime = 'text/csv; charset=utf-8';
				$name = $table . '_export_' . date( 'Y-m-d_H-i' ) . '.csv';

				break;

			case 'log' :

				$st = $db->getLink()->prepare( 'SELECT rowid,action,username,ctime FROM __log WHERE tablename=? ORDER BY ctime DESC' );
				if ( !$st )
					throw new Exception( 'failed to prepare viewing log', 500 );

				if ( !$st->execute( array( $table ) ) )
					throw new Exception( 'failed to execute viewing log', 500 );


				// retrieve records
				$rows = $st->fetchAll( PDO::FETCH_ASSOC );
				if ( empty( $rows ) )
					$data = '';
				else
				{

					// write header row to CSV
					$data = $db->__csvLine( array_keys( array_shift( array_slice( $rows, 0, 1 ) ) ) );

					// write all fetched rows to CSV
					foreach ( $rows as $row )
					{

						$row['ctime'] = date( 'r', $row['ctime'] );

						$data .= $db->__csvLine( $row );

					}
				}


				// add meta information for proper download
				$mime = 'text/csv; charset=utf-8';
				$name = $table . '_log_' . date( 'Y-m-d_H-i' ) . '.csv';

				break;

			default :
				$data = $db->getMedia( $column, $idColumn, $rowid );

				// strip off MIME and filename embedded in retrieved file
				$sepPos = strpos( $data, '|' );
				if ( !$sepPos || ( $sepPos > 256 ) )
					throw new Exception( 'untyped data record', 403 );

				$mime = substr( $data, 0, $sepPos );
				$data = substr( $data, $sepPos + 1 );


				$sepPos = strpos( $data, '|' );
				if ( !$sepPos || ( $sepPos > 256 ) )
					// missing filename, thus reject to support download
					$_GET['d'] = false;
				else
					$name = substr( $data, 0, $sepPos );

				$data = substr( $data, $sepPos + 1 );

		}
	}




	// provide file ...
	if ( $_GET['d'] )
		// ... for download otionally
		header( 'Content-Disposition: attachment; filename=' . $name );


	if ( isset( $_GET['thumb'] ) )
	{
		// derive thumbnail from file

		list( $major, $minor ) = explode( '/', $mime );

		// try to parse file as image first
		$img = ( $major == 'image' ) ? @imagecreatefromstring( $data ) : false;
		if ( $img )
		{

			// get desired size of thumbnail
			$temp = ( trim( $_GET['thumb'] ) === '' ) ? '200x150' : $_GET['thumb'];

			list( $width, $height ) = explode( 'x', $temp );

			$width  = intval( $width )  ? intval( $width )  : null;
			$height = intval( $height ) ? intval( $height ) : null;

			if ( !$width || !$height )
			{

				if ( !$width && !$height )
					$width = 200;

				$aspect = ( imagesx( $img ) / imagesy( $img ) );

				if ( $width )
					$height = round( $width  / $aspect );
				else
					$width  = round( $height * $aspect );

			}

			if ( ( $width < imagesx( $img ) ) || ( $height < imagesy( $img ) ) )
			{
				// scale-down image to thumbnail size

				$dest = imagecreatetruecolor( $width, $height );

				imagesavealpha( $dest, ( $minor === 'png' ) );
				imagealphablending( $dest, ( $minor !== 'png' ) );

				if ( $minor !== 'png' )
					imagecolorallocate( $dest, 255, 255, 255 );

				imagecopyresampled( $dest, $img, 0, 0, 0, 0,
									imagesx( $dest ), imagesy( $dest ),
									imagesx( $img ), imagesy( $img ) );

			}
			else
				// keep original, as it is smaller than requested thumbnail size
				$dest = $img;


			// render thumbnail image
			ob_start();

			switch ( $minor )
			{

				case 'gif' :
					imagegif( $dest );
					break;

				case 'jpeg' :
				case 'pjpeg' :
				case 'jpg' :
					imagejpeg( $dest );
					break;

				default :
					$mime = 'image/png';
				case 'png' :
					imagepng( $dest );

			}

			$data = ob_get_clean();

		}
	}



	header( 'Content-Type: ' . $mime );

	echo $data;

}
catch ( Exception $e )
{

	header( 'HTTP/1.1 ' . $e->getCode() . ' ' . $e->getMessage() );

	echo $e->getMessage();

}


?>