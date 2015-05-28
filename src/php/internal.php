<?php

# Called from the local build machine by the Ichabod synchronizer plugin for Jekyll.
# Will be passed the unique ID of each Jekyll document/post.

require "lib/comment-zero.php";
$handler = new CommentZero\request_handler();
$handler->go(
   false,
   'Server',
   'Database', 
   'User',
   'Password'
);
