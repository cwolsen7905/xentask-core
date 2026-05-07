<?php

if( !class_exists( 'Base_Model' ) ) include 'Base_Model.php';

class xenAttachment extends Base_Model {

	// Attributes
	private $task_id;
	private $task_hash;
	private $user_id;
	private $filename;
	private $type;
	private $size;
	private $storage_hash;
	private $date_created;
	private $date_updated;
	private $hash;
	private $soft_deleted;

	function __construct( $hash ){

		parent::__construct();

		$sql		= "SELECT ta.*, t.hash as task_hash
						FROM task_attachments ta JOIN xentask.tasks t on ta.task_id = t.id  WHERE ta.hash = '" . $hash . "'";

		$res		= $this->DB->query( $sql );

		$attachment	= $this->DB->fetch_assoc( $res );

		$this->hash	= $hash;
		$this->task_id		= $attachment['task_id'];
		$this->task_hash	= $attachment['task_hash'];
		$this->user_id		= $attachment['user_id'];
		$this->filename		= $attachment['filename'];
		$this->type			= $attachment['type'];
		$this->size			= $attachment['size'];
		$this->storage_hash	= $attachment['storage_hash'];
		$this->date_created	= $attachment['date_created'];
		$this->date_updated	= $attachment['date_updated'];
		$this->soft_deleted	= $attachment['soft_deleted'];

    }

	public function deleteAttachment() {

		$sql	= "UPDATE xentask.task_attachments SET soft_deleted = 1 WHERE hash = '" . $this->hash . "'";

		$res	= $this->DB->query( $sql );

		return( ['message' => 'Attachment Deleted', 'res' => $res ] );

	}

	public function fetchAttachmentView() {

		return( [
			'filename'		=> $this->filename,
			'size'			=> $this->size,
			'type'			=> $this->type,
			'date_created'	=> $this->date_created,
			'date_updated'	=> $this->date_updated
		] );

	}

	public function renameAttachment( $dataArr ) {

	}

	public function readAttachment( ) {

		$file	= "/xentask/attachments/" . $this->task_hash . "/" . $this->storage_hash;

		if( $this->soft_deleted == 1 || !is_file( $file ) ) {

			header("HTTP/1.1 404 Not Found");
			exit;

		}

		header('Content-Type: ' . $this->type );
		header('Content-Disposition: attachment; filename="' . $this->filename . '"');
		header('Content-Transfer-Encoding: binary');
		header('Content-Length: ' . $this->size);

		readfile( $file );

		exit;

	}

	public function getThumb( ) {

		$file	= "/xentask/attachments/" . $this->task_hash . "/" . $this->storage_hash;

		if( $this->soft_deleted == 1 || !is_file( $file ) ) {

			header("HTTP/1.1 404 Not Found");
			exit;

		}

		$type	= explode( "/", $this->type );

		if( $type[0] == 'video' ) {

			$tmp_file	= "/tmp/" . $this->task_hash . "_" . $this->storage_hash . ".jpg";

			$process	= proc_open( "/usr/bin/ffmpeg -ss 1 -i " . $file . " -qscale:v 4 -frames:v 1 " . $tmp_file, array( 1 => array("pipe", "w"), 2 => array("pipe", "w") ), $pipes );

			$output		= stream_get_contents( $pipes[1] );

			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close($process);

			$image		= imagecreatefromjpeg( $tmp_file );
			system( "rm " . $tmp_file );

		} else {

			switch( $type[1] ) {

				case 'jpeg':
					$image = imagecreatefromjpeg( $file );  
					break;
				case 'gif':
					$image = imagecreatefromgif( $file );
					break;
				case 'webp':
					$process = proc_open( "/usr/bin/webpmux -info " . $file . " |grep animation", array( 1 => array("pipe", "w"), 2 => array("pipe", "w") ), $pipes );

					$output	= stream_get_contents( $pipes[1] );
					fclose( $pipes[1] );
					fclose( $pipes[2] );
					proc_close($process);

					if( strstr( $output, "anim") === false ) {
						$image	= imagecreatefromwebp( $file );
					} else {
						$tmp_file	= "/tmp/" . $this->task_hash . "_" . $this->storage_hash . ".webp";
						$process	= proc_open( "/usr/bin/webpmux -get frame 1 " . $file . " -o " . $tmp_file, array( 1 => array("pipe", "w"), 2 => array("pipe", "w") ), $pipes );
						$output		= stream_get_contents( $pipes[1] );
						fclose( $pipes[1] );
						fclose( $pipes[2] );
						proc_close($process);
						$image		= imagecreatefromwebp( $tmp_file );
						system( "rm " . $tmp_file );
					}

					break;

				case 'png':
					$image = imagecreatefrompng( $file );  
					break;
				
				default:
					header('Content-Type: ' . $this->type );
					header('Content-Disposition: attachment; filename="' . $this->filename . '"');
					header('Content-Transfer-Encoding: binary');
					header('Content-Length: ' . $this->size);
					readfile( $file );
					exit;

			}

		}

		// Use imagescale() function to scale the image 
		$img	= imagescale( $image, 134, 140 ); 
		header('Content-type: image/png');
		header('Content-Transfer-Encoding: binary');
		imagepng($img);
		exit;

	}

}