<?php
require "lib/comment-zero.php";
$handler = new CommentZero\request_handler();
$handler->go(
   true,
   'Server',
   'Database', 
   'User',
   'Password'
);
