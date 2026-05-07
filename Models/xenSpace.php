<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenSpace extends Base_Model {

	public $id;

	public static $DEBUG = [];

	public function __construct( $hash ) {

		// Call the parent class constructor For DB 
		// And Other Globally Accessible Variables
        parent::__construct();

		$sql = "SELECT id FROM xentask.spaces WHERE hash = '" . $hash . "'";

        $id = $this->DB->fetch1( $sql );

        if( !empty( $id ) )
			$this->id = $id;
		else
			Response::returnJSON( 400, ['err_code' => 'S0002', 'err_string' => 'INVALID SPACE: ' . $hash, $sql => $sql ] );

	}

	static function getSpaces( $workspace_id, $USER ) {

		$DB     = new Database( );
	
		$sql    = "SELECT * FROM xentask.workspaces WHERE hash = '" . $workspace_id . "'";
	
		$RES    = $DB->query( $sql );
	
		$WS     = $DB->fetch_assoc( $RES );
	
		$spacesInfo = [];
	
		//	First Grab the Space ID's That The User Has Access To
		$sql = "SELECT * FROM spaces
				WHERE workspace_id = " . $WS['id'] . " 
				AND deleted = 0
				AND is_private = 0 OR (
					is_private = 1 
					AND deleted = 0
					AND  created_by = " . $USER['id'] . "
				)";
		
		self::$DEBUG['spaces_sql'] = $sql;

		$RES = $DB->query( $sql );

		$SPACES	= [];

		if( $RES ) {
	
			while( $row = $DB->fetch_assoc( $RES ) ) {
	
				$SPACES[ $row['id'] ] = [
					'id'			=> $row['space_hash'],		// Space Hash
					'name'			=> $row['name'],    		// Space Name
					'is_private'	=> $row['is_private'],
					'date_created'	=> $row['date_created'],
					'created_by'	=> $row['created_by'],
					'lists'			=> [],                 		// Space Lists That Are Not In Folders
					'folders'		=> [],
				];

			}

			self::$DEBUG['spaces_result'] = $SPACES;

		}

		// Grab All Folders By Space ID The User Has Access To
		$sql = "SELECT * FROM xentask.folders
					WHERE space_id IN(" . implode( ',', array_keys( $SPACES ) ) . ") 
						AND is_private = 0
						AND deleted = 0 
						OR (
							is_private = 1 
							AND deleted = 0
							AND created_by = " . $USER['id'] . "
						)";

		$RES = $DB->query( $sql );

		$FOLDERS	= [];

		if( $RES ) {

			while ($row = $DB->fetch_assoc( $RES )) {

				$FOLDERS[ $row['space_id'] ][ $row['id'] ] = [
					'id'	=> $row['hash'],	//	Folder Id
					'name'	=> $row['name'],		//	Folder Name
					'lists' => [],          		//	Lists Belonging To The Folder
				];

			}

		}

		// Grab All Lists By Space ID The User Has Access To
		$sql = "SELECT * FROM xentask.lists
					WHERE space_id IN(" . implode( ',', array_keys( $SPACES ) ) . ") 
						AND is_private = 0
						AND deleted = 0 
						OR (
							is_private = 1 
							AND deleted = 0
							AND created_by = " . $USER['id'] . "
						)";

		$RES = $DB->query( $sql );

		$LISTS	= [];

		if( $RES ) {
	
			while( $row = $DB->fetch_assoc( $RES ) ) {

				$LISTS[ $row['space_id'] ][ $row['parent_folder_id'] ][] = [
					'id'	=> $row['hash'],	// List ID
					'name'	=> $row['name'],	// List Name
				];

			}

		}

		//	AAF MAN, build it without indexes to keep ReARIC Happy
		foreach( $SPACES as $space_id => $SPACE ) {

			$SPACE['lists']		= !empty( $LISTS[ $space_id ][ 0 ] ) ?  $LISTS[ $space_id ][ 0 ] : [];

			foreach( $FOLDERS[ $space_id ] as $folder_id => $FOLDER ) {

				$FOLDER['lists'] = !empty( $LISTS[ $space_id ][ $folder_id ] ) ?  $LISTS[ $space_id ][ $folder_id ] : [];

				$SPACE['folders'][] = $FOLDER;

			}

			$spacesInfo[] = $SPACE;

		}

		self::$DEBUG['spacesInfo'] = $spacesInfo;

		return $spacesInfo;

	}

	static function getSpacesNew( $workspace_id, $USER ) {

		$DB     = new Database( );
	
		$sql    = "SELECT * FROM xentask.workspaces WHERE hash = '" . $workspace_id . "'";
	
		$RES    = $DB->query( $sql );
	
		$WS     = $DB->fetch_assoc( $RES );

		//	First Grab the Space ID's That The User Has Access To
		$sql = "SELECT * FROM xentask.spaces
				WHERE workspace_id = " . $WS['id'] . " 
				AND deleted = 0
				AND is_private = 0 OR (
					is_private = 1 
					AND deleted = 0
					AND created_by = " . $USER['id'] . "
				)";
		
		$RES = $DB->query( $sql );

		$SPACES	= [];

		//	Maps hash to id
		$FOLDERS_MAP = [];

			while( $row = $DB->fetch_assoc( $RES ) ) {
	
				$SPACES[ $row['hash'] ] = [
					'id'			=> $row['hash'],			// Space Hash
					'name'			=> $row['name'],    		// Space Name
					'is_private'	=> $row['is_private'],
					'date_created'	=> $row['date_created'],
					'created_by'	=> $row['created_by'],
					'lists'			=> [],                 		// Space Lists That Are Not In Folders
					'folders'		=> [],
				];

				// Grab All Folders By Space ID The User Has Access To
				$sql = "SELECT * FROM xentask.folders
							WHERE space_id = " . $row['id'] . "
							AND deleted = 0
							AND (
								(is_private = 0)
								OR
								(is_private = 1 AND created_by = 2)
							);";

				$folder_res = $DB->query( $sql );		

				while( $folder_rows = $DB->fetch_assoc( $folder_res ) ) {

					$SPACES[ $row['hash'] ]['folders'][ $folder_rows['hash'] ] = [
						'id' => $folder_rows['hash'],
						'name' => $folder_rows['name'],
						'is_private' => $folder_rows['is_private'],
						'created_by' => $folder_rows['created_by'],
						'lists' => [],
					];

					$FOLDERS_MAP[ $folder_rows['id'] ] = $folder_rows['hash'];

				}

				// Grab All Lists By Space ID The User Has Access To
				$sql = "SELECT * FROM xentask.lists
							WHERE space_id = " . $row['id'] . "
							AND deleted = 0
							AND (
									(is_private = 0)
									OR
									(is_private = 1 AND created_by = 2)
							)";

				$list_res = $DB->query( $sql );

				while( $list_rows = $DB->fetch_assoc( $list_res ) ) {

					$list_data = [
						'id' => $list_rows['hash'],
						'name' => $list_rows['name'],
						'is_private' => $list_rows['is_private'],
						'created_by' => $list_rows['created_by'],
					];

					if( empty( $list_data ) ) $list_data = new stdClass();

					if( empty( $list_rows['parent_folder_id'] ) ) {

						$SPACES[ $row['hash'] ]['lists'][$list_rows['hash']] = $list_data;

					} else {

						$SPACES[ $row['hash'] ]['folders'][ $FOLDERS_MAP[ $list_rows['parent_folder_id'] ] ]['lists'][$list_rows['hash']] = $list_data;

					}

				}

			}

		return $SPACES;

	}

	function getWorkspaceHashFromId(){

		$sql = "SELECT ws.hash FROM workspaces ws
					LEFT JOIN spaces s ON ws.id = s.workspace_id
				WHERE s.id = " . (int)$this->id;

		return $this->DB->fetch1( $sql );

	}

	/**
	* Creates The Space For The User
	*/
	function createSpace( $workspace_id, $DATA, $USER ) {

		$DB     = new Database( );

		$sql    = "SELECT * FROM workspaces WHERE hash = '" . $workspace_id . "'";

		$res    = $DB->query( $sql );

		$WS     = $DB->fetch_assoc();

		if( empty( $WS ) )
			Response::returnJSON( 400, [ 'err_code' => 'S1001', 'err_string' => 'INVALID WORKSPACE' ] );

		$space_hash	= UniqID::genUID( 'aaf', 10 );

		//  Creates The Space
		$sql = "INSERT INTO xentask.spaces
					SET name = '" . $DATA['name'] . "',
						workspace_id	= " . (int)$WS['id'] . ",
						created_by		= " . (int)$USER['id'] . ",
						hash			= '" . $space_hash . "', 
						is_private		= " . ( !empty( $DATA['is_private'] ) ? 1 : 0 );

		$res = $DB->query($sql);

		if( $res === false )
			Response::returnJSON( 400, [ 'err_code' => 'S1002', 'err_string' => 'ERROR CREATING SPACE', 'query_str' => $sql ] );

		$last_insert_id = $DB->last_insert_id( );

		$resp = json_decode( '{
			"id": "790",
			"name": "New Space Name",
			"private": false,
			"statuses": [
			{
				"id": "p16911531_p8y2WNC6",
				"status": "to do",
				"type": "open",
				"orderindex": 0,
				"color": "#d3d3d3"
			},
			{
				"id": "p17911545_ABo7jSsf",
				"status": "complete",
				"type": "closed",
				"orderindex": 1,
				"color": "#6bc950"
			}
			],
			"multiple_assignees": true,
			"features": {
			"due_dates": {
				"enabled": true,
				"start_date": false,
				"remap_due_dates": true,
				"remap_closed_due_date": false
			},
			"sprints": {
				"enabled": false
			},
			"points": {
				"enabled": false
			},
			"custom_items": {
				"enabled": false
			},
			"tags": {
				"enabled": true
			},
			"time_estimates": {
				"enabled": true
			},
			"checklists": {
				"enabled": true
			},
			"zoom": {
				"enabled": false
			},
			"milestones": {
				"enabled": false
			},
			"custom_fields": {
				"enabled": true
			},
			"remap_dependencies": {
				"enabled": true
			},
			"dependency_warning": {
				"enabled": true
			},
			"multiple_assignees": {
				"enabled": true
			},
			"portfolios": {
				"enabled": true
			},
			"emails": {
				"enabled": true
			}
			},
			"archived": false
		}', true );

		$resp['id']		= $space_hash;
		$resp['name']	= $DATA['name'];

		if( !$res ) {

			$DB->query( "DELETE FROM space WHERE id = " . $last_insert_id );
			Response::returnJSON( 400, [ 'err_code' => 'S1003', 'err_string' => 'ERROR CREATING SPACE' ] );

		}

		return [
			'id' => $last_insert_id,
			'response' => $resp,
		];

	}

	/**
	 * Gets All Information About The Space
	 * includes list, folders, custom fields, statuses
	 */
	public function getSpace( ) {

		$sql = "SELECT * FROM xentask.spaces WHERE id = " . $this->id;

		$res = $this->DB->query( $sql );

		$row = $this->DB->fetch_assoc( $res );

		return $row;

	}

	function updateSpace( $DATA ) {

		$ALLOWED = [ 
			'name',
			'description',
			'created_by',
			'is_private',
		];

		$setClause = self::buildSetClause( $DATA, $ALLOWED );

		$sql = "UPDATE spaces SET $setClause WHERE id = '" . $this->id ."'";

		$res = $this->DB->query( $sql );

		return $res;

	}

	public function deleteSpace( $USER ) {

		$sql = "UPDATE xentask.spaces 
						SET deleted = 1,
							deleted_by_user = " . (int)$USER['id'] . "
						WHERE id = " . $this->id;

		$res = $this->DB->query( $sql );

		if( !$res ) Response::returnJSON( 400, [ 'err_code' => 'S1002', 'err_string' => 'ERROR DELETE SPACE', 'query_str' => $sql ] );

	}

}