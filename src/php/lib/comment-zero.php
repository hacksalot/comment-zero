<?php

/**
Comment retrieval and storage for the headless CMS. Prototype.
@module comment-zero.php
*/



namespace CommentZero;
require 'comment-zero-repo.php';
require 'dep/throttle/throttle.php';
require_once 'dep/Michelf/MarkdownExtra.inc.php';



/**
Process HTTP requests on the CommentZero comments endpoint.
@class request_handler
*/
class request_handler
{



   /**
   Handle incoming requests.
   @method go
   */
   public function go($recent, $server, $dbname, $user, $pwd)
   {
      try {
         # Uncomment to enable CORS (eg, for localhost access)
         header('Access-Control-Allow-Origin: *');
         $this->repo = new wordpress_comment_repository;
         $this->repo->connect($server, $dbname, $user, $pwd);
         $res = $this->execute( $recent );
         $this->respond(200);
         if( $res ) echo $res;
      }
      catch(PDOException $ex) {
         $this->respond(500);
         echo $ex->getMessage();
         exit;
      }
   }



   /**
   Execute the appropriate action based on HTTP method type.
   @method execute
   */
   private function execute( $recent )
   {
      $method = $_SERVER['REQUEST_METHOD'];
      if( $method == 'GET' )
         return $this->execute_get( $recent );
      else if( $method == 'POST' )
         return $this->execute_post();
      $this->respond( 405 ); # Method Not Allowed
      exit;
   }



   /**
   Execute actions for GET requests.
   @method execute_get
   */
   private function execute_get( $recent )
   {
      // Extract query params.
      $pid = $_GET['pid'];
      $mid = $_GET['mid'];
      if( !$pid ) $pid = 0;

      // Fetch and JSON-ify the list of comments
      $q = $this->repo->fetch( $pid, $mid, $recent );
      $out = new \StdClass;
      $ret = $this->assemble( $q, $out );

      // Set the comments for this post as "baked".
      // Note: this doesn't violate the "GETs should be idempotent" rule
      // (http://restcookbook.com/HTTP%20Methods/idempotency/) because it
      // doesn't change the representation of the resource.
      if( !$recent && $out->postid ) $this->repo->bake( $out->postid );
      return $ret;
   }



   /**
   Execute actions for POST requests.
   @method execute_post
   */
   private function execute_post()
   {
      // Extract and validate comment fields
      $object = $this->extract();
      if( !$this->validate( $object ) ) exit;

      // Throttle overly-enthusiastic users
      $wait_time = 0;
      session_start();
      throttle(array(
         'id'            => 'submit-comment',
         'throttleKey'   => 'rl1',
         'timeout'       => 60,  // Throttle user for 60 seconds
         'passes'        => 2,   // if they attemps this action MORE than 2 times
         'interval'      => 60,  // within 60 seconds
         'throttled'     => function( $seconds ) use ( &$wait_time ) { $wait_time = $seconds; }
      ));
      if( $wait_time > 0 ) {
         $this->set_error( Errors::ActionThrottled, "comment", "$wait_time seconds" );
         die();
      }

      // Save
      return $this->repo->save( $object );
   }



   /**
   Extract comment fields from the inbound POST data.
   @method extract
   */
   private function extract()
   {
      $object = new \StdClass;
      $object->id = NULL;
      $object->postid = filter_var( $_POST['postid'], FILTER_SANITIZE_NUMBER_INT );
      $object->moniker = filter_var( $_POST['moniker'], FILTER_SANITIZE_STRING );
      $object->author = filter_var( $_POST['author'], FILTER_SANITIZE_STRING );
      $object->email = filter_var( $_POST['email'], FILTER_SANITIZE_EMAIL );
      $object->website = filter_var( $_POST['website'], FILTER_SANITIZE_URL );
      $object->content = filter_var( $_POST['content'], FILTER_SANITIZE_STRING );
      $object->author_ip = $_SERVER['REMOTE_ADDR'];
      $object->referer = $_SERVER['HTTP_REFERER'];
      date_default_timezone_set("UTC");
      $object->date = date("Y-m-d H:i:s", time());
      return $object;
   }



