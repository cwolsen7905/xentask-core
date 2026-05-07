<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenList extends Base_Model {

    public $hash;
    public $id;
    public $space_id;
	public $space_hash;

    function __construct( $hash ) {

        parent::__construct();

        //  Get The Internal List ID From DB
		$sql	= "SELECT l.*, s.hash as space_hash FROM xentask.lists l LEFT JOIN xentask.spaces s ON l.space_id = s.id WHERE l.hash = '" . $hash . "' AND l.deleted = 0";
        
        $res	= $this->DB->query( $sql );

        $info	= $this->DB->fetch_assoc( $res );

        if( empty( $info ) )
            Response::returnJSON( 400, ['err_string' => 'INVALID LIST', 'list_id' => $hash ] );

        $this->id			= $info['id'];
        $this->hash			= $hash;
        $this->space_id		= $info['space_id'];
		$this->space_hash	= $info['space_hash'];

    }

    /**
     * Creates A New List Under A Space Or Folder
     * 
     * @param {array} $DATA  - Payload Data Expecting space hash, folder_hash, name
     * @param {array} $USER  - The Session Data
     */
    public static function createList( $DATA, $USER ) {
        
        //  At The Bare Minimum We Need A Space
        if( empty( $DATA['space_hash'] ) || empty( $DATA['name'] ) ) Response::returnJSON( 400, ['err_string' => 'INVALID DATA PROVIDED' ] );
        
        $DB = new Database( );
        
        //  Get The Internal Space ID From DB
		$sql = "SELECT id FROM xentask.spaces WHERE hash = '" . $DATA['space_hash'] . "'";
	
        $space_id = $DB->fetch1( $sql );

        if( empty( $space_id ) ) Response::returnJSON( 400, [ 'err_string' => 'SPACE NOT FOUND' ] );

        //  If We're Creating Within A Folder And A Folder Hash Is Provided Grab That Too
        if( !empty( $DATA['folder_hash'] ) ) {

            //  Get The Internal Folder ID From DB
            $sql = "SELECT id FROM xentask.folders WHERE hash = '" . $DATA['folder_hash'] . "'";
        
            $folder_id = $DB->fetch1( $sql );

            if( empty( $folder_id ) ) Response::returnJSON( 400, [ 'err_string' => 'FOLDER NOT FOUND' ] );

        }

        //  Generate A New List Hash
        $list_hash	= UniqID::genUID( 'aaf', 10 );

        //  Start Inserting The New List To The DB
		$sql = "INSERT INTO xentask.lists 
                    SET hash = '" . $list_hash . "',
                        name = '" . $DATA['name'] . "',
                        space_id = " . $space_id . ",
                        created_by = " .  $USER['id'] . ",
                        is_private = " . ( empty( $DATA['is_private'] ) ? 0 : 1 );

        if( !empty( $folder_id ) ) $sql .= ', parent_folder_id =' . $folder_id;
	
        $res = $DB->query( $sql );

        $last_insert_id = $DB->last_insert_id();

        if( !$res ) Response::returnJSON( 400, ['err_string' => 'ERROR CREATING NEW LIST' , 'DATA'=> $DATA, 'USER' => $USER, 'SQL' => $sql ] );
        
        $sql = "SELECT * FROM xentask.lists WHERE id = " . (int)$last_insert_id;
        
        $res = $DB->query( $sql );

        return ( $DB->fetch_assoc($res) );

    }

    public function getList( ) {

        $list_info = [];

        //  Get The Internal List ID From DB
		$sql = "SELECT * FROM xentask.lists WHERE id = '" . $this->id . "' AND deleted = 0";

        $res = $this->DB->query( $sql );

        $list_info = $this->DB->fetch_assoc( $res );    

        //  Grab The Space Information
        $sql = "SELECT * FROM xentask.spaces WHERE id = " . (int)$list_info['space_id'] . " AND deleted = 0";
        
        $res = $this->DB->query( $sql );
     
        $space_info = $this->DB->fetch_assoc( $res );

        //  Grab The Workspace Hash
        $sql = "SELECT hash FROM xentask.workspaces WHERE id = " . (int)$space_info['workspace_id'];
        
        $workspace_hash = $this->DB->fetch1( $sql );

        //  Grab The Folders Hash
        $sql = "SELECT hash FROM xentask.folders WHERE id = " . (int)$list_info['parent_folder_id'] . " AND deleted = 0";

        $folder_hash = $this->DB->fetch1( $sql );

        $list_info['workspace_hash']	= $workspace_hash;

        $list_info['space_hash']		=  $space_info['hash'];

		$list_info['folder_hash']		=  $folder_hash;

        $list_info['forms']             = [];
        //  Get Forms For The List (To Populate The Dropdown)
        $sql = "SELECT name, hash FROM xentask.forms WHERE list_hash = '" . $this->hash . "' AND deleted = 0";
        
        $res = $this->DB->query( $sql );

        while( $row = $this->DB->fetch_assoc($res) ) {
            $list_info['forms'][] = $row;
        }

		$list_info['debug']				= self::$DEBUG;

		unset( $list_info['id'] );
		unset( $list_info['space_id'] );

		return $list_info;

    }

    /**
     * Soft Deletes A List
     */
    public function deleteList() {

        //  Get The Internal List ID From DB
		$sql = "UPDATE xentask.lists SET deleted = 1 WHERE id = " . $this->id;
	
        $res = $this->DB->query( $sql );

        return $res;

    }

    /**
     * This Is Used When The List Is Dragged Into Another Space Container
     * 
     * @param {string} $spaceHash - The New Space To Move To. This Is Coming From The FE POST Params 
     * 
     */
    function updateParent($DATA) {

        //  Makes Sure We Have The Required Data
        if( !( self::checkRequired( $DATA, ['parent_type', 'parent_id'] ) ) ) {

            Response::returnJSON( 400, [ 'err_string' => 'MISSING REQUIRED PARAMETERS', 'payload' => $DATA ] );

        }

        $parent_type = $DATA['parent_type'];

        //  Folder Id 0 means that its the part of the space's root
        $folder_id = 0;

        if( $parent_type == 'space' ) {

            $space_hash = $DATA['parent_id'];

            //  Query DB For Spaces Internal ID
		    $sql = "SELECT id FROM xentask.spaces WHERE hash = '" . (string)$space_hash . "'";

            $space_id = $this->DB->fetch1( $sql );

        } elseif( $parent_type == 'folder' ) {
            
            //  First Grab The Folders Internal ID and The Space Parent 
            $sql = "SELECT * FROM xentask.folders WHERE hash = '" . (string)$DATA['parent_id'] . "'";

            $res = $this->DB->query( $sql );

            $folder_data = $this->DB->fetch_assoc($res);

            $space_id = $folder_data['space_id'];

            $folder_id = $folder_data['id'];

        } else {

            Response::returnJSON( 400, [ 'err_string' => 'UNKNOWN UPDATE ACTION', 'payload' => $DATA ] );

        }

        //  Update The Lists Space ID 
        //  We Set Folder Parent To 0 Just In Case The Node Was Dragged
        //  Out From A Folder 
        $sql = "UPDATE xentask.lists SET 
                        space_id = " . (int)$space_id . ",
                        parent_folder_id = " . (int)$folder_id . "                 
                    WHERE id = " . (int)$this->id;
        
        $res = $this->DB->query( $sql );

        return $res;

    }

    /**
     * Will Update The List To Private Or Not Private
     * 
     * @param {int} $isPrivate 
     */
    function updatePrivate( $isPrivate ) {

         //  Update The Lists Space And Folder Ids
         $sql = "UPDATE xentask.lists SET 
                    is_private = " . (int)$isPrivate . "       
                WHERE id = " . (int)$this->id;

        $res = $this->DB->query( $sql );

        return $res;
    
    }

    function updateList($DATA){

        $setClause = self::buildSetClause( $DATA, [ 'name' ] );

		$sql = "UPDATE xentask.lists SET $setClause WHERE id = '" . $this->id ."'";

		$res = $this->DB->query($sql);

		return $res;

    }

    /**
     * Handles Task Creation
     */
    function createTask( $REQUEST, $FILES, $USER ) {

        $to_decode = ['basicFields', 'checkList', 'customFields'];
       
        foreach( $to_decode as $key )
            $REQUEST[$key]	= json_decode( $REQUEST[$key], true );

        $basicFields		= $REQUEST['basicFields'];
        $checkLists			= $REQUEST['checkList'];
        $customFields		= $REQUEST['customFields'];
        $description		= $REQUEST['description'];
        $taskName			= $REQUEST['taskName'];
        $parentId			= !empty( $REQUEST['parentId'] ) ? $REQUEST['parentId'] : '';
        $parentInternalId	= 0;

        //  Grab The Internal Id For The Parent
        if( !empty( $parentId ) ) {

            $sql	= "SELECT id FROM xentask.tasks WHERE hash = '" . $parentId . "'";

            $parentInternalId	= $this->DB->fetch1($sql);

        }   

        $taskHash	= UniqID::genUID( 'aaf', 10 );
        
        $sql = "INSERT INTO xentask.tasks 
                    SET title = '". $taskName ."',
                        description = '". addslashes( $description ) ."',
                        status = '" . $basicFields['statuses']['value'] . "',
                        priority = '" . $basicFields['priority']['value'] . "',
                        time_estimate = " . (int)$basicFields['time_estimate'] .",
                        list_id = " . (int)$this->id .",
                        hash = '" . $taskHash . "',
                        workspace_hash = '" . $USER['default_workspace']. "', 
						creator_id = " . $USER['id'];
        
        if( !empty( $basicFields['date_start'] ) )
            $sql .= ",date_start = '" . $basicFields['date_start'] . " 00:00:00'";

        if( !empty( $basicFields['due_date'] ) )
            $sql .= ",due_date = '" . $basicFields['due_date'] . " 00:00:00'";
        
        //  Insert Parent Id
        if( !empty( $parentId ) )
			$sql .= ",parent_id = " . $parentInternalId;

        $res	= $this->DB->query( $sql );

        if( !$res ) throw new Exception("Could Not Create Task SQL:" . $sql );

        $task_id = $this->DB->last_insert_id();
        
        // Insert CheckList Into Task
        if( !empty( $checkLists ) ) {

            foreach( $checkLists as $checkListObj => $checkList ) {

                $name = $checkList['name'];

                $checkListHash = UniqID::genUID( 'aaf', 10 );
                
                $sql = "INSERT INTO xentask.task_checklist 
                            SET task_id = " . $task_id . ",
                                name = '" . $name . "',
                                uuid = '" . $checkListHash . "'" ;

                $this->DB->query( $sql );

                $checklist_id = $this->DB->last_insert_id();

                //  INSERT the checklist Items
                foreach( $checkList['items'] as $itemObj => $item ) {

                    $itemUUID = UniqID::genUID( 'aaf', 10 );

                    $sql = "INSERT INTO task_checklist_items
                            SET checklist_parent_id = " . $checklist_id . ",
                                checklist_parent_uuid = '" . $checkListHash . "',
                                name = '" . $item['name'] . "',
                                uuid = '" . $itemUUID . "',
                                checked = " . (int)$item['checked'];

                    $this->DB->query( $sql );
                }
            }
        }

        if( !empty( $customFields ) ) {

            //  Insert Custom Fields Values
            foreach( $customFields as $fieldsObj => $fields ) {

                //  We Do This Because Labels Value Is An Array Of Id's We
                //  Need To Insert It Into DB As A CSV
                if( $fields['type'] == 'labels' || $fields['type'] == 'contacts' || $fields['type'] == 'datatables') {

                    if( !empty( $fields['value'] ) )
                        $fields['value'] = implode( "," , $fields['value'] );
                    else
                        $fields['value'] = '';
               
                }

                if( $fields['type'] == 'people'  ) {

					if( !empty( $fields['value'] ) ) {

						$fields['value'] = json_encode( $fields['value'] );
						
					} else $fields['value'] = '';
               
                }

				$cf_id	= $this->DB->fetch1( "SELECT id FROM custom_fields WHERE hash = '" . $fields['hash'] . "'" );

                $sql = "INSERT INTO custom_fields_values
                            SET custom_field_id = " . $cf_id. ",	
                                task_id = " . (int)$task_id . ",
                                value = '" . $fields['value'] . "',
                                custom_field_type = '" . $fields['type'] . "'";

                $this->DB->query( $sql );

            }

        }

        //  Assignees Should Come In As An Array
        if( !empty( $basicFields['assignees'] ) ) {

            if( is_array( $basicFields['assignees'] ) ){

                foreach( $basicFields['assignees'] as $assigneObj => $assign ){

                    $assignee_id = $assign['id'];
                    
                    $sql = "INSERT INTO xentask.assignees 
                                SET user_id = " . $assignee_id .",
                                    assigned_by_id = " .  $USER['id'] . ",
                                    task_id = " . $task_id;

                    $res = $this->DB->query($sql);

                }

            }

        }

        if( !empty( $FILES ) ){

			//This is a funciton in xenTasks need to make a shared function somewhere
			for ( $i = 0; $i < count( $FILES['attachments']['name'] ); $i++ ) {

				$file_hash		= UniqID::genUID( 'att', 10 );
				$file_name_hash	= $file_hash . "_" . md5( $FILES['attachments']['name'][ $i ] );

				if( !file_exists('/xentask/attachments/' . $this->hash ) )
					mkdir('/xentask/attachments/' . $this->hash, 0777, true);

				$saveto	= "/xentask/attachments/" . $this->hash . "/" . $file_name_hash;

				move_uploaded_file( $FILES['attachments']['tmp_name'][ $i ], $saveto );

				$sql = "INSERT INTO xentask.task_attachments SET 
					task_id			= " . $task_id. ",
					user_id			= " . $USER['id'] . ",
					filename		= '" . $FILES['attachments']['name'][ $i ] . "',
					type			= '" . $FILES['attachments']['type'][ $i ] . "',
					hash			= '" . $file_hash . "',
					storage_hash	= '" . $file_name_hash . "',
					size			= " . $FILES['attachments']['size'][ $i ];

				$res	= $this->DB->query( $sql );

			}

        }

        //  Perform Callouts
        require_once LIB_CORE . 'Notification.php';

        $EMAIL          = New Notification();

        $dom            = new DOMDocument();

        $dom->loadHTML($description);

        $xpath                  = new DOMXPath($dom);

        Template::$base_path	= TPL_BASE;

        $spans = $xpath->query('//span[@class="mention"]');
        
        //	Get Information On The Commentor
        //	To Fill The HTML Template
        $commentor = new xenUser( $USER['id'] );

        //	Loop Through Each Span To Get The User ID Mentioned
        foreach( $spans as $span ) {
                
            //	The User Who Is Being Mentioned
            $mention_user_id = $span->getAttribute('data-user-id');

            $mentioned_user = new xenUser( $mention_user_id );

            $HTML = Template::render( 'content/notification.html', [
                'title' => 'New Task Mention',
                    'subtext'		=> 'By ' . $commentor->full_name,
                    'profile_image' => $commentor->image,
                    'profile_color'	=> !empty($commentor->color) ? $commentor->color : '#6610f2',
                    'profile_name'	=> $commentor->initals,
                    'body'			=> $description,
                    'link'			=> ( ( getenv('DEPLOY_ENV') == 'PROD' ) ? 'https://go.xentask.com/task/' : 'https://xentask-fe.' . strtolower( getenv('DEPLOY_ENV') ) . '.your-domain.com/task/' ) . $taskHash,
                    'link_text'		=> 'View Task',
                ]
            );

            $EMAIL->sendMessage(
                    [
                        'to_address' => [ 'email' => $mentioned_user->email ],
                        'subject' => "New Task Mention",
                        'message_html' => $HTML
                    ]
            );
        }

        return ['task_id' => $taskHash, 'task_name' => $taskName ];
        
    }   

    //  Get All Tasks For A List 
    //  This Builds The Task Tables
    public function getTasks() {

        $tasks = [];

        $sql = "SELECT t.*, pt.hash as parent_hash FROM xentask.tasks t LEFT JOIN tasks pt ON t.parent_id = pt.id WHERE t.list_id = " . $this->id . ' AND t.deleted = 0';

        $res = $this->DB->query( $sql );
        
        // Bind The Custom Fields Data With The Task Data
        while( $row = $this->DB->fetch_assoc($res) ) {

            if ( $row['date_start'] == "0000-00-00" )
                $row['date_start']	= "";

            if ( $row['due_date'] == "0000-00-00" )
                $row['due_date']	= "";

			if( empty( $row['parent_hash'] ) ) unset( $row['parent_hash'] );

            $tasks[]	= $row;

            //  Get Assignees Information
            $sql		= "SELECT GROUP_CONCAT(DISTINCT user_id ORDER BY user_id) AS assignees FROM xentask.assignees WHERE task_id = '" . $row['id'] . "'";

            $assignees	= $this->DB->fetch1( $sql );
            
            $tasks[ count( $tasks ) - 1 ]['assignees'] = empty( $assignees ) ? [] : explode( ',', $assignees );

            // Get The Custom Field Values As Well
            $sql	= "SELECT cf.hash, cfv.custom_field_type, cfv.value FROM xentask.custom_fields_values cfv JOIN xentask.custom_fields cf ON cfv.custom_field_id = cf.id WHERE task_id = " . $row['id'];

            $res2	= $this->DB->query( $sql );

            while( $row2 = $this->DB->fetch_assoc( $res2 ) ) {
                
                $field_type = $row2['custom_field_type'];
                 
                $csv_convert = [
                    'labels', 
                    'contacts', 
                    'datatables',
                ];

                //  Turn csv values back into an array
                if( in_array( $field_type, $csv_convert ) ) {
                    
                    if( !empty( $row2['value'] ) )
                        $row2['value'] = explode( ',' , $row2['value'] );
                    else
                        $row2['value'] = [];

                        

                }

                if( $row2['custom_field_type'] == 'people' ) {
                    
                    if( !empty( $row2['value'] ) )
                        $row2['value'] = json_decode( $row2['value'] );
                    else
                        $row2['value'] = [];

                }


                if( $row2['custom_field_type'] == 'date' ) $row2['value'] = date( 'Y-m-d', strtotime( $row2['value'] ) );

                $tasks[ count($tasks) - 1 ][ $row2['hash'] ] = $row2['value'];

            }

			unset( $tasks[ count( $tasks ) - 1]['id'] );
			unset( $tasks[ count( $tasks ) - 1]['list_id'] );
			unset( $tasks[ count( $tasks ) - 1]['parent_id'] );

        }

        return $tasks;

    }

    public function deleteTasks( $DATA ) {

        if( !empty( $DATA['tasks'] ) ) {

            $task_hashes = implode( ",", array_map( 'add_quotes', $DATA['tasks'] ) );

            $sql = "UPDATE xentask.tasks SET
                        deleted = 1, 
                        date_deleted=NOW()
                    WHERE hash IN( $task_hashes ) 
                    AND list_id = " . $this->id;

           $res = $this->DB->query($sql);

        }

    }

    /**
     * Creates A Custom List Filter For The User
     */
    public function createListFilters( $DATA, $USER ) {

        $hash = UniqID::genUID( 'aaf', 10 );
        
        $sql = "INSERT INTO xentask.list_filters 
                    SET list_hash = '" . $this->hash . "',
                        user_hash = '" . $USER['hash'] . "',
                        name = '" . $DATA['name'] . "',
                        hash = '" . $hash . "',
                        value = '" . $this->DB->real_escape_string( json_encode( $DATA['value'] ) ) . "'" ;

        $res = $this->DB->query($sql);

        if( $res ) {
            return [ 'hash' => $hash ];
        } else {
            throw new Exception("Could Not Create Filter" . $sql );
        }
    }

    public function shareListFilter( $DATA, $USER ) {

        if( empty( $DATA['share_user'] ) ) Response::returnJSON( 400, ['err_string' => 'No Users Selected'] );

        foreach( $DATA['share_user'] as $user_hash ) {

            //  No Reason To Copy The Users
            if( $user_hash  == $USER['hash'] ) continue;

            //  Check If The User Already Has A Copy Of The Filter.
            $sql = "SELECT hash
                        FROM xentask.list_filters
                    WHERE original_hash = '" . $DATA['hash'] . "'
                        AND user_hash = '" . $user_hash . "'";

            $copy_hash = $this->DB->fetch1($sql);

            // Grab The Originals Data
            $sql = "SELECT * FROM xentask.list_filters WHERE hash = '" . $DATA['hash'] . "'";

            $res = $this->DB->query($sql);

            $ORIGINAL = $this->DB->fetch_assoc($res);

            // The User Doesn't Have A Copy Yet
            if( empty( $copy_hash ) ) {

                $hash = UniqID::genUID( 'aaf', 10 );
                
                $sql = "INSERT INTO xentask.list_filters 
                            SET list_hash = '" . $this->hash . "',
                                user_hash = '" . $user_hash . "',
                                name = '" . $ORIGINAL['name'] . "',
                                hash = '" . $hash . "',
                                value = '" . $this->DB->real_escape_string( $ORIGINAL['value'] ) . "',
                                original_hash = '". $ORIGINAL['hash'] ."'" ;

                $this->DB->query($sql);


            } else {

                // The User Already Has A Copy We Should Just Update Instead
                $sql = "UPDATE xentask.list_filters
                            SET value = '" . $this->DB->real_escape_string( $ORIGINAL['value'] ) . "'
                        WHERE hash = '" . $copy_hash . "'" ;

                $this->DB->query($sql);

            }

        }

    }
    
    public function updateListFilter( $DATA ) {
        
        // Remove 'hash' from the changes array
        $filter_hash = $DATA['hash'];

        unset( $DATA['hash'] );

        foreach( $DATA['changes'] as $field => $value ) {

            if( $field === 'value' ) {

                // JSON-encode if the field is 'value'
                $set_string_parts[] = "$field = '" . $this->DB->real_escape_string( json_encode( $value ) ) . "'";

            } else {

                // Otherwise, just assign the value directly
                $set_string_parts[] = "$field = '" . $this->DB->real_escape_string( $value ) . "'";

            }

        }

        $set_string = implode(', ', $set_string_parts);

        // Construct the SQL query using the generated $set_string
        $sql = "UPDATE xentask.list_filters 
                    SET $set_string
                WHERE hash = '" . $filter_hash . "'";

        $res = $this->DB->query($sql);

    }

    /**
     * Gets The Filters That The User Saved For A Particular List
     */
    public function getListFilters( $USER ) {

        $sql = "SELECT hash, name, value
                    FROM xentask.list_filters
                WHERE list_hash = '" . $this->hash . "'
                    AND user_hash = '" . $USER['hash'] . "'"  ;

        $res = $this->DB->query($sql);

        $DATA = [];

        while( $row = $this->DB->fetch_assoc( $res ) ) {

            $DATA[ $row['hash'] ]['name'] = $row['name'];
            $DATA[ $row['hash'] ]['value'] =  json_decode( $row['value'], true );

        }

        return $DATA;
        
    }

    public function deleteListFilter( $hash ) {

        $sql = "DELETE FROM xentask.list_filters
                    WHERE list_hash = '" . $this->hash . "'
                AND hash = '" . $hash . "'" ;

        $this->DB->query($sql);
        
    }

    public function createForm( $DATA, $USER ) {

        $hash = UniqID::genUID( 'att', 10 );

        $sql = "INSERT INTO xentask.forms 
                SET name = '" . $DATA['name'] ."',
                    hash = '" . $hash . "',
                    list_hash = '" . $this->hash . "',
                    published = '" . $DATA['published'] . "',
                    created_by_user = '" . $USER['id'] . "',
                    data = '" . json_encode( $DATA['data'] ). "'";

        $this->DB->query($sql);

        return [
            'hash' => $hash,
            'name' => $DATA['name'],
        ];
        
    }

    public function updateForm( $DATA ) {

        $form = $this->getForm( $DATA['id'] );

        $name_changed = false;

        if( $form['name'] !== $DATA['name'] ) $name_changed = true;

        $sql = "UPDATE xentask.forms 
                    SET name = '" . $DATA['name'] ."',
                        published = '" . $DATA['published'] . "',
                        data = '" . json_encode( $DATA['data'] ). "'
                    WHERE hash = '" . $DATA['id'] . "'";

    

        $res = $this->DB->query($sql);
        
        return [
            'name_changed' => $name_changed
        ];

    }

    public function getForm( $form_hash ) {

        $sql = "SELECT * FROM xentask.forms WHERE hash = '$form_hash'";

        $res = $this->DB->query($sql);

        return $this->DB->fetch_assoc( $res );
        
    }

    public function deleteForm( $form_hash ) {

        $sql = "UPDATE xentask.forms set DELETED = 1 WHERE hash = '$form_hash'";

        $res = $this->DB->query($sql);

    }
    

}

function add_quotes($str) {
	return sprintf("'%s'", $str);
}
