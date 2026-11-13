<?php
require_once __DIR__ . '/vendor/autoload.php';  
include 'common.inc'; 

//Take a TFR ID and use that to get the image and text for posting to FB

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
	$fb_post_text	= file_get_contents("/var/www/html/rendered_maps/$tfr_id-deleted.txt");
} else
{
	$fb_post_text	= file_get_contents("/var/www/html/rendered_maps/$tfr_id.txt");
}

if(file_exists("/var/www/html/rendered_maps/$tfr_id.png") && isset($fb_post_text))
{
	//Is good
} else
{
	echo "Either PNG was not found or error with fb_post_text\n";
	exit();
}

echo "Initializing FB API...\n";

$fb = new \Facebook\Facebook([
  'app_id' => bot_cfg('facebook', 'app_id'),           //Replace {your-app-id} with your app ID
  'app_secret' => bot_cfg('facebook', 'app_secret'),   //Replace {your-app-secret} with your app secret
  'graph_api_version' => 'v5.0',
]);

$pageId	 = "106573441470619";

$foreverPageAccessToken	= bot_cfg('facebook', 'page_access_token');

$fb->setDefaultAccessToken($foreverPageAccessToken);

//$fb->sendRequest('POST', "$pageId/feed", [
//    'message' => 'This is a debug post for API testing',
//    'link' => 'http://twitter.com/SpaceTFRs',
//]);


$fb->sendRequest('POST', "$pageId/photos", [
    'message' => $fb_post_text,
    'url' => "http://104.225.140.153/rendered_maps/$tfr_id.png",
]);

//var_dump($fb->sendRequest('GET', '/debug_token', ['input_token' => $foreverPageAccessToken])->getDecodedBody());

?>