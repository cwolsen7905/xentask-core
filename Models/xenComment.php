<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenComment extends Base_Model {

	public	$commentHash;

	public	$plainText;

	public	$htmlText;

	public	$userId;

	public	$taskId;

	public	$dateCreated;

	public	$dateModified;

	public	$assignee;

	public	$resolved;
	
	public	$deleted;

	private	$id;

	private	$requiredFields	= [ 'text', 'notify_all' ];

	function __construct( $commentHash, $commentData = [] ) {

		// Call the parent class constructor For DB 
		// And Other Globally Accessible Variables
		parent::__construct();

		if( !empty( $commentHash ) ) {

			$this->commentHash	= $commentHash;

			$sql	= "SELECT id, task_id, user_id, html_text, plain_text, date_created, date_modified, assignee, resolved, deleted 
						FROM xentask.comments 
						WHERE hash = '" . $commentHash . "'";

			$res	= $this->DB->query( $sql );

			$row	= $this->DB->fetch_assoc( $res );

			//  Assign If ID And Error If None
			if( !empty( $row ) ) {

				$this->id			= $row['id'];
				$this->taskId		= $row['task_id'];
				$this->userId		= $row['user_id'];
				$this->htmlText		= $row['html_text'];
				$this->plainText	= $row['plain_text'];
				$this->dateCreated	= $row['date_created'];
				$this->dateModified	= $row['date_modified'];
				$this->assignee		= $row['assignee'];
				$this->resolved		= $row['resolved'];
				$this->deleted		= $row['deleted'];

			} else {

				throw new Exception( 'INVALID COMMENT' );

			}

		} else if( !empty( $commentData ) ) {

			$this->deleted		= 0;

			foreach( $this->requiredFields as $field )
				if( !isset( $commentData[ $field ] ) )
					throw new Exception( 'MISSING REQUIRED FIELD' );

			$sql = '';

			$comment_html = '';

			$task_hash = 'AAF';

			foreach( $commentData as $key => $val ) {

				switch( strtolower( $key ) ) {
	
					case 'text':
	
						$sql .= ", plain_text = '" . addslashes( strip_tags( $val ) ) . "'";
						$sql .= ", html_text = '" . addslashes( $val ) . "'";
						$comment_html = $val;
						break;
	
					case 'assignee':
	
						$sql .= ", assignee = " . (int)$val;
	
						break;

					case 'taskid':

						$sql .= ", task_id = " . (int)$val;

						break;

					case 'taskhash':
						$task_hash	= $val;

					case 'listid':

						if( empty( $commentData['taskId'] ) )
							$sql .= ", list_id = " . (int)$val;
	
					default:
	
				}
	
			}

			//	Parse Comment For Mentions
			$dom = new DOMDocument();

			$dom->loadHTML($comment_html);

			$xpath = new DOMXPath($dom);

			$spans = $xpath->query('//span[@class="mention"]');
			
			//	Get Information On The Commentor
			//	To Fill The HTML Template
			$commentor = new xenUser( $commentData['user_id'] );

			//	Loop Through Each Span To Get The User ID Mentioned
			foreach( $spans as $span ) {
				 
				//	The User Who Is Being Mentioned
				$mention_user_id = $span->getAttribute('data-user-id');

				$USER = new xenUser( $mention_user_id );

				require_once LIB_CORE . 'Notification.php';

				$EMAIL          = New Notification();
	
				Template::$base_path	= TPL_BASE;
	
				$HTML = Template::render( 'content/notification.html', [
					'title' => 'New Comment Mention',
						'subtext'		=> 'By ' . $commentor->full_name,
						'profile_image' => $commentor->image,
						'profile_color'	=> !empty($commentor->color) ? $commentor->color : '#6610f2',
						'profile_name'	=> $commentor->initals,
						'body'			=> $comment_html,
						'link'			=> ( ( getenv('DEPLOY_ENV') == 'PROD' ) ? 'https://go.xentask.com/task/' : 'https://xentask-fe.' . strtolower( getenv('DEPLOY_ENV') ) . '.your-domain.com/task/' ) . $task_hash,
						'link_text'		=> 'View Task',
					]
				);
	
				$EMAIL->sendMessage(
						[
							'to_address' => [ 'email' => $USER->email ],
							'subject' => "New Comment Mention",
							'message_html' => $HTML
						]
				);
			}

			//  Gen Comment Hash
			$comment_hash	= UniqID::genUID( 'aaf', 10 );

			$sql			= "INSERT INTO xentask.comments SET" . ltrim( $sql, ",") .  ", user_id = " . (int)$_SESSION['USER']['id'] . ", hash = '" . $comment_hash . "'";

			$res			= $this->DB->query( $sql );

			
			$this->commentHash = $comment_hash;

			//	Bind Timestamp So We Don't Have To Pull From DB
			$currentTimestamp = time();
			$formattedDateTime = date('Y-m-d H:i:s', $currentTimestamp);
			$this->dateCreated = $formattedDateTime;

			if( empty( $res ) )
				throw new Exception( $sql );

		} else {

			throw new Exception( 'INVALID ACTION' );

		}

	}

	function update( $commentData ) {

		if( $this->deleted == 1 )
			throw new Exception( 'DELETED TASK' );

		$sql	= '';

		foreach( $commentData as $key => $val ) {

			switch( strtolower( $key ) ) {

				case 'text':

					$sql .= ", plain_text = '" . addslashes( strip_tags( $val ) ) . "'";
					$sql .= ", html_text = '" . addslashes( $val ) . "'";

					break;

				case 'assignee':

					if( $this->resolved == 0 ) {

						$sql .= ", assignee = " . (int)$val;
						$this->assignee	= (int)$val;

					}

					break;
				case 'resolved':

					if( !empty( $this->assignee) ) {

						$sql .= ", resolved = " . (int)$val;

						if( $val == 1 )
							$sql .= ", date_resolved = current_timestamp()";
						else
							$sql .= ", date_resolved = '0000-00-00 00:00:00'";

					}

					break;

				default:

			}

		}

		$sql	= "UPDATE xentask.comments SET" . ltrim( $sql, ",") .  " WHERE id = " . $this->id;

		$res	= $this->DB->query( $sql );

		if( empty( $res ) )
			throw new Exception( $sql );

	}

	function delete() {

		if( $this->deleted == 1 )
			throw new Exception( 'DELETED TASK' );

		if( $this->assignee != 0 && $this->resolved == 0 )
			throw new Exception( 'UNRESOLVED COMMENT' );

		if( $this->userId != $_SESSION['USER']['id'] ) 
			throw new Exception( 'ACCESS DENIED' );

		$sql	= "UPDATE xentask.comments SET deleted = 1, date_deleted = current_timestamp() WHERE id = " . $this->id;

		$res	= $this->DB->query( $sql );

		if( empty( $res ) )
			throw new Exception( $sql );

		$this->deleted	= 1;

		return( 0 );

	}

	static function search( $key ) {

		$DB  = new Database( );

		$sql	= "SELECT c.hash, c.plain_text as text, 'comment' as type, t.hash as task_hash, u.hash as user_hash, CONCAT( u.first_name, ' ', u.last_name ) as user_name, c.date_created, c.date_modified
					FROM xentask.comments c JOIN xentask.tasks t on c.task_id = t.id JOIN xentask.users u on c.user_id = u.id
					WHERE c.plain_text LIKE '%" . addslashes( $key ) . "%' and c.deleted = 0";

		$res	= $DB->query( $sql );

		$retArr	= [];

		while( $row = $DB->fetch_assoc( $res ) )
			$retArr[] = $row;

		return( $retArr );

	}

}