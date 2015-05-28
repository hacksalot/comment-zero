<?php
# Debug only: enable CORS for localhost access via AJAX
header('Access-Control-Allow-Origin: *');
require "lib/comment-zero.php";
$handler = new CommentZero\request_handler();
$handler->go(
   true,
   $_ENV{DATABASE_SERVER},
   "db161109_xk1", 
   "db161109_xkuser",
   "A100MillionApplesBeneathTheTree!"
);
