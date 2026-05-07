<?php
class Base_Model {

    protected $DB;

    //  Can Store Anything In Here
    public static $DEBUG = [];

    public function __construct( $ENV = '' ) {
     
        $this->DB  = new Database( $ENV );

    }

    public static function buildSetClause( $DATA, $ACCEPTED = [] ) {

        $setClause = [];

        foreach( $DATA as $key => $value ) {

            //  Don't Allow The User To Pass In Whatever They Want
            if( !empty( $ACCEPTED ) )
                if( !in_array( $key, $ACCEPTED ) ) continue;

            // Handle different data types
            if( is_bool( $value ) ) {
                $value = $value ? 1 : 0;
                $setClause[] = "$key = $value";
            } elseif( is_int( $value ) || is_float( $value ) ) {
                $setClause[] = "$key = $value";
            } elseif ( is_null( $value ) ) {
                $setClause[] = "$key = NULL";
            } else {

                // Escape the value here to prevent SQL injection
                $setClause[] = "$key = '$value'";

            }

        }

        return implode( ", ", $setClause );

    }

    //  Ensures That We Have All The Required Variables
    public static function checkRequired( $DATA, $REQUIRED ) {

        // Check if all required keys are present in the data array
        foreach ( $REQUIRED as $key )
            if( !array_key_exists( $key, $DATA ) ) return false;

        return true; // Return true if all required keys are present

    }

}