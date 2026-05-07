<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenCustomField extends Base_Model {


	public function __construct( ) {

		//	Call the parent class constructor For DB  and other globally accessible variables
		parent::__construct();

	}

	/**
	 * This Array Is For Custom Fields That Can Carry 
	 * An Options Array
	 */
	public $TYPES_WITH_OPTIONS = [
		'dropdown',
		'labels',
		'currency',
		'slider',
		'contacts',
		'datatable',
	];

    /**
     * Get Information About The Custom Field
     * 
     * @parm {mixed} $parent_id - An Array Of UUIDS or A String
     */
    public function getCustomFields( $parent_hashes ) {

		if( isset( $parent_hashes['hash'] ) )
			$parent_hashes[] = $parent_hashes;

		$ID_LIST	= [];

		foreach( $parent_hashes as $type => $hash_data ) {

			if( empty( $hash_data['hash'] ) )
				continue;

			if( !is_array( $hash_data['hash'] ) )
				$HASHES	= [ $hash_data['hash'] ];
			else
				$HASHES	= $hash_data['hash'];

			$type		= $hash_data['type'];

			switch( $type ) {

				case 'workspace':
					$sql	= "SELECT id FROM xentask.workspaces WHERE hash IN ('" . implode( "','", $HASHES ) . "')";
					$res	= $this->DB->query( $sql );
					while( $row = $this->DB->fetch_assoc( $res ) )
						$ID_LIST[ $type ][]	= $row['id'];
					break;

				case 'space':
					$sql	= "SELECT id FROM xentask.spaces WHERE hash IN ('" . implode( "','", $HASHES ) . "')";
					$res	= $this->DB->query( $sql );
					while( $row = $this->DB->fetch_assoc( $res ) )
						$ID_LIST[ $type ][]	= $row['id'];
					break;

				case 'folder':
					$sql	= "SELECT id FROM xentask.folders WHERE hash IN ('" . implode( "','", $HASHES ) . "')";
					$res	= $this->DB->query( $sql );
					while( $row = $this->DB->fetch_assoc( $res ) )
						$ID_LIST[ $type ][]	= $row['id'];
					break;

				case 'list':
					$sql	= "SELECT id FROM xentask.lists WHERE hash IN ('" . implode( "','", $HASHES ) . "')";
					$res	= $this->DB->query( $sql );
					while( $row = $this->DB->fetch_assoc( $res ) )
						$ID_LIST[ $type ][]	= $row['id'];
					break;

				default:
					break;

			}

		}

		$DATA = [];

		foreach( $ID_LIST as $type => $IDS ) {

			if( !is_array( $IDS ) )
				$IDS[] = $IDS;

			//	Begin Grabbing All The Required Custom Fields
			$sql	= "SELECT
						id,
						hash,
						name, 
						required,
						pinned,
						created_by_user,
						date_created,
						type,
						description,
						parent_type
						FROM custom_fields WHERE parent_id IN(" . implode( ",", $IDS )  . ") AND parent_type = '" . $type . "'
						ORDER BY pinned DESC, required DESC, date_created ASC";

			$res	= $this->DB->query($sql);

			if( !$res ) Response::returnJSON( 400, [ 'err_code' => 'M001', 'err_string' => 'COULD NOT GET CUSTOM FIELDS', 'query' => $sql ] );

			while( $row = $this->DB->fetch_assoc( $res ) ) {

				$row['required']		= (int)$row['required'];
				$row['pinned']			= (int)$row['pinned'];
				$row['date_created']	= date( 'Y-m-d', strtotime( $row['date_created'] ) );

				//	Grab The Custom Fields With Options
				if( in_array( $row['type'], $this->TYPES_WITH_OPTIONS ) ) {
	
					$sql = "SELECT options FROM xentask.custom_fields_options WHERE custom_field_id = '" . $row['id'] . "'";
	
					$options = $this->DB->fetch1( $sql );
	
					$row['options'] = json_decode( $options, true ); 

					//	Build The Data For The Contacts And DataTables
					//	We Don't Want To risk calling it multiple times on the component level
					if( $row['type'] == 'contacts' || $row['type'] == 'datatables' ) {

						

					}


				}
	
				// Translate The User ID To A String
				$sql = "SELECT CONCAT(first_name, ' ', last_name) AS created_by_user FROM users WHERE id = " . (int)$row['created_by_user'];

				$user = $this->DB->fetch1( $sql );   
	
				$row['created_by_user'] = $user;

				unset( $row['id'] );
	
				$DATA[] = $row;
	
			}

		}

		return $DATA;

    }

    //  Creates A Custom Field
    public function createCustomField( $parent_id, $DATA, $USER ) {

        if( !self::checkRequired( $DATA, ['name','type'] ) ) {

			Response::returnJSON( 400, [ 
				'err_code' => 'M001', 
				'err_string' => 'MISSING REQUIRED PARAMETERS',
			]);

		}
		

        //$DB		= new Database( );

        $hash	= UniqID::genUID( 'xcf', 10 );

        $sql = "INSERT INTO xentask.custom_fields 
					SET name ='" . $this->DB->real_escape_string( $DATA['name'] ) . "',
						type = '" . $DATA['type'] ."',
						parent_id = $parent_id,
						parent_type = '"  . $DATA['parent_type'] . "',
						pinned =  ". ( !empty( $DATA['pinned'] ) ? (int)$DATA['pinned'] : 0 ) . ",
						required = " . ( !empty( $DATA['required'] ) ? (int)$DATA['required'] : 0 ) . ",
						description = '" . ( !empty( $DATA['description'] ) ? $this->DB->real_escape_string( $DATA['description'] ) : '' ) . "',
						hash = '$hash',
						created_by_user = ". (int)$USER['id'];

        $res = $this->DB->query($sql);

        if( !$res ) Response::returnJSON( 400, [ 'err_code' => 'CS1000', 'err_string' => 'ERROR CREATING CUSTOM STATUS', 'query_str' => $sql ] );

        $last_insert_id = $this->DB->last_insert_id();

        //  If The Custom Field Has Options Store It In The DB As JSON
        //  We Don't Care About The Options Data That Comes In Because FE Sends It In Bulk
        //  The Order Index, names and everything else will be bulk saved and tied by the custom fields uuid
        if( $res && !empty( $DATA['options'] ) && in_array( $DATA['type'], $this->TYPES_WITH_OPTIONS ) ) {
            
            $sql = "INSERT INTO custom_fields_options 
                    SET custom_field_id = " . $last_insert_id . ",
                        options = '" . json_encode( $DATA['options'] ) . "'";

            //  No Point In Keeping The Custom Field Around 
            //  If The Options Aren't Created
            if( !$this->DB->query( $sql ) ) {

                $sql = "DELETE FROM custom_fields WHERE id = " . (int)$last_insert_id;

                $this->DB->query( $sql );

                Response::returnJSON( 400, [ 'err_code' => 'CS1000', 'err_string' => 'ERROR CREATING CUSTOM STATUS', 'query_str' => $sql ] );

            }
        }

        return [ 
			'hash' => $hash,
            'created_by_user' => ( $USER['first_name'] . ' ' . $USER['last_name'] ),
            'date_created' => date('Y-m-d')
        ];

    }

    //  Updates A Custom Field
    public function updateCustomFields( $hash, $DATA ) {
        
        //  We Just Need The Custom Field Hash
        //if( !self::checkRequired( $DATA, [ 'id' ] ) )  Response::returnJSON( 400, [ 'err_code' => 'M001', 'err_string' => 'MISSING REQUIRED PARAMETERS' ] );
        if( empty( $hash ) )  Response::returnJSON( 400, [ 'err_code' => 'M001', 'err_string' => 'INVALID CUSTOM FIELD' ] );

        $DB = new Database( );

		$id = $this->getCustomFieldIdByHash( $hash );

        $ALLOWED = [
			'name',
			'pinned',
			'required',
            'description',
            'order_index',
		];

		$setClause = self::buildSetClause( $DATA, $ALLOWED );

        $sql = "UPDATE custom_fields SET $setClause WHERE hash = '" . $hash . "'";

        $res = $DB->query($sql);

        // UPDATE The Entire Column By Inserting New Data
        // ATM It Really Makes No Difference Since The FE Is Updating 
        // In Bulk We Don't Have To Keep Track Of Order_index Since The FE Will For Us
        if( $res && in_array( $DATA['type'], $this->TYPES_WITH_OPTIONS ) ) {
            
            if( empty( $DATA['options'] ) ) Response::returnJSON( 400, [ 'err_code' => 'CS1000', 'err_string' => 'MISSING OPTIONS FOR CUSTOM FIELD', 'DATA' => $DATA ] );

            $sql = "UPDATE custom_fields_options 
                        SET options = '" . json_encode( $DATA['options'] ) . "'
                        WHERE custom_field_id = '" . $id . "'";

            if( !$DB->query($sql) ) Response::returnJSON( 400, [ 'err_code' => 'CS1000', 'err_string' => 'ERROR UPDATING CUSTOM STATUS OPTIONS', 'query_str' => $sql ] );
            
        } else {

            if( !$DB->query($sql) ) Response::returnJSON( 400, [ 'err_code' => 'CS1000', 'err_string' => 'ERROR UPDATING CUSTOM STATUS', 'query_str' => $sql ] );

        }

    }

    //  Deletes A Custom Field
    public function deleteCustomField( $hash ) {

        //$DB = new Database( );

		$sql	= "SELECT id FROM xentask.custom_fields WHERE hash = '" . $hash . "'";

		$id		= $this->DB->fetch1( $sql );

		if( !empty( $id ) ) {

			//	Delete The Custom Field Options
			$sql = "DELETE FROM xentask.custom_fields_options WHERE custom_field_id = " . $id;
			$this->DB->query( $sql );

		}

        // Delete The Custom Field
        $sql = "DELETE FROM xentask.custom_fields WHERE hash = '" . $hash . "'";

        $this->DB->query( $sql );

    }

    public function updateCustomFieldValue( $task_id, $DATA ){

        //$DB = new Database( );

        foreach( $DATA as $custom_field_hash => $value ){

            $sql = "SELECT cfv.custom_field_type, cfv.custom_field_id FROM xentask.custom_fields_values cfv
							JOIN xentask.custom_fields cf ON cfv.custom_field_id = cf.id
                            WHERE cfv.task_id = " . $task_id . " 
                        AND cf.hash = '" . $custom_field_hash . "'";

			$res	= $this->DB->query( $sql );

			$row	= $this->DB->fetch_assoc( $res );

			if( !empty( $row ) ) {

				//$type = $this-.DB->fetch1($sql);

				//  We Do This Because Labels Value Is An Array Of Id's We
				//  Need To Insert It Into DB As A CSV
				switch( $row['custom_field_type'] ) {

					case('labels'):
						case('contacts'):
							case('datatables'):
								//	We Should Update This To Be A JSON encoded String
								if( !empty( $value ) )
								$value = implode( "," , $value );
								else $value = '';
					break;

					case('people'):

						if( !empty( $value ) ) {

							$value = json_encode( $value );
							
						} else $value = '';

					break;

				}

				// Was UPDATE fix this
				$sql = "UPDATE xentask.custom_fields_values SET value = '$value' 
							WHERE task_id = " . $task_id . " 
								AND custom_field_id = " . $row['custom_field_id'];
			} else {

				$sql	= "SELECT * FROM xentask.custom_fields WHERE hash = '" . $custom_field_hash . "'";

				$res	= $this->DB->query( $sql );

				$row	= $this->DB->fetch_assoc( $res );

				switch( $row['type'] ) {

					case('labels'):
						case('contacts'):
							case('datatables'):
						//	We Should Update This To Be A JSON encoded String
						if( !empty( $value ) )
						$value = implode( "," , $value );
						else $value = '';

					break;

					case('people'):
						if( !empty( $value ) ) {

							$value = json_encode( $value );
							
						} else $value = '';
					break;

				}

				$sql = "INSERT INTO xentask.custom_fields_values SET 
							value = '$value', 
							task_id = " . $task_id . ", 
							custom_field_id = " . $row['id'] . ", 
							custom_field_type = '" . $row['type'] . "'";

			}
            $res = $this->DB->query( $sql );

			return $sql;

        }

    }

	public function getCustomFieldIdByHash($hash){

		$sql	= "SELECT id FROM xentask.custom_fields WHERE hash = '" . $hash . "'";

		$id		= $this->DB->fetch1( $sql );

		return $id;

	}

}