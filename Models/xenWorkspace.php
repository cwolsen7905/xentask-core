<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenWorkspace extends Base_Model {

	public $id;
	public $client_id;
	public $hash;
	public $name;
	public $image;

	public function __construct( $hash ) {

		// Call the parent class constructor For DB 
		// And Other Globally Accessible Variables
        parent::__construct();

		$sql = "SELECT * FROM xentask.workspaces WHERE hash = '" . $hash . "'";

		$res = $this->DB->query( $sql );

        $workspace = $this->DB->fetch_assoc( $res );

		if( !empty( $workspace ) ) {

			$this->id			= $workspace['id'];
			$this->hash			= $hash;
			$this->client_id	= $workspace['client_id'];
			$this->name			= $workspace['name'];
			$this->image		= $workspace['image'];

		} else {

			Response::returnJSON( 400, ['err_code' => 'S0002', 'err_string' => 'INVALID WORKSPACE: ' . $hash, "sql" => $sql ] );

		}

	}

	public function getWorkspaceUsers(){

		$USERS = [];

		$sql = "SELECT u.id,
					CONCAT(first_name, ' ', last_name) AS full_name,
					CONCAT(SUBSTRING(first_name, 1, 1), SUBSTRING(last_name, 1, 1)) AS initials,
					image,
					color,
					email,
					hash
					FROM users u JOIN xentask.workspace_users wu ON u.id = wu.user_id WHERE wu.workspace_id = " . $this->id;

		$res = $this->DB->query( $sql );

		while( $row = $this->DB->fetch_assoc( $res ) ) {

			$USERS[] = $row;

		}

		return $USERS;

	}

	public function isAdmin( $userId ):bool {

		$ADMINS	= MemcacheUtil::get( "WORKSPACE_ADMINS_" . $this->id );

		if( empty( $ADMINS ) ) {

			$ADMINS	= [];

			$sql	= "SELECT user_id FROM xentask.workspace_users WHERE workspace_id = " . $this->id . " AND is_admin = 1";

			$res	= $this->DB->query( $sql );

			while( $row	= $this->DB->fetch_assoc( $res ) ) {

				$ADMINS[] = $row['user_id'];

			}

			MemcacheUtil::set( "WORKSPACE_ADMINS_" . $this->id, $ADMINS );

		}

		return( in_array( $userId, $ADMINS ) );

	}

	public function inviteToWorkspace( $invited_by, $DATA ) {

		if( empty( $DATA['emails'] ) )
			return [ 400, 'MISSING PARAMETER -> EMAILS', 6969 ];

		if( !empty( $DATA['level'] ) && $DATA['level'] == 2 )
			$admin	= 1;
		else
			$admin	= 0;

		foreach( $DATA['emails'] as $email ) {

			$hash		= UniqID::genUID( 'ih', 32 );

			$sql		= "SELECT id FROM xentask.users WHERE email like '" . $email . "'";

			$user_id	= $this->DB->fetch1( $sql );

			if( !empty( $user_id ) ) {

				$sql	= "INSERT INTO xentask.workspace_invites SET type = 1, invited_by = " . $invited_by . ", user_id = " . $user_id . ", workspace_id = " . $this->id . ", is_admin = " . $admin . ", invite_hash = '" . $hash . "', email = '" . $email . "'";

				$res	= $this->DB->query(( $sql ) );

				if( !$res )
					$res	= [ 'MESSAGE' => 'Failed To Create Invite', 'CODE' => 1234 ];
				else
					$res	= [ 'MESSAGE' => 'Invite Sent', 'CODE' => 4321 ];

			} else {

				$sql	= "INSERT INTO xentask.workspace_invites SET type = 2, invited_by = " . $invited_by . ", user_id = 0, workspace_id = " . $this->id . ", is_admin = " . $admin . ", invite_hash = '" . $hash . "', email = '" . $email . "'";

				$res	= $this->DB->query(( $sql ) );

				if( !$res )
					$res	= [ 'MESSAGE' => 'Failed To Create Invite', 'CODE' => 1234 ];
				else
					$res	= [ 'MESSAGE' => 'Invite Sent', 'CODE' => 4321 ];

			}

			if( $res['CODE'] != 1234 ) {

				require_once LIB_CORE . 'Notification.php';

				$EMAIL          		= New Notification( );

				Template::$base_path	= TPL_BASE;

				$HTML = Template::render( 'content/notification.html', [
						'title'			=> 'Workspace Invitation',
						'subtext'		=> '',
						'profile_image'	=> $this->image,
						'profile_color'	=> '#6610f2',
						'profile_name'	=> ucfirst( substr( $this->name, 0, 1) ),
						'body'			=> "You've been invited to join <strong>" . $this->name  . "'s</strong> Workspace!<br>If you feel like there's been a mistake please ignore this message.",
						'link'			=> ( ( getenv('DEPLOY_ENV') == 'PROD' ) ? 'https://go.xentask.com/invite/' : 'https://xentask-fe.' . strtolower( getenv('DEPLOY_ENV') ) . '.your-domain.com/invite/' ) . $hash,
						'link_text'		=>'Accept Invite',
					]
				);

				$EMAIL->sendMessage(
					[
						'to_address' => [ 'email' => $email ],
						'subject' => "Invited To Workspace",
						'message_html' => $HTML
					]
				);

			}

		}

		return( $res );

	}

	//	Gets The Users Dashboard Data
	public function getUserDashBoard($USER){

		$DATA = [];

		$today = date('Y-m-d H:i:s'); // Today's datetime in 'YYYY-MM-DD HH:MM:SS' format

		//  Get All The Tasks That's Assigned To The User That Is Not Cancelled Or Completed Yet
        $sql = "SELECT 
					t.hash,
					t.title,
					t.date_created,
					t.date_start AS start,
					t.due_date AS end,
					l.hash as list_hash, s.type AS task_type,
					GROUP_CONCAT(DISTINCT a_all.user_id ORDER BY a_all.user_id) AS assignees,
					s.type AS status_type
				FROM tasks t
				LEFT JOIN assignees a_all ON a_all.task_id = t.id
				LEFT JOIN xentask.lists l ON t.list_id = l.id
				JOIN statuses s ON t.status = s.hash
				WHERE t.id IN (
					SELECT DISTINCT t1.id
					FROM tasks t1
					JOIN assignees a ON a.task_id = t1.id
					WHERE a.user_id = " . $USER['id'] . "
				)
				AND s.type NOT IN ('cancelled', 'completed')
				AND workspace_hash = '" . $this->hash. "' 
				GROUP BY t.id;";

		$res = $this->DB->query($sql);

		while( $row = $this->DB->fetch_assoc($res) ) {

			$row['overdue'] = false;

			if( $row['end'] != '0000-00-00' && strtotime( $row['end'] ) < strtotime( $today ) )
				$row['overdue'] = true;

			$DATA[] = $row;

		}

		return $DATA;

	}

}
