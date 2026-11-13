<?php

include 'testingcommon.inc';

$parent_tweet	= (int)1361405560000835595;

$result_array	= reply_tweet($conn, "Testing API, nobody should see this but a lone developer in an undisclosed location:".time(),'', '');

$tweet_id	= $result_array->id;
echo "Debug: tweet parent was $tweet_id\n";
//var_dump($result_array);

?>