<?php

/**
Comment retrieval and storage for the headless CMS. Prototype.
@module comment-zero-repo.php
*/



namespace CommentZero;



/**
A simple interface describing access to an arbitrary data source.
@class comment_repository
*/
interface comment_repository
{
   public function connect($server, $dbname, $user, $pwd);
   public function fetch($postid, $moniker, $recent);
   public function save($object);
}



/**
A representation of a MySQL datasource using PDO.
@class mysql_comment_repository
*/
abstract class mysql_comment_repository implements comment_repository
{
   protected $db = NULL;

   # Set up a connection to the MySQL database
   public function connect( $server, $dbname, $user, $pwd )
   {
      $this->db = new \PDO('mysql:host=' . $server .
      ';dbname=' . $dbname . ';charset=utf8',
      $user, $pwd, array(\PDO::ATTR_EMULATE_PREPARES => false,
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
   }

}



/**
A representation of a Wordpress comment repository.
@class wordpress_comment_repository
*/
class wordpress_comment_repository extends mysql_comment_repository
{

   private $cmt_table = 'wp_comments';
   private $post_table = 'wp_posts';

   /**
   Fetch all comments for a single post.
   SQL injection vector: Make sure to sanitize/parameterize $moniker as it
   could be spoofed by user. $postid is already sanitized (if not null).
   @method fetch
   */
   public function fetch($postid, $unused, $recent)
   {
      $query_text = "
         SELECT comment_ID, comment_post_ID, comment_author,
                comment_content, comment_date, comment_author_url
         FROM wp_comments
         WHERE comment_post_ID = :postValParam
         AND comment_approved = 1
         ORDER BY comment_date DESC";

      $stmt = $this->db->prepare( $query_text );
      $stmt->bindParam( ':postValParam', $postid );
      if( !$stmt->execute() ) {
         // TODO: error
      }
      return $stmt;
   }   

   /**
   Save a new comment to the database.
   @method save
   */
   public function save( $object )
   {
      $q = $this->db->query( "SELECT ID FROM wp_posts WHERE ID = $object->postid" );
      $rows = $q->fetchAll();

      if( count( $rows ) !== 1 || $rows[0]['comment_status'] !== 'open' ) {
         $object->msg = 'Error.';
         return json_encode( $object );
      }

      $object->postid = $rows[0]['ID'];

      $stmt = $this->db->prepare("
         INSERT wp_comments
         SET comment_post_ID = :postid,
         comment_author = :author,
         comment_author_email = :email,
         comment_author_url = :website,
         comment_date = :date,
         comment_content = :content,
         comment_author_ip = :author_ip,
         comment_approved = 1");

      $stmt->bindParam( ':postid', $object->postid );
      $stmt->bindParam( ':author', $object->author );
      $stmt->bindParam( ':email', $object->email );
      $stmt->bindParam( ':website', $object->website );
      $stmt->bindParam( ':date', $object->date );
      $stmt->bindParam( ':content', $object->content );
      $stmt->bindParam( ':author_ip', $object->author_ip );

      $stmt->execute();
      $object->id = $this->db->lastInsertId();

      return json_encode($object);
   }
}




/**
A representation of the CommentZero comment repository.
@class comment_zero_repository
*/
class comment_zero_repository extends mysql_comment_repository
{

   private $cmt_table = 'wp_comments';
   private $post_table = 'wp_posts';

   /**
   Fetch all comments for a single post.
   SQL injection vector: Make sure to sanitize/parameterize $moniker as it
   could be spoofed by user. $postid is already sanitized (if not null).
   @method fetch
   */
   public function fetch($postid, $moniker, $recent)
   {
      $postKey = $postid !== 0 ? 'comment_post_ID' : 'moniker';
      $postVal = $postid !== 0 ? $postid : $moniker;
      $joinClause = $postid !== 0 ? "" : "INNER JOIN $this->post_table ON ID = comment_post_ID";
      $recentOnly = $recent ? " AND is_baked = 0 " : "";

      # INNER JOIN only needed if fetch comments by post moniker
      $query_text = "
         SELECT comment_ID, comment_post_ID, comment_author,
                comment_content, comment_date, comment_author_url
         FROM $this->cmt_table
         $joinClause
         WHERE $postKey = :postValParam
         AND comment_approved = 1
         $recentOnly
         ORDER BY comment_date DESC";

      $stmt = $this->db->prepare( $query_text );
      $stmt->bindParam( ':postValParam', $postVal );
      if( !$stmt->execute() ) {
         // TODO: error
      }
      return $stmt;
   }



   /**
   Mark all comments for the specified post as "baked".
   @method bake
   */
   public function bake( $postid )
   {
      $q = "UPDATE wp_comments SET is_baked = 1 WHERE comment_post_ID = $postid";
      $stmt = $this->db->prepare($q);
      $stmt->execute();
   }



   /**
   Save a new comment to the database.
   @method save
   */
   public function save( $object )
   {
      $key = $object->postid ? 'ID' : 'Moniker';
      $val = $object->postid ? $object->postid : "'{$object->moniker}'";

      $qtext = "
         SELECT ID, Moniker, AllowComments
         FROM wp_posts
         WHERE $key = $val";

      $q = $this->db->query( $qtext );
      $rows = $q->fetchAll();

      if( count( $rows ) == 1 ) {
         $object->postid = $rows[0]['ID'];
      }
      else {
         $stmt = $this->db->prepare("
            INSERT zero_container
            SET Moniker = :moniker
         ");
         $stmt->bindParam(':moniker', $object->moniker);
         $stmt->execute();
         $object->postid = $this->db->lastInsertId();
      }

      $stmt = $this->db->prepare(
         "INSERT zero_comments
         SET comment_post_ID = :postid,
         comment_author = :author,
         comment_author_email = :email,
         comment_author_url = :website,
         comment_date = :date,
         comment_content = :content,
         comment_author_ip = :author_ip,
         comment_approved = 1");

      $stmt->bindParam( ':postid', $object->postid );
      $stmt->bindParam( ':author', $object->author );
      $stmt->bindParam( ':email', $object->email );
      $stmt->bindParam( ':website', $object->website );
      $stmt->bindParam( ':date', $object->date );
      $stmt->bindParam( ':content', $object->content );
      $stmt->bindParam( ':author_ip', $object->author_ip );

      $stmt->execute();
      $object->id = $this->db->lastInsertId();

      return json_encode($object);
   }
}
