<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';
class xenFolder extends Base_Model {

    public $id;

    public function __construct( $folderHash ) {

        // Call the parent class constructor For DB 
		// And Other Globally Accessible Variables
        parent::__construct();

		$sql	= "SELECT * FROM xentask.folders WHERE hash = '" . $folderHash . "' AND deleted = 0";

        $id		= $this->DB->fetch1( $sql );

        //  Assign If ID And Error If None
        if( !empty( $id ) )
            $this->id = $id;
		else
            Response::returnJSON( 400, [ 'err_code' => 'S1001', 'err_string' => 'INVALID FOLDER' ] );

    }

    public static function createFolder( $DATA, $USER ) {

        if( empty( $DATA['space_hash'] ) || empty( $DATA['name'] ) )
            Response::returnJSON( 400, [ 'err_code' => 'F1001', 'err_string' => 'MISSING DATA', "DATA" => $DATA ] );
          
        $DB = new Database( );

        // Pull Up Internal Space ID
        $sql  = "SELECT id FROM xentask.spaces WHERE hash = '" . $DATA['space_hash'] . "'";

        $space_id = $DB->fetch1( $sql );

        if( empty( $space_id ) ) Response::returnJSON( 400, [ 'err_code' => 'F1001', 'err_string' => 'INVALID SPACE' ] );

		//  Creates The Space
        $folder_hash = UniqID::genUID( 'aaf', 10 );

		$sql = "INSERT INTO xentask.folders
					SET name 		= '" . (string)$DATA['name'] . "',
						space_id	= " . (int)$space_id . ",
						created_by	= " . (int)$USER['id'] . ",
						hash		= '" . (string)$folder_hash . "', 
						is_private	= " . ( !empty( $DATA['is_private'] ) ? 1 : 0 );
		
		$res = $DB->query($sql);

        $last_insert_id = $DB->last_insert_id();

		if( $res === false )
			Response::returnJSON( 400, [ 'err_code' => 'F1002', 'err_string' => 'ERROR CREATING FOLDER', 'query_str' => $sql ] );
        
        $sql = "SELECT * FROM xentask.folders WHERE id = " . (int)$last_insert_id;
		
		$res = $DB->query($sql);

        return ( $DB->fetch_assoc($res) );

    }

    public function getFolder() {

        $sql	= "SELECT * FROM xentask.folders WHERE id = " . $this->id;
	
		$res	= $this->DB->query( $sql );
	
		$row	= $this->DB->fetch_assoc( $res );
		
		return $row;

    }

    public function getSpaceParentUUID(){

        $sql = "SELECT s.hash FROM spaces s
					LEFT JOIN folders f ON f.space_id = s.id
				WHERE f.id = " . (int)$this->id;

		return $this->DB->fetch1( $sql );

    }

    public function deleteFolder( $USER ) {

        //  Update The Folders List Children And Their space_id parent
        $sql = "SELECT * FROM lists WHERE parent_folder_id = " . $this->id;

        $res = $this->DB->query( $sql );

        while ($row = $this->DB->fetch_assoc( $res )) {

            $sql = "UPDATE xentask.lists 
                            SET deleted = 1,
                                deleted_by_user = " . $USER['id'] . "
                        WHERE id = " . (int)$row['id'];

            $this->DB->query( $sql );

        }
        
        $sql = "UPDATE xentask.folders 
                        SET deleted = 1, 
                        deleted_by_user = " . (int)$USER['id']. " 
                        WHERE id = " . $this->id;
	
		$res = $this->DB->query( $sql );

        if( !$res ) Response::returnJSON( 400, [ 'err_code' => 'L_DELETE', 'err_string' => 'ERROR DELETEING FOLDER' ] );

    }

	function updateFolder( $DATA ) {

		$setClause = self::buildSetClause( $DATA, ['name'] );

		$sql = "UPDATE xentask.folders SET $setClause WHERE id = '" . $this->id ."'";

		$res = $this->DB->query($sql);

		return $res;
        
	}

    function updateParent($DATA) {

        if( !( self::checkRequired( $DATA, ['parent_type', 'parent_id'] ) ) || $DATA['parent_type'] !== 'space' )
            Response::returnJSON( 400, [ 'err_string' => 'MISSING REQUIRED PARAMETERS', 'payload' => $DATA ] );

        $spaceHash = $DATA['parent_id'];

        //  Query DB For Spaces Internal ID
		$sql = "SELECT id FROM xentask.spaces WHERE hash = '" . (string)$spaceHash . "'";
	
		$spaceId = $this->DB->fetch1( $sql );

        //  Update The Folders List Children And Their space_id parent
        $sql = "SELECT * FROM lists WHERE parent_folder_id = " . $this->id;

        $res = $this->DB->query( $sql );

        while ($row = $this->DB->fetch_assoc( $res )) {

            $sql = "UPDATE xentask.lists SET space_id = " . (int)$spaceId . "
                        WHERE id = " . (int)$row['id'];

            $this->DB->query( $sql );

        }

        //  Update The Folders Space ID 
        $sql = "UPDATE xentask.folders SET 
                        space_id = " . (int)$spaceId . "  
                    WHERE id = " . (int)$this->id;
        
        $res = $this->DB->query( $sql );

    }

}