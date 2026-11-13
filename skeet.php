<?php
//10-8-23
//Post a skeet with an image if available
//Use mastodon model

include 'common.inc';

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
	$text	= file_get_contents("/var/www/html/rendered_maps/$tfr_id-deleted.txt");
} else
{
	$text	= file_get_contents("/var/www/html/rendered_maps/$tfr_id.txt");
}

if(file_exists("/var/www/html/rendered_maps/$tfr_id.png") && isset($text))
{
	//Is good
} else
{
	echo "Either PNG was not found or error with skeet\n";
	//TODO add a discord alert
	dy_discord("Error with skeet in tfr");
	exit();
}



$config['bluesky-username']="bocaroad.bsky.social";
$config['bluesky-password']=bot_cfg('bluesky_skeetphp', 'app_password');

echo "Planned skeet: $text\n";

//Upload the file and get the deets

$outputString	= shell_exec("./bsky_image_upload.sh -files /var/www/html/rendered_maps/$tfr_id.png");

// Use regular expression to extract JSON
if (preg_match_all('/Response for (.*?):(.*?)(?:Response|\z)/s', $outputString, $output_array)) 
{
    $jsonString = trim($output_array[2][0]);
    
    // Decode the JSON
    $jsonData = json_decode($jsonString, true); // Use true to decode as an associative array
    
    if ($jsonData !== null) {
        echo "Blob Reference: " . $jsonData['blob']['ref']['$link'] . "\n";
        echo "MIME Type: " . $jsonData['blob']['mimeType'] . "\n";
        echo "Size: " . $jsonData['blob']['size'] . "\n";
    } else {
        echo "Failed to parse JSON response.\n";
    }
} else {
    echo "JSON data not found in the output.\n";
}
exit();

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.server.createSession',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS =>'{
    "identifier":"'.$config['bluesky-username'].'",
    "password":"'.$config['bluesky-password'].'"
}',
  CURLOPT_HTTPHEADER => array(
    'Content-Type: application/json'
  ),
));

$response = curl_exec($curl);
curl_close($curl);
$session=json_decode($response,TRUE);

// That's got the auth bearer, and other bits of session data
// So now we need to post the message

$curl = curl_init();

curl_setopt_array($curl, array(
  CURLOPT_URL => 'https://bsky.social/xrpc/com.atproto.repo.createRecord',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'POST',
  CURLOPT_POSTFIELDS =>'{
    "repo":"'.$session['did'].'",
    "collection":"app.bsky.feed.post",
    "record":{
        "$type":"app.bsky.feed.post",
        "createdAt":"'.date("c").'",
        "text":"'.$text.'"
    }
}',
  CURLOPT_HTTPHEADER => array(
    'Content-Type: application/json',
    'Authorization: Bearer '.$session['accessJwt']
  ),
));

$response = curl_exec($curl);

var_dump($response);

curl_close($curl);

echo "SKEET: Sent: ".$text.' skeet';
?>