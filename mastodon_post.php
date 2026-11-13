<?php
require 'tootophp/autoload.php';
include 'common.inc'; 

$tootoPHP = new TootoPHP\TootoPHP('botsin.space');
$app = $tootoPHP->registerApp('SpaceTFRs', 'http://deltayeet.net');

//Take a TFR ID and use that to get the image and text for posting to Mastodon

$tfr_id	= $argv[1];
$mode	= $argv[2];

if(!isset($tfr_id))
{
	echo "TFR DB ID not passed.\n";
	exit();
}

//Validate ID
$sqlstring	= "SELECT COUNT(*) AS cnt FROM tfr_list WHERE tfr_id = '$tfr_id'";
if(mysqli_query($conn,$sqlstring)->fetch_object()->cnt >= 1)
{
	//Valid
} else
{
	echo "Invalid TFR ID.  Find in tfr_list table.\n";
	exit();
}

if($mode	== "withdrawn")
{
	$mastodon_post_text	= file_get_contents("/var/www/html/rendered_maps/$tfr_id-deleted.txt");
} else
{
	$mastodon_post_text	= file_get_contents("/var/www/html/rendered_maps/$tfr_id.txt");
}

if(file_exists("/var/www/html/rendered_maps/$tfr_id.png") && isset($mastodon_post_text))
{
	//Is good
} else
{
	echo "Either PNG was not found or error with mastodon_post_text\n";
	//TODO add a discord alert
	exit();
}

//$img1	= $app->createAttachement("/var/www/html/rendered_maps/mastodon_$tfr_id.png");

//print_r($img1);

echo "Posting...\n";
//print_r($app->PostStatus($mastodon_post_text, 'public',$img1['id']));

echo "Attaching: /var/www/html/rendered_maps/mastodon_$tfr_id.png\n";

$mastodon_id	= mastodon_post($mastodon_post_text, "/var/www/html/rendered_maps/mastodon_$tfr_id.png");
$sqlstring	= "UPDATE tfr_list SET mastodon_status = '$mastodon_id' WHERE tfr_id = '$tfr_id'";
$result		= mysqli_query($conn, $sqlstring);


//$fb->sendRequest('POST', "$pageId/photos", [
//    'message' => $fb_post_text,
//    'url' => "http://104.225.140.153/rendered_maps/$tfr_id.png",
//]);

//var_dump($fb->sendRequest('GET', '/debug_token', ['input_token' => $foreverPageAccessToken])->getDecodedBody());

?>