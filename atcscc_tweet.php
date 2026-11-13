<?php
//Tweet the appropriate atcscc_id

include 'common.inc';

$atcscc_id	= $argv[1];
$tweet_reply	= $argv[2];

if(empty($atcscc_id))
{
	echo "Pass an ID, jerk.\n";
	exit();
}


$sqlstring	= "SELECT * FROM atcscc WHERE atcscc_id = '$atcscc_id'";
$result		= mysqli_query($conn, $sqlstring);
while ($row	= mysqli_fetch_assoc($result))
{
	$unique		= $row['atcscc_unique'];
	$atcscc_tweet	= $row['atcscc_tweet'];

	$rendered_oplan	= preg_replace("/[^a-z0-9.]+/i", "", $unique.".png"); //Filename of rendered excerpt

	echo "Tweeting: $atcscc_tweet\nImage: $rendered_oplan\n";
	$status	= new_tweet($conn, $atcscc_tweet, array("$rendered_oplan"),$tweet_reply);

	pushbullet("Tweeted $atcscc_tweet/$rendered_oplan");
}


?>