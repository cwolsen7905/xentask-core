<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenStatus extends Base_Model { 

    private $status_id;

    private $status_str;

    private $status_color;

    private $status_type;

    private static $default_status_color = '#aabbcc';

    private static $default_cancelled_color = '#dc3545';

    private static $defualt_done_color = '#20c997';
    
    function __construct( $status_id = -1 ) {

        $this->$status_id   = $status_id;

        switch( $status_id ) {

            case 0:
                $this->status_str   = "Open";
                $this->status_color = "#00FF00";
                $this->status_type  = "open";
                break;

            default:
                $this->status_str   = "Invalid";

        }
    
    }

    public static function getStatuses( $space_hash ) {

        $statuses = [];

        $DB = new Database( );

        //  Keep This Double Status_hash Here For Now 
        $sql = "SELECT s.hash, s.name, s.color, s.is_default, s.type, s.order_index 
					FROM xentask.statuses s JOIN xentask.spaces sp ON s.space_id = sp.id
				WHERE sp.hash = '" . $space_hash . "' ORDER BY s.order_index ASC";

        $res = $DB->query( $sql );

        while( $row = $DB->fetch_assoc( $res ) ) {
            
            $row['is_default']  = (int)$row['is_default'];
            $row['order_index'] = (int)$row['order_index'];
            $statuses[] = $row;

        }

        return $statuses;

    }

    /**
     * These Are Default Statuses That Are Created When A Space Is Created
     * 
     */
    public static function createDefaultStatuses( $space_id, $USER ) {

        $DB = new Database( );

        $opened_status_hash	= UniqID::genUID( 'aaf', 10 );
        $done_status_hash	= UniqID::genUID( 'aaf', 10 );
        $closed_status_hash = UniqID::genUID( 'aaf', 10 );

        //	If Everything Was Good Create The Default Statuses For A Space
        $sql = "INSERT INTO xentask.statuses (
                            space_id, 
                            hash, 
                            name, 
                            color, 
                            is_default, 
                            type, 
                            created_by_user 
                        ) VALUES 
                    (
                        " . (int)$space_id . ",
                        '" . (string)$opened_status_hash . "',
                            'Open',
                            '" . self::$default_status_color . "',
                            1,
                            'not_started',
                        " . (int)$USER['id'] . "
                    ),
                    (
                        " . (int)$space_id . ",
                        '" . (string)$done_status_hash . "',
                            'Done',
                            '" . self::$defualt_done_color . "',
                            1,
                            'completed',
                        " . (int)$USER['id'] . "
                    ),
                    (
                        " . (int)$space_id . ",
                        '" . (string)$closed_status_hash . "',
                            'Cancelled',
                            '" . self::$default_cancelled_color . "',
                            1,
                            'cancelled',
                        " . (int)$USER['id'] . "
                    )";

        $res = $DB->query($sql);

        if( !$res ) Response::returnJSON( 400, [ 'err_code' => 'S0002', 'err_string' => 'INVALID SPACE ACTION', 'sql' => $sql ] );
        
    }

    public static function bulkStatusesUpdate( $space_id, $DATA, $USER ) {

        foreach( $DATA as $CHANGES => $VALUES ) {

            foreach( $VALUES as $ITEM ) {

                switch( $CHANGES ) {

                    //  Any New Statuses Added
                    case('added'):
                        self::addStatus( $space_id, $ITEM, $USER );
                    break;
    
                    case('updates'):
                        self::updateStatus( $space_id, $ITEM );
                    break;
    
                    case('deleted'):
                        self::deleteStatus( $space_id, $ITEM );
                    break;
    
                }

            }

		}

    }

    public static function addStatus( $space_id, $ITEM, $USER ) {

        $status_hash = UniqID::genUID( 'aaf', 10 );

        $sql = "INSERT INTO statuses 
                    SET name = '" . $ITEM['name'] . "',
                        color = '" . ( empty( $ITEM['color'] ) ? self::$default_status_color : $ITEM['color'] ) . "',
                        order_index = " . (int)$ITEM['order_index'] . ",
                        type = '" . $ITEM['type'] . "',
                        is_default = 0,
                        space_id = ". (int)$space_id . ",
                        created_by_user = " . (int)$USER['id'] . ",
                        hash = '" . $status_hash . "'";

        $DB = new Database( );

        $res = $DB->query($sql);

        if( !$res )  Response::returnJSON( 400, [ 'err_code' => 'S0005', 'err_string' => 'ERROR INSERTING STATUS', 'SQL' => $sql ] );

    }

    public static function updateStatus( $space_id, $ITEM ) {

        if( empty( $ITEM ) )  Response::returnJSON( 400, [ 'err_code' => 'S0005', 'err_string' => 'MISSING PARAMETERS' ] );
        
        $DB = new Database( );

        $ALLOWED = [ 
            'name',
            'color',
            'order_index',
            'type',
		];

		$setClause = self::buildSetClause( $ITEM, $ALLOWED );

		$sql = "UPDATE statuses SET $setClause WHERE hash = '" . $ITEM['id'] . "' AND space_id = " . (int)$space_id;

		$res = $DB->query($sql);

        if( !$res )  Response::returnJSON( 400, [ 'err_code' => 'S0005', 'err_string' => 'ERROR UPDATING STATUS', 'SQL' => $sql ] );
		
        return $res;

    }

    /**
     * @param {int} $space_id - The Space ID The Status Belongs To
     * @param {string} $id - The Status Hash ID
     */
    public static function deleteStatus( $space_id, $hash ) {
        
        $DB = new Database( );

        $sql = "DELETE FROM statuses WHERE hash = '" . $hash . "' AND space_id = " . (int)$space_id;

        $res = $DB->query($sql);

        if( !$res )  Response::returnJSON( 400, [ 'err_code' => 'S0005', 'err_string' => 'ERROR DELETING STATUS', 'SQL' => $sql ] );

    }

    function getName() {

        return( $this->status_str );

    }

    function getId() {

        return( $this->status_id );

    }

}