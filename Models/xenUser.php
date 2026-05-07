<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenUser extends Base_Model {

    public $id;
    public $full_name;
    public $initals;
    public $email;
    public $image;
    public $color;

    function __construct($id){

        parent::__construct();

        $sql = "SELECT id,
                    image,
                    email,
                    color,
                    CONCAT(first_name, ' ', last_name) AS full_name,
                    CONCAT( SUBSTRING(first_name, 1, 1), SUBSTRING(last_name, 1, 1) ) AS initials
                FROM xentask.users WHERE id = " . $id;

        $res = $this->DB->query($sql);

        $user = $this->DB->fetch_assoc( $res );

        $this->id = $id;
        $this->full_name	= $user['full_name'];
        $this->initals		= $user['initials'];
        $this->email		= $user['email'];
        $this->image		= $user['image'];
        $this->color		= $user['color'];

    }

    function getUserTasks( $USER ) {

        $tasks = [];

        //  Get All The Tasks That's Assigned To The User Along With 
        //  Any Other Users That May Be Assigned To It
        $sql = "SELECT t.*, l.hash as list_hash, 
                    GROUP_CONCAT(DISTINCT a_all.user_id ORDER BY a_all.user_id) AS assignees
                FROM tasks t
                LEFT JOIN assignees a_all
                ON a_all.task_id = t.id
				LEFT JOIN xentask.lists l
				ON t.list_id = l.id
                WHERE t.id IN (
                    SELECT DISTINCT t1.id
                    FROM tasks t1
                    JOIN assignees a
                    ON a.task_id = t1.id
                    WHERE a.user_id = " . $this->id . "
                )
                GROUP BY t.id";

        $res = $this->DB->query( $sql );
       
        while( $row = $this->DB->fetch_assoc($res) ) {

            //  Makes Sure The Assignees Is An Array Before Displaying On FE Side
            if( !empty( $row['assignees'] ) )
                $row['assignees'] = explode( ',' , $row['assignees'] );
            else
                $row['assignees'] = [];

            $tasks[] = $row;

            // Get Only The Custom Field Values That Are Global
            // We Bind It To The Array For Easier Access
            $sql = "SELECT cf.hash, cfv.custom_field_type, value FROM xentask.custom_fields_values cfv JOIN xentask.custom_fields cf ON cfv.custom_field_id = cf.id 
                        WHERE cfv.task_id = " . $row['id'] . " 
                        AND cf.hash = ' " . $USER['default_workspace'] . "'";

            $res2 = $this->DB->query($sql);

            while( $row2 = $this->DB->fetch_assoc($res2) ) {
                
                //  Turn Labels Value Back Into An Array
                if( $row2['custom_field_type'] == 'labels' ) {
                    
                    if( !empty( $row2['value'] ) )
                        $row2['value'] = explode( ',' , $row2['value'] );
                    else
                        $row2['value'] = [];

                }

                $tasks[ count($tasks) - 1 ][ $row2['hash'] ] = $row2['value'];

            }

        }

        return $tasks;

	}

    function updateUserData( $REQUEST, $FILES ) {

        $first_name = $REQUEST['first_name'];
        $last_name = $REQUEST['last_name'];
        $email = $REQUEST['email'];
        $color = $REQUEST['color'];

        $sql = "UPDATE users SET 
                        first_name = '$first_name',
                        last_name = '$last_name',
                        color = '$color',
                        email = '$email'
                    WHERE id = " . $this->id;

        //  Upload File And Set The SQL Statement To Return Path
        if( !empty( $FILES ) ){

        }

        $res = $this->DB->query($sql);

        if( $res ){

            //  Refresh Session Data So When Overview Is Hit again It'll Have The Updated Data
            $_SESSION['USER']['first_name'] = $first_name;
            $_SESSION['USER']['last_name'] = $last_name;
            $_SESSION['USER']['email'] = $email;
            $_SESSION['USER']['color'] = $color;
            $_SESSION['USER']['initals'] = substr($_SESSION['USER']['first_name'], 0, 1) . substr($_SESSION['USER']['last_name'], 0, 1);

		}

		return $res;

    }

    function updatePassword( $DATA ){

		// First Validate If The User's Current Password Matches 
		// What They Posted.
		$sql	= "SELECT id, password FROM users WHERE id = " . $this->id;

		$MATCH	= $this->DB->fetch1($sql);

		if( !empty( $MATCH ) && ( $DATA['current_password'] == $MATCH['password'] || $DATA['current_password'] == MemcacheUtil::get( base64_encode( 'RECOVERY_PASS_' . $this->email ) ) ) ) {

			MemcacheUtil::delete( base64_encode( 'RECOVERY_PASS_' . $this->email ) );

			$sql = "UPDATE users SET password = '" . $DATA['new_password'] . "' WHERE id = " . $this->id;

			$res = $this->DB->query($sql);

			return [ 'status' => $res ];

		}

		Response::returnJSON( 400, [ 'err_code' => 'U0003', 'err_string' => 'The Current Password Is Invalid' ] );

    }

}