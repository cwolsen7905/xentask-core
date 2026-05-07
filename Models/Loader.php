<?php

$_XMBP = dirname( __FILE__ ) . "/";

if( !class_exists('Base_Model' ) ) include $_XMBP . 'Base_Model.php';

function loadModel( $modelName ) {
global $_XMBP;

	if( class_exists( $modelName ) ) {

		return $modelName;

	} else if ( file_exists( $_XMBP . $modelName . ".php" ) ) {

		include $_XMBP . $modelName . ".php";

		return $modelName;

	}

	return false;

}

function loadModels( $models ) {

	foreach( $models as $model ) {
		
		if( !loadModel( $model ) ) {

			throw new LogicException( "Unable to load Model: " . $model );

		}

	}

}