   /**
   Extract comment fields from the outbound rowset data.
   @method assign
   */
   private function assign($object, $row)
   {
      $object->id = $row['comment_ID'];
      $object->postid = $row['comment_post_ID'];
      $object->author = $row['comment_author'];
      $object->date = $row['comment_date'];
      $object->content = \Michelf\MarkdownExtra::defaultTransform( $row['comment_content'] );
      $object->url = $row['comment_author_url'];
      return $object;
   }



   /**
   Assemble the outbound comments JSON from dataset.
   Avoiding creating a monolithic array of comments and JSONifying them in one
   fell swoop (there may be thousands of comments). Instead, assemble the JSON
   incrementally using a single temporary.
   @method assemble
   */
   private function assemble($query, $out)
   {
      $first_row = $query->fetch();
      $actual_post_id = $first_row['comment_post_ID'];
      if(!$actual_post_id) {
         return "{ }";
      }
      $out->postid = $actual_post_id;

      $comments = "{ \"postid\": \"$actual_post_id\", \"comments\": [ ";
      $object = new \StdClass;
      $this->assign($object, $first_row);
      $comments .= json_encode($object);

      foreach ($query as $row) {
         $object = $this->assign($object, $row);
         $comments .= "," . json_encode($object);
      }

      $comments .= " ] }";
      return $comments;
   }



   /**
   Validate the inbound comment fields.
   @method validate
   */
   private function validate( $comment )
   {
      if(strlen($comment->author) > 250) {
         $this->set_error(Errors::FieldTooLong, 'author', '250');
         return false;
      }
      else if($comment->website != NULL && !ctype_space($comment->website) && !filter_var($comment->website, FILTER_VALIDATE_URL)) {
         $this->set_error(Errors::InvalidField, 'website', 'hyperlink');
         return false;
      }
      else if(strlen($comment->website) > 200) {
         $this->set_error(Errors::FieldTooLong, 'website', '200');
         return false;
      }
      else if($comment->email != NULL && !ctype_space($comment->email) && !filter_var($comment->email, FILTER_VALIDATE_EMAIL)) {
         $this->set_error(Errors::InvalidField, 'email', 'email');
         return false;
      }
      else if(strlen($comment->email) > 100) {
         $this->set_error(Errors::FieldTooLong, 'email', '100');
         return false;
      }
      else if($comment->content == NULL || ctype_space($comment->content)) {
         $this->set_error(Errors::FieldEmpty, 'comment', 'comment');
         return false;
      }
      else if(strlen($comment->content) > 50000) {
         $this->set_error(Errors::FieldTooLong, 'content', '20,000');
         return false;
      }
      return true;
   }



   /**
   Issue formal HTTP response headers.
   @method respond
   */
   private function respond($code)
   {
      header('Content-Type: application/json');
      header("HTTP/1.0 $code {$this->http_codes[$code]}");
   }



   /**
   Issue formal HTTP error code and metadata.
   @method set_error
   */
   private function set_error( $formatString, $field, $field2 )
   {
      $msg = sprintf( $formatString, $field, $field2 );
      $this->respond( 400 );
      $ret = "{ \"field\": \"$field\", \"error\": \"$msg\", \"status\": \"error\" }";
      echo $ret;
   }



   /**
   An array of HTTP error codes per http://stackoverflow.com/q/3913960.
   @property http_codes
   */
   private $http_codes = array(
      200 => "Ok",
      400 => "Bad Request",
      404 => "Not Found",
      405 => "Method Not Allowed",
      500 => "Internal Server Error"
   );

   private $repo;
}



/**
A simple idiom for pseudo-enumeration error codes in PHP.
@class Errors
*/
abstract class Errors
{
    const FieldEmpty = "The %s is empty. Please specify a valid %s.";
    const FieldTooLong = "The %s field is too long. %d character maximum.";
    const FieldTooShort = "The %s field is too short. %d character minimum.";
    const InvalidField = "Invalid or incomplete %s. Please specify a valid %s.";
    const ActionThrottled = "You are attempting to %s too quickly! Please wait %s and try again.";
    // etc.
}
