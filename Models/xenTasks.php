<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenTasks extends Base_Model {

	public $listId;		//	ID of Home List for task

    public $hash;		//	Hash of task

	public $list_id;	//	ID of task's list

    public $id;			//	ID of task

	function __construct( $taskHash ) {

		parent::__construct();

		//  Get The Internal List ID From DB
		$sql	= "SELECT id, list_id FROM xentask.tasks WHERE hash = '" . $taskHash . "' AND deleted = 0";

		$res	= $this->DB->query( $sql );

		$row	= $this->DB->fetch_assoc( $res );

		if( empty( $row ) )
			throw new Exception( 'INVALID TASK' );

		$this->hash		= $taskHash;
		$this->id		= $row['id'];
		$this->list_id	= $row['list_id'];

	}

    //  Gets General Information About The Task
	function getTask( $USER ) {

		$sql = "SELECT 
					t.hash, 
					t.title,
					t.description,
					l.hash as list_hash,
					t.status,
					DATE_FORMAT(t.due_date, '%Y-%m-%d') AS due_date,
					t.time_estimate,
					t.time_spent,
					t.is_private,
					DATE_FORMAT(t.date_start, '%Y-%m-%d') AS date_start,
					pt.id as parent_hash,
					t.date_created,
					t.date_updated,
					t.priority
				FROM xentask.tasks t JOIN xentask.lists l ON t.list_id = l.id LEFT JOIN xentask.tasks pt ON t.parent_id = pt.id WHERE t.id = " . $this->id;

        $res = $this->DB->query( $sql );

        $task['basic'] = $this->DB->fetch_assoc( $res );

        if( !$res ) Response::returnJSON( 400, [ 'err_string' => 'ERROR GETTING TASK', 'sql' => $sql ] );

        //  Get Subtasks Associated With This Task
        $sql = "SELECT hash, title FROM xentask.tasks WHERE parent_id = '" . $this->id . "' AND deleted = 0"; 

        $res = $this->DB->query( $sql );

        $task['basic']['subtasks'] = [];
        
        while( $row = $this->DB->fetch_assoc( $res ) )
            $task['basic']['subtasks'][ $row['hash'] ] = $row['title'];

        if( empty( $task['basic']['subtasks'] ) ) $task['basic']['subtasks'] = new stdClass();

        // Get The Custom Field Values
        $sql = "SELECT cf.hash, cfv.custom_field_id, cfv.custom_field_type, cfv.value FROM xentask.custom_fields_values cfv JOIN xentask.custom_fields cf ON cfv.custom_field_id = cf.id WHERE cfv.task_id = " . $this->id;

        $res = $this->DB->query($sql);

		while( $row = $this->DB->fetch_assoc( $res ) ) {

			switch( $row['custom_field_type'] ) {

				case 'labels':
					case 'contacts':
						case 'datatables':

					if( !empty( $row['value'] ) )
						$row['value'] = explode( ',' , $row['value'] );
					else
						$row['value'] = [];

				break;

				case 'checkbox':

					$row['value'] = (bool)$row['value'];

				break;

				case 'date':

					$row['value'] = !empty( $row['value'] ) ? date( 'Y-m-d', strtotime( $row['value'] ) ) : '';

				break;

				case 'people':
					if( !empty( $row['value'] ) )
						$row['value'] = json_decode( $row['value'] );
					else
						$row['value'] = [];
				break;

			}

			$task['custom_field_values'][ $row['hash'] ] = $row['value'];    

		}

		// Get The CheckLists For The Task
		$sql = "SELECT id, hash, name FROM xentask.task_checklist 
					WHERE task_id = " . $this->id . "
					AND deleted = 0";

		$res = $this->DB->query( $sql );

		$CHECKLIST = [];

		while( $row = $this->DB->fetch_assoc( $res ) ) {

			$CHECKLIST[] = $row;

			$sql = "SELECT id, hash, name, checked FROM xentask.task_checklist_items 
						WHERE checklist_parent_id = '" . $row['id'] . "'
							AND deleted = 0";

			$res2 = $this->DB->query( $sql );

			while( $row2 = $this->DB->fetch_assoc( $res2 ) ) {

				$row2['checked'] = (int)$row2['checked'];

				$CHECKLIST[ count( $CHECKLIST ) - 1 ]['items'][] = $row2;

			}

		}

		$task['checklists'] = $CHECKLIST;

		//  Get The Assignees For The Task
		$USERS = [];

		$sql = "SELECT user_id FROM xentask.assignees WHERE task_id = " . $this->id;

		$res = $this->DB->query( $sql );

		while( $row = $this->DB->fetch_assoc( $res ) ) {
			
			$sql = "SELECT id,
						CONCAT(first_name, ' ', last_name) AS full_name,
						CONCAT(SUBSTRING(first_name, 1, 1), SUBSTRING(last_name, 1, 1)) AS initials,
						image,
						color
						FROM users WHERE id = " . $row['user_id'];

			$user_res = $this->DB->query( $sql );

			$user = $this->DB->fetch_assoc( $user_res );

			$USERS[] = $user;

		}

		$task['basic']['assignees'] = $USERS;

		//  Get All Time Tracked On The Task And See If There Are Any Opened Ones Currently For The User
		$TIME = [
			'task_time'	=> 0,
			'active'	=> null,
			'activity'	=> []
		];

		$sql = "SELECT user_id, 
						hash, 
						time, 
						date_started, 
						date_ended,
						state
					FROM xentask.task_time_tracking 
				WHERE task_id = " . $this->id . "
				ORDER BY date_started DESC, date_ended DESC";
		
		$res = $this->DB->query( $sql );

		while( $row = $this->DB->fetch_assoc( $res ) ) {

			// Gather The Basic Information About The User
			$TIME['activity'][ $row['user_id'] ]['user'] = $this->getUserBasicInfo( $row['user_id'] );

			// Gather The Time Intervals Information Relating To This User
			if( $row['state'] !== 'pending' ) {

				$TIME['activity'][ $row['user_id'] ]['intervals'][] = [
					'id'			=> $row['hash'],
					'date_started'	=> $row['date_started'],
					'date_ended'	=> $row['date_ended'],
					'time'			=> (int)$row['time']
				];

			}

			//  Overall Task Time
			$TIME['task_time'] += $row['time'];

			//  User Task Time
			if( !empty( $TIME['activity'][ $row['user_id'] ]['total_time'] ) )
				$TIME['activity'][ $row['user_id'] ]['total_time'] += $row['time'];
			else
			$TIME['activity'][ $row['user_id'] ]['total_time']		= $row['time'];

			//  If There Is A Pending Record For The Logged In User This Means They Are
			//  Using The Auto Tracking Button
			if( $row['user_id'] == $USER['id'] && $row['state'] == 'pending' ) {

				$TIME['active'] = [
					'id'			=> $row['hash'],
					'date_started'	=> $row['date_started'],
					'user_id'		=> $row['user_id']
				];
			}

        };

		$task['basic']['time_tracking'] = $TIME;

		return $task;

	}

	//	Grabs The Task Comments Associated With A Task
	function getTaskComments() {

		$comments = [];

		$sql = "SELECT hash as id,
					user_id,
					html_text,
					date_created
				FROM xentask.comments 
				WHERE task_id = " . $this->id . "
					AND deleted = 0";

		$res = $this->DB->query( $sql );

		while( $row = $this->DB->fetch_assoc( $res ) ) {

			$sql = "SELECT id,
						CONCAT(first_name, ' ', last_name) AS full_name,
						CONCAT(SUBSTRING(first_name, 1, 1), SUBSTRING(last_name, 1, 1)) AS initials,
						image
					FROM users WHERE id = " . $row['user_id'];

			$user_res = $this->DB->query( $sql );

			$row['user'] = $this->DB->fetch_assoc( $user_res );

			$comments[] = $row;

		}

		return $comments;

	}

	//	Updates Basic Fields On A Task
	//	Since Most Are Single Action They Will Probably Come In
	//	1 at a time. But Can Accept Multiple
	function updateBasicField( $DATA ){

		$set_clause = self::buildSetClause( $DATA );

		$sql = "UPDATE tasks SET $set_clause WHERE id = " . $this->id;

		$res = $this->DB->query($sql);

		if( !$res ) throw new Exception( 'Could Not Update Field SQL:' . $sql );

	}

	//	Grabs The Task Comments Associated With A Task
	public function updateTaskDescription( $DATA ) {
	
		$sql = "UPDATE tasks SET description = '" . addslashes( $DATA['description'] ) . "'
					WHERE id = " . $this->id; 

		$res = $this->DB->query($sql);

		if( !$res )throw new Exception( 'Could Not Update Description SQL:' . $sql );

	}

	//	Task Checklists
	public function addCheckList( $DATA ) {
	
		$name = $DATA['name'];

		$checkListHash = UniqID::genUID( 'aaf', 10 );

		$sql = "INSERT INTO xentask.task_checklist 
					SET task_id	= " . $this->id . ",
						name	= '" . $name . "',
						hash	= '" . $checkListHash . "'" ;

		$res = $this->DB->query($sql);

		return $checkListHash;

	}

	//	Really Can Only Update A Name Only
	public function updateCheckListName( $DATA ) {

		$sql = "UPDATE xentask.task_checklist SET name = '" . addslashes( $DATA['name'] ) . "'
					WHERE hash = '" . $DATA['checklist_id'] . "'
					AND task_id = " . $this->id; 

		$res = $this->DB->query($sql);

		if( !$res ) throw new Exception( 'Could Not Update Checklist - SQL:' . $sql );

	}

	public function deleteCheckList( $checklist_hash ) {

		$sql = "UPDATE xentask.task_checklist SET deleted = 1
					WHERE hash = '" . $checklist_hash . "'
					AND task_id = " . $this->id;

		$res = $this->DB->query($sql);

		if( !$res ) throw new Exception( 'Could Not DELETE Checklist - SQL:' . $sql );

	}

	public function addCheckListItem( $DATA ) {

		$name = $DATA['name'];

		$checkListHash = $DATA['checklist_id'];

		$sql = "SELECT id FROM xentask.task_checklist WHERE hash = '" . $checkListHash . "'";

		$checklist_id = $this->DB->fetch1( $sql );

		$itemUUID = UniqID::genUID( 'aaf', 10 );

		$sql = "INSERT INTO xentask.task_checklist_items
				SET checklist_parent_id = " . $checklist_id . ",
					name = '" . $name . "',
					hash = '" . $itemUUID . "',
					checked = 0";

		$res = $this->DB->query( $sql );

		return $itemUUID;

	}

	public function updateCheckListItem( $DATA ) {

		$check_list_item_id = $DATA['checklist_item_id'];
		$field = $DATA['field'];
		$value = $DATA['value'];

		if( $field == 'checked' )
			$value = (int)$value;
		else
			$value = "'" . addslashes($value) . "'";

		$sql = "UPDATE xentask.task_checklist_items SET $field = " . $value . " 
					WHERE hash = '" . $check_list_item_id . "'";

		$res = $this->DB->query($sql);

		if( !$res ) throw new Exception( 'Could Not UPDATE Checklist Item - SQL:' . $sql );

	}
    
	public function deleteCheckListItem( $check_list_item_id ) {

		$sql = "UPDATE xentask.task_checklist_items SET deleted = 1 WHERE hash = '" . $check_list_item_id . "'";

		$res = $this->DB->query($sql);

		if( !$res ) throw new Exception( 'Could Not DELETE Checklist Item - SQL:' . $sql );

	}

	/**
	 * 
	 * @param {array} $DATA - Array Of User ID's
	 */
	public function addAssignee( $DATA, $USER ) {

		foreach( $DATA as $users => $user ) {

			//	Makes Sure The User Isn't Already Assigned To The Task
			$sql = "SELECT id FROM assignees WHERE user_id = " . (int)$user . " AND task_id = " . $this->id;

			$result = $this->DB->fetch1($sql);

			if( empty( $result ) ) { 

				$sql = "INSERT INTO xentask.assignees 
							SET user_id = " . (int)$user .",
								assigned_by_id = " .  $USER['id'] . ",
								task_id = " . $this->id;

				$res = $this->DB->query($sql);

				if( !$res ) throw new Exception( "COULD NOT ASSIGN USER TO TASK | SQL: $sql" );

			}

		}

	}

	public function deleteAssignee( $user_id ) {

		$sql = "DELETE FROM assignees 
					WHERE user_id = " . $user_id ."
					AND task_id = " . $this->id;

		$res = $this->DB->query($sql);

		if( !$res ) throw new Exception( "COULD NOT DELETE USER FROM TASK | SQL: $sql" );

	}

	// Handles Adding Time To A Task
	public function addTaskTime( $DATA, $USER ) {

		//  Normal Time Adding
		if( empty( $DATA['auto'] ) ) { 

			$time_hash = UniqID::genUID( 'aaf', 10 );

			if( empty( $DATA['date'] ) ) {

				$current_time = time();
				$time_later = (int)$DATA['time'] + $current_time;

				$date_started = date('Y-m-d H:i:s', $current_time);
				$date_ended = date('Y-m-d H:i:s', $time_later);

			} else {

				$date_started = $DATA['date'] . ' 00:00:00';
				$time_later = strtotime( $date_started ) + (int)$DATA['time'];
				$date_ended = date( 'Y-m-d H:i:s', $time_later );

			}

			$sql = "INSERT INTO xentask.task_time_tracking SET
						task_id = ". $this->id.",
						date_started = '$date_started',
						date_ended = '$date_ended',
						time = " . (int)$DATA['time'] . ",
						state = 'finished',
						hash = '" . $time_hash . "',
						user_id = " . $USER['id'];

			$res = $this->DB->query($sql);

			return [
				'user' => $USER['id'],
				'interval' => [
					'id' =>  $time_hash,
					'date_started' => $date_started,
					'date_ended' => $date_ended,
					'time' => (int)$DATA['time'],
				]
			];

		} else {

			//  Auto Time Tracking
			$now = date('Y-m-d H:i:s');

			if( $DATA['type'] == 'start' ) {

				$time_hash = UniqID::genUID( 'aaf', 10 );

				$sql = "INSERT INTO xentask.task_time_tracking SET
							task_id = ". $this->id.",
							date_started = '" . $now . "',
							state = 'pending',
							hash = '" . $time_hash . "',
							time = 0,
							user_id = " . $USER['id'];

				$this->DB->query($sql);

				return [
					'id' => $time_hash,
					'date_started' => $now,
					'user_id' => $USER['id'],
					'sql' => $sql,
				];

			} else {

				$date_started = $DATA['date_started'];
			
				// Convert datetime strings to Unix timestamps
				$start_timestamp = strtotime($date_started);
				$now_timestamp = strtotime($now);
	
				// Calculate the difference in seconds
				$time_diff = $now_timestamp - $start_timestamp;

				$sql = "UPDATE xentask.task_time_tracking SET
							time = " . (int)$time_diff . ",
							date_ended = '" . $now . "',
							state = 'finished'
							WHERE task_id = '" . $this->id . "'
							AND hash = '" . $DATA['id'] . "'"; 

				$this->DB->query($sql);

				return [
					'user' => $USER['id'],
					'interval' => [
						'id' =>  $DATA['id'],
						'date_started' => $date_started,
						'date_ended' => $now,
						'time' => (int)$time_diff,
					],
					'sql' => $sql
				];
							
			}

		}

	}

	public function getUserBasicInfo( $user_id ){

		$sql = "SELECT id,
		CONCAT(first_name, ' ', last_name) AS full_name,
		CONCAT(SUBSTRING(first_name, 1, 1), SUBSTRING(last_name, 1, 1)) AS initials,
		image,
		color
		FROM users WHERE id = " . $user_id;

		$user_res = $this->DB->query($sql);

		$user = $this->DB->fetch_assoc($user_res);

		return $user;

	}

	public function updateTaskTime($DATA){

		$new_start_date = date( 'Y-m-d H:i:s', strtotime( $DATA['date_started'] ) );
		$new_end_date = date( 'Y-m-d H:i:s', strtotime( $DATA['date_ended'] ) );

		$sql = "UPDATE task_time_tracking 
					SET notes = '" . $DATA['notes'] . "',
						time = " . (int)$DATA['time'] . ",
						date_started = '" . $new_start_date . "',
						date_ended = '" . $new_end_date . "'
					WHERE task_id = " . $this->id . "
						AND hash = '" .  $DATA['id'] . "'";
		
		$res = $this->DB->query($sql);

		//	Send Back The Dates Formatted So FE Doesn't Have To Calculate Or Change
		return [ 'date_started' => $new_start_date,'date_ended' => $new_end_date ];

	}

	public function deleteTaskTime( $time_id, $USER ){

		//	Makes Sure The User Deleting Is The User Whose Logged In
		$sql = "SELECT user_id FROM task_time_tracking WHERE task_id = " . $this->id . "
				AND hash = '" . $time_id . "'";

		$user_id = $this->DB->fetch1($sql);

		if( $user_id == $USER['id'] ){

			$sql	= "DELETE FROM task_time_tracking 
						WHERE task_id = " . $this->id . "
							AND hash = '" . $time_id . "'";

			$res	= $this->DB->query($sql);

		}

	}

	//	Used To Load The FE View When Hitting A Task Directly From A Route
	public function fetchTaskView(){

		$sql = "SELECT t.hash, t.title, l.hash as list_hash FROM xentask.tasks t LEFT JOIN xentask.lists l ON t.list_id = l.id  WHERE t.id = " . $this->id;

		$res = $this->DB->query($sql);

		$task = $this->DB->fetch_assoc($res);

		return $task;

	}

	public function addSubtask( $DATA ){

		//	First Verify Incoming subtask hash actually exists
		//$sql = "SELECT id, title, parent_id FROM tasks WHERE task_id = '" . $DATA['task_id'] . "'";
		$sql = "SELECT id, title, parent_id FROM tasks WHERE hash = '" . $DATA['task_id'] . "'";

		$res = $this->DB->query($sql);

		$subtask = $this->DB->fetch_assoc( $res );

		// The Task ID Provided Is Invalid
		if( empty( $subtask ) ) Response::returnJSON(400, [ 'err_code' => 'T_ADD_SUBTASK', 'err_string' => 'INVALID TASK ID SUPPLIED' ] );

		// The Task ID Is == To The Parent Task
		if( $subtask['id'] == $this->id ) Response::returnJSON( 400, [ 'err_code' => 'T_ADD_SUBTASK', 'err_string' => 'SUBTASK CANNOT BE PARENT TASK' ] );

		// Check If The Subtask Already Belongs To The Task
		if( $subtask['parent_id'] == $this->id ) Response::returnJSON( 400, [ 'err_code' => 'T_ADD_SUBTASK', 'err_string' => 'SUBTASK ALREADY LINKED TO PARENT' ] );

		// If Valid Then Change The Parent Of Incoming Task To Be The Current Object
		$sql = "UPDATE tasks SET 
					parent_id = " . $this->id . "
				WHERE id = " . $subtask['id'];

		$res = $this->DB->query($sql);

		return [ 
			'id' => $DATA['task_id'], 
			'title' => $subtask['title'],
			'sql' => $sql
		];

	}

	public function removeParentTask(){

		$sql = "UPDATE xentask.tasks SET parent_id = 0 WHERE id = " . $this->id;

		$res = $this->DB->query( $sql );

		return( $res );

	}

	public function addAttachments( $DATA, $USER, $FILES ) {

		// Stubbed Up Here For Testing Until Aric Is Around To Make Final Updates

		$ret	= [];

		for ( $i = 0; $i < count( $FILES['attachments']['name'] ); $i++ ) {

			$file_hash		= UniqID::genUID( 'att', 10 );
			$file_name_hash	= $file_hash . "_" . md5( $FILES['attachments']['name'][ $i ] );

			if( !file_exists('/xentask/attachments/' . $this->hash ) )
				mkdir('/xentask/attachments/' . $this->hash, 0777, true);

			$saveto	= "/xentask/attachments/" . $this->hash . "/" . $file_name_hash;

			move_uploaded_file( $FILES['attachments']['tmp_name'][ $i ], $saveto );

			$sql = "INSERT INTO xentask.task_attachments SET 
				task_id			= " . $this->id. ",
				user_id			= " . $USER['id'] . ",
				filename		= '" . $FILES['attachments']['name'][ $i ] . "',
				type			= '" . $FILES['attachments']['type'][ $i ] . "',
				hash			= '" . $file_hash . "',
				storage_hash	= '" . $file_name_hash . "',
				size			= " . $FILES['attachments']['size'][ $i ];

			$this->DB->query( $sql );

			$ret[]	= [
				'filename'	=> $FILES['attachments']['name'][ $i ], 
				'type'		=> $FILES['attachments']['type'][ $i ],
				'file_hash'	=> $file_hash,
				'size'		=> $FILES['attachments']['size'][ $i ]
			];

		}

		return $ret;

	}

	//	Grabs The Attachments Associated With A Task
	function getAttachments() {

		$attachments = [];

		$sql	= "SELECT filename, type, size, hash as attachment_hash, date_created, date_updated 
					FROM xentask.task_attachments 
					WHERE task_id = " . $this->id . "
						AND soft_deleted = 0";

		$res	= $this->DB->query( $sql );

		while( $row = $this->DB->fetch_assoc( $res ) ) {

			if( strstr( $row['type'], "image" ) === false )
				$row['thumb']	= '';
			else
				$row['thumb']	= '/attachment/' . $row['attachment_hash'] . "/thumb";

			$attachments[]	= $row;

		}

		return $attachments;

	}

	static function search( $key ) {

		$retArr	= [];

		$DB  = new Database( );

		$sql	= "SELECT t.hash, t.title as text, 'task' AS type, t.date_created, t.date_updated, u.hash as user_hash, CONCAT( u.first_name, ' ', u.last_name ) as user_name 
					FROM xentask.tasks t LEFT JOIN xentask.users u ON t.creator_id = u.id
					WHERE title LIKE '%" . $key . "%' AND deleted = 0";

		$res	= $DB->query( $sql );

		while( $row = $DB->fetch_assoc( $res ) )
			$retArr[]	= $row;

		return $retArr;

	}

}