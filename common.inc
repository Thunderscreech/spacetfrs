<?php
include('vendor/autoload.php');
//use Abraham\TwitterOAuth\TwitterOAuth;

use Orhanerday\OpenAi\OpenAi;

// Credentials and private settings live in config.ini next to this file (not in git; see config.example.ini).
// bot_cfg('section', 'key') returns the value as a string ('' when unset).
function bot_cfg($section, $key)
{
	static $cfg = null;
	if ($cfg === null) {
		$file	= __DIR__ . '/config.ini';
		$cfg	= is_readable($file) ? parse_ini_file($file, true, INI_SCANNER_RAW) : false;
		if (!is_array($cfg)) {
			error_log("cannot read $file (copy config.example.ini and fill it in)");
			die("Missing or unreadable config.ini\n");
		}
	}
	return (string)($cfg[$section][$key] ?? '');
}

//DB connect - do it every time
$dbhost = bot_cfg('database', 'host');
$dbuser = bot_cfg('database', 'user');
$dbpass = bot_cfg('database', 'password');

$conn = mysqli_connect($dbhost, $dbuser, $dbpass) or die ('Error connecting to mysql');

$dbname = bot_cfg('database', 'name');
mysqli_select_db($conn, $dbname) or die("Error selecting DB");



/*::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::*/
/*::                                                                         :*/
/*::  This routine calculates the distance between two points (given the     :*/
/*::  latitude/longitude of those points). It is being used to calculate     :*/
/*::  the distance between two locations using GeoDataSource(TM) Products    :*/
/*::                                                                         :*/
/*::  Definitions:                                                           :*/
/*::    South latitudes are negative, east longitudes are positive           :*/
/*::                                                                         :*/
/*::  Passed to function:                                                    :*/
/*::    lat1, lon1 = Latitude and Longitude of point 1 (in decimal degrees)  :*/
/*::    lat2, lon2 = Latitude and Longitude of point 2 (in decimal degrees)  :*/
/*::    unit = the unit you desire for results                               :*/
/*::           where: 'M' is statute miles (default)                         :*/
/*::                  'K' is kilometers                                      :*/
/*::                  'N' is nautical miles                                  :*/
/*::  Worldwide cities and other features databases with latitude longitude  :*/
/*::  are available at https://www.geodatasource.com                          :*/
/*::                                                                         :*/
/*::  For enquiries, please contact sales@geodatasource.com                  :*/
/*::                                                                         :*/
/*::  Official Web site: https://www.geodatasource.com                        :*/
/*::                                                                         :*/
/*::         GeoDataSource.com (C) All Rights Reserved 2018                  :*/
/*::                                                                         :*/
/*::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::*/

function get_faa_content($url) {
    $ch = curl_init();
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    
    // Create a temporary file to store cookies for this session
    $cookieFile = tempnam(sys_get_temp_dir(), 'faa_cookie');

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_COOKIEJAR => $cookieFile,  // Write cookies here
        CURLOPT_COOKIEFILE => $cookieFile, // Read cookies from here
        CURLOPT_TIMEOUT => 30
    ]);

    // First request: Hits the splash screen, gets the cookie
    curl_exec($ch);

    // Second request: Uses the cookie to bypass the splash screen
    $content = curl_exec($ch);
    
    curl_close($ch);
    unlink($cookieFile); // Clean up
    
    return $content;
}


// 2026-11-13: FAA outages used to send a Discord alert on every run (every 3 minutes).
// Now: stay quiet until the feed has been failing for over an hour, then alert at most once a day.
// "Down since" is the last successful fetch, i.e. when tfrs.json was last written.
function tfr_fetch_failed($msg)
{
    $down_after  = 3600;   // seconds of failure before the first alert
    $alert_every = 86400;  // minimum seconds between alerts
    $state_file  = __DIR__ . '/tfr_last_alert.txt';

    $last_ok    = @filemtime(__DIR__ . '/tfrs.json') ?: time();
    $down_for   = time() - $last_ok;
    $last_alert = (int)@file_get_contents($state_file);
    echo "$msg (failing for " . round($down_for / 60) . " min)\n";

    if ($down_for > $down_after && time() - $last_alert >= $alert_every) {
        dy_discord("$msg Failing since " . tfr_local_time($last_ok) . " (" . round($down_for / 3600, 1) . " hours). Next alert in 24h at the earliest.");
        file_put_contents($state_file, time());
    }
}

// PHP runs in UTC here; show times in the server's own zone (America/New_York etc.)
function tfr_local_time($ts)
{
    $d  = new DateTime('@' . $ts);
    $tz = trim((string)@file_get_contents('/etc/timezone'));
    if ($tz !== '' && in_array($tz, timezone_identifiers_list())) $d->setTimezone(new DateTimeZone($tz));
    return $d->format('M j g:ia T');
}

function get_new_tfrs($conn)
{
    $target_url = "https://tfr.faa.gov/tfrapi/getTfrList";
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    
    // Create a temp file for cookies
    $cookieFile = tempnam(sys_get_temp_dir(), 'faa_cookie');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $target_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 30
    ]);

    // First attempt
    $json = curl_exec($ch);

    // If we see HTML (the splash screen), the cookie is now in our cookieJar.
    // We hit the URL a second time to bypass the banner.
    if (stripos($json, '<!DOCTYPE') !== false) {
        echo "Splash screen detected, retrying with cookies...\n";
        $json = curl_exec($ch);
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    unlink($cookieFile);

    if ($json === false || $httpCode !== 200) {
        tfr_fetch_failed("Failed to load TFR data from FAA. HTTP Code: $httpCode" . ($curlError !== '' ? " ($curlError)" : ''));
        return null;
    }

    // Double check we actually got JSON and not the splash screen again
    if (stripos($json, '<!DOCTYPE') !== false) {
        tfr_fetch_failed("Stuck on FAA splash screen. Manual intervention may be required.");
        return null;
    }

    file_put_contents(__DIR__ . '/tfrs.json', $json);

    $tfr_array = json_decode($json, true);
    
    // Handle cases where JSON might be malformed or empty
    if (!is_array($tfr_array)) {
        echo "Error decoding JSON. Response length: " . strlen($json) . "\n";
        return null;
    }

    $return_list = [];

    foreach ($tfr_array as $notam) 
    {
        $notam_id = $notam['notam_id'];
        $notam_type = $notam['type'] ?? '';

        // Skip if it's not "SPACE OPERATIONS"
        if ($notam_type !== 'SPACE OPERATIONS') 
        {
            continue;
        }

        // Prepared statement to prevent SQL injection
        // NOTAM IDs repeat every 10 years ("6/7130" = 2016, 2026, 2036...), so only look back 5 years
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM tfr_list WHERE notam = ? AND date_added > NOW() - INTERVAL 5 YEAR");
        $stmt->bind_param("s", $notam_id);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        // If NOTAM is not in the database, add it to return list
        if ($count == 0) 
        {
            echo "$notam_id is new\n";
            $return_list[] = $notam;
        } else 
        {
            echo "$notam_id in DB\n";
        }
    }

    return $return_list;
}


function skeet($statusText, $imagePaths = null)
{
	// Path to the Bash script
	$bashScriptPath = '/path/to/post_to_bsky.sh';

	// Status text
	$statusText = "Here’s my latest status update!";

	// Prepare the command
	$command = escapeshellcmd($bashScriptPath) . ' ' . escapeshellarg($statusText);

	// Append each image path if provided
	foreach ($imagePaths as $imagePath) {
	    $command .= ' ' . escapeshellarg($imagePath);
	}

	// Execute the command and capture the output
	$output = shell_exec($command);

	// Output the result
	echo "$output\n";
}

function xml_get_state($conn, $xml)
{
	preg_match_all('/<txtNameUSState>(.*?)<\/txtNameUSState>/', $xml, $output_array);
	//return substr($output_array[1][0],0,2);

	$state		= $output_array[1][0];

	$stmt		= $conn->prepare("SELECT state_abbr FROM us_states WHERE state_name = ?");
	$stmt->bind_param("s", $state);
	$stmt->execute();
	$row		= $stmt->get_result()->fetch_assoc();
	$stmt->close();
	return $row['state_abbr'] ?? null;

}


function mastodon_post($post_text, $img)
{
	//$media_array is an array of filespec
	///var/www/html/mastodon/23423401-1.png
	///var/www/html/mastodon/23423401-2.png
	///var/www/html/mastodon/23423401-3.png 
	//Currently, it's only working with a single filespec

	$tootoPHP = new TootoPHP\TootoPHP('botsin.space');
	$app = $tootoPHP->registerApp('SpaceTFRs', 'http://deltayeet.net');

//	//Media loop here TODO
///	foreach($img_array as $key => $filespec)
//	{
//		$working_array[]	= $app->createAttachement($filespec);
//		$media_ids[]		= $working_array[$key]['id'];
//	}

	echo "Attach: $img\n";

	$img_id	= $app->createAttachement($img);

	var_dump($img_id);

	//$media_ids	= array(

	echo "Posting...\n";
	$response	= $app->PostStatus($post_text, 'public', [$img_id]);

	//TODO: Discord notification if post failure
	
	var_dump($response);

	return $response['id'];
}

function rll_match($conn, $start_time, $location)
{
	//Attempt to cross reference a launch with one from Rocketlaunch.live

	//Attempt to guess a launch location
//	$sqlstring	= "SELECT * FROM tfrmonitor.upcoming_launches, launch_sites WHERE DATE(convert_tz(STR_TO_DATE('$tfr_from', 'From %M %d, %Y at %H%i UTC'), '+00:00',@@global.time_zone)) = launch_est_date AND '$location' LIKE CONCAT('%', launch_sites.faa_name, '%') AND launch_sites.launch_location_slug = tfrmonitor.upcoming_launches.launch_location_slug";
	$stmt		= $conn->prepare("SELECT * FROM upcoming_launches, launch_sites WHERE DATE(convert_tz(?, '+00:00',@@global.time_zone)) = launch_est_date AND ? LIKE CONCAT('%', launch_sites.faa_name, '%') AND launch_sites.launch_location_slug = upcoming_launches.launch_location_slug");
	$stmt->bind_param("ss", $start_time, $location);
	$stmt->execute();
	$result		= $stmt->get_result();
	echo "RLL match lookup: $start_time / $location\n";
	while ($row	= mysqli_fetch_assoc($result))
	{
		$launch_name	= $row['launch_name'];
		$launch_provider= $row['launch_provider'];
		$launch_slug	= $row['launch_slug'];

		$rll_url	= "https://www.rocketlaunch.live/launch/$launch_slug";


	}
	//echo "RLL: $rll_url\n";

	if(isset($launch_name) && isset($rll_url))
	{
		//return array($launch_name, $rll_url);
		return "\nPossibly $launch_name ($rll_url)";
	} else
	{
		return false;
	}
}

function gpt_query($working_text)
{
	//Accept a request and return the value
	$open_ai_key = bot_cfg('openai', 'api_key'); //getenv('OPENAI_API_KEY');
	$open_ai = new OpenAi($open_ai_key);
	echo "gpt_query: $open_ai_key\n";

	$complete = $open_ai->completion([
	   'model' => 'gpt-3.5-turbo-instruct',
	   'prompt' => "$working_text",
	   'max_tokens' => 300,
	   'frequency_penalty' => 0,
	   'presence_penalty' => 0.6,
	]);

	$working_array	= json_decode($complete, true);

	print_r($working_array);

	return $working_array['choices'][0]['text'];
}

function gpt_forecast_summary($working_text)
{
	$open_ai_key = bot_cfg('openai', 'api_key'); //getenv('OPENAI_API_KEY');
	$open_ai = new OpenAi($open_ai_key);
	echo "Key var: $open_ai_key\n";

	$complete = $open_ai->completion([
	   'model' => 'gpt-3.5-turbo-instruct',
	   'prompt' => "Write a 250 or fewer character summary of the likelihood a rocket launch is attempted based on this forecast and comment on whether it improves or worsens after a 24-hour delay if the change is significant.  It MUST be less than 250 characters. All percentages in this text are probability of the rocket launch NOT being attempted, you must phrase your answer as the percentages being a launch weather criteria violation and you are commenting only on whether they attempt a launch, not guessing whether or not the launch will be successful: '$working_text'\n",
	   'max_tokens' => 1000,
	   'frequency_penalty' => 0,
	   'presence_penalty' => 0.6,
	]);

	$working_array	= json_decode($complete, true);

	print_r($working_array);

	return $working_array['choices'][0]['text'];

}


function distance($lat1, $lon1, $lat2, $lon2, $unit) 
{
  if (($lat1 == $lat2) && ($lon1 == $lon2)) {
    return 0;
  }
  else {
    $theta = $lon1 - $lon2;
    $dist = sin(deg2rad($lat1)) * sin(deg2rad($lat2)) +  cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos(deg2rad($theta));
    $dist = acos($dist);
    $dist = rad2deg($dist);
    $miles = $dist * 60 * 1.1515;
    $unit = strtoupper($unit);

    if ($unit == "K") {
      return ($miles * 1.609344);
    } else if ($unit == "N") {
      return ($miles * 0.8684);
    } else {
      return $miles;
    }
  }
}

function build_map_string($conn, $notam_id)
{
	//Accept a URL for a TFR and extract coordinates from the XML

//	$tfr_url	= str_replace('.html','.xml',$tfr_url);
	echo "Getting XML from $tfr_url...\n";
	$tfr_url_xml = "https://tfr.faa.gov/download/detail_" . str_replace("/", "_", $notam_id) . ".xml";
	$xml	= file_get_contents($tfr_url);

	if(empty($xml))
	{
		echo "Warning: the XML was empty\n";
	}

	$tfr	= new SimpleXMLElement($xml);

	$map_url	= "https://maps.googleapis.com/maps/api/staticmap?size=600x600&maptype=terrain&sensor=false"; //Base of url, add path(s)
	foreach($tfr->xpath('//abdMergedArea') as $mergedarea)
	{
		echo "Area: ".$mergedarea->AbdUid->AseUid->codeType."\n";
		unset($coordinates);
		foreach($mergedarea->Avx as $avx_item)
		{
			$lat	= $avx_item->geoLat;
			$long	= $avx_item->geoLong;
	
			if(str_contains($lat, "N"))
			{
				//Positive #, no other change
				$lat	= str_replace('N','',$lat);
			} else
			{
				$lat	= str_replace('S','',$lat);
				$lat	= $lat * -1;
			}
	
			if(str_contains($long, "E"))
			{
				//Positive #, no other change
				$long	= str_replace('E','',$long);
			} else
			{
				$long	= str_replace('W','',$long);
				$long	= $long * -1;
			}
			echo "Pulled coords from XML: $lat, $long\n";
			$coordinates[]	= "$lat,$long";
	
			//echo $avx_item->geoLat."/".$avx_item->geoLong;
		}
		$zoom_level	= determine_zoom_level($coordinates);
		$map_path[]	= implode("|", $coordinates);

	}

	

	//Make multiple Paths
	foreach($map_path as $path)
	{
		$good_coords	= true;
		$map_url	.= "&path=color%3ared|weight:1|fillcolor%3awhite|$path";
	}

	//Get nearby launchpads and add to map URL as pins
	$markers	= nearby_launchpads($conn, $lat, $long); //Pass the last lat/long from above
	$map_url	.= $markers;
	echo "Markers: $markers\n";


	$map_url	.= "&key=" . bot_cfg('google', 'maps_api_key') . "";


	echo "Generated map string in common.inc: $map_url\n";
	
	if($good_coords == true)
	{
		return $map_url;
	} else
	{
		return false;
	}
}

function experimental_build_map_string($conn, $tfr_url)
{
	//Accept a URL for a TFR and extract coordinates from the XML

	$tfr_url	= str_replace('.html','.xml',$tfr_url);
	echo "Getting XML from $tfr_url...\n";
	$xml	= file_get_contents($tfr_url);

	$tfr	= new SimpleXMLElement($xml);

	$map_url	= "https://maps.googleapis.com/maps/api/staticmap?size=600x600&maptype=terrain&sensor=false"; //Base of url, add path(s)
	foreach($tfr->xpath('//Abd') as $mergedarea)
	{
		//echo "Area: ".$mergedarea->AbdUid->AseUid->codeType."\n";
		unset($coordinates);
		echo "Merged_area_count: ".count($mergedarea->Avx)."\n";
		foreach($mergedarea->Avx as $avx_item)
		{
			$lat	= $avx_item->geoLat;
			$long	= $avx_item->geoLong;
	
			if(str_contains($lat, "N"))
			{
				//Positive #, no other change
				$lat	= str_replace('N','',$lat);
			} else
			{
				$lat	= str_replace('S','',$lat);
				$lat	= $lat * -1;
			}
	
			if(str_contains($long, "E"))
			{
				//Positive #, no other change
				$long	= str_replace('E','',$long);
			} else
			{
				$long	= str_replace('W','',$long);
				$long	= $long * -1;
			}
	
			$coordinates[]	= "$lat,$long";
	
			//echo $avx_item->geoLat."/".$avx_item->geoLong;
		}
		$zoom_level	= determine_zoom_level($coordinates);
		$map_path[]	= implode("|", $coordinates);
	}

	

	//Make multiple Paths
	foreach($map_path as $path)
	{
		$good_coords	= true;
		$map_url	.= "&path=color%3ared|weight:1|fillcolor%3awhite|$path";
	}

	//Get nearby launchpads and add to map URL as pins
	$markers	= nearby_launchpads($conn, $lat, $long); //Pass the last lat/long from above
	$map_url	.= $markers;
	echo "Markers: $markers\n";


	$map_url	.= "&key=" . bot_cfg('google', 'maps_api_key') . "";

	//The spiky airspace that shows up at KSC/CCSFS sometimes seems to use a different shape structure but simple TFRs don't have it.
	//Using the old function to generate map string if there's only a single entry in the aseShapes Avx structure because that means it's 
	//definitly broken

	if(count($mergedarea->Avx) == 1)
	{
		//We can't render a map from this, we depend on a list of coordinates from the FAA
		//We use the original function
		$map_url	= build_map_string($conn, $tfr_url);
	} 


	echo "Generated map string in common.inc: $map_url\n";
	
	if($good_coords == true)
	{
		return $map_url;
	} else
	{
		return false;
	}
}


function determine_zoom_level($coordinates)
{
	$highest_dist	= 0;
	//Receive a string of lat/long and figure out what the maximum distance betwen any two points is
	//Return an appropriate zoom level for Google maps
	foreach ($coordinates as $firstlatlong)
	{
		list($x1, $y1) = explode(",",$firstlatlong);
		foreach($coordinates as $secondlatlong)
		{
			list($x2, $y2) 	= explode(",",$secondlatlong);
			$distance	= distance($x1, $y1, $x2, $y2, "N");
			if($distance 	>= $highest_dist)
			{
				//echo "New max: $distance\n";
				$highest_dist	= $distance;
			} 
		}
	}
	
	echo "Highest distance between two points: $highest_dist\n";

}

function nearby_launchpads($conn, $lat, $long)
{
	//Find local launchsites to add to the map
	$sqlstring	="SELECT * FROM `launch_site_locations` WHERE site_latitude > ($lat-.3) AND site_latitude < ($lat+.3) AND site_longitude > ($long - .3) AND site_longitude < ($long + .3)";
	echo "Marker SQL: $sqlstring\n";
	$result		= mysqli_query($conn, $sqlstring);
	echo "SQL return:".mysqli_error($conn);

	while($row	= mysqli_fetch_assoc($result))
	{
		//Build markers
		$site_name	= $row['site_name'];
		$site_latitude	= $row['site_latitude'];
		$site_longitude	= $row['site_longitude'];
		echo "Site coordinates: $site_latitude/$side_longitude\n";
		$markers	.= "&markers=icon:https://deltayeet.net/airspace_rocket5.png%7Clabel:".urlencode("$site_name|$site_latitude,$site_longitude");
	}	
	echo "Returning marker: $markers\n";
	return($markers);
}

function translate_spacewing_date($date_string)
{
	echo "checking: $date_string\n";
	//14 Feb 2021 / 2310 – 2331L (15 Feb 0410 – 0431Z)
	//14 Feb 2021 / 2310 – 2331L (15 Feb 0410 – 0431Z)
	//17 Feb 2021/ 0045 – 0106L (0545 – 0606Z)
	//28 Feb 2021 / 2027 - 2048L (1/0127 0148Z)

//	if(strpos($date_string,'(') !== false)
//	{
//		$date_string	= strtok($date_string, '(');
//		echo "Trimming date to: $date_string\n";
//	}


	$date_array	= explode(' ', $date_string);
	$day		= str_pad($date_array[0],2,"0", STR_PAD_LEFT);
	$month		= date("m",strtotime($date_array[1]));
	$year		= preg_replace("/\\D/","",$date_array[2]);
	
	echo "$year-$month-$day\n";
	return "$year-$month-$day";

}

function twute($conn, $tweet = '', $mediaArray = null, $parent_tweet)
{
	//Revision: 7/1/2023: Updated for v2.  Uses twute for upload, not acceptable for big media yet

	echo "Tweet function executing: $tweet\n";
	print_r($mediaArray);
	echo "\n";

	//dy_discord("Attempting to tweet with twute code from SpaceTFRs");

	//This authorization model is defunct, now in ~/.twurlrc and specifying user in command

	//New method
	$acct_name	= "SpaceTfrs";

	$media_string	= "";
	$reply_string	= "";

    	if(is_array($mediaArray) && !empty($mediaArray[0])) 
	{
        
        	$mediaIDS = array();
        
        	/*
            	Up to 4 media objects can be uploaded.
           	 https://developer.twitter.com/en/docs/media/upload-media/overview
        	*/
        
 		foreach($mediaArray AS $key => $media_path) 
		{
 			echo "In MediaArray: $media_path is file\n";
			if(substr($media_path, -1) == "/")
			{
				$media_path	= "";
			}

			/* Upload media to twitter API and get media ID back */
			if(strpos($media_path,'mp4'))
			{	
				echo "MP4 logic being used.\n";
//				$mediaOBJ = $connection->upload('media/upload', [
//				'media' => '/home/chairboy/nasaprocurement/rendered_opportunities/'.$media_path,
//				'media_type' => 'video/mp4',
//				'media_category' => 'tweet_video',
//				], true);
			} else
			{
				echo "Catch-all media logic being used for $media_path.\n";
				if(!empty($media_path))
				{
//					$mediaOBJ = $connection->upload('media/upload', [
//					'media' => '/home/chairboy/nasaprocurement/rendered_opportunities/'.$media_path
//					], true);
//					array_push($mediaIDS, $mediaOBJ->media_id_string);	
//					echo "Media String: ".$mediaOBJ->media_id_string."\n";
//					print_r($mediaOBJ);

					//Upload using Twurl
					//twurl -H "upload.twitter.com" -X POST "/1.1/media/upload.json" --file "/home/chairboy/tfrmonitor/rendered_maps/2213.png" --file-field "media"

					$twurl_cmd	= "twurl -H \"upload.twitter.com\" -X POST \"/1.1/media/upload.json\" --file \"$media_path\" --file-field \"media\" -u '$acct_name'";
					echo "Twurl_cmd: $twurl_cmd\n";

					$twurl_resp	= shell_exec($twurl_cmd);
					echo "$twurl_resp\n";
					$media_id = json_decode($twurl_resp)->media_id_string;
					array_push($mediaIDS, $media_id);

					echo "mediaIDS array: "; 
					print_r($mediaIDS);
				}
			}
			/* push uploaded media ID to array */	

		}

		if(!empty($mediaIDS))
		{
			//$media_string	= "-d 'media_ids=" . implode(',', $mediaIDS)."'";
			
				
			// Add double quotes around each element
			$quotedArray = array_map(function($element) 
			{
				return '"' . $element . '"';
		}, $mediaIDS);


	
		//Implode the array with commas
		$imploded_media_ids = implode(',', $quotedArray);

		$media_string	= <<<EOT
, "media": {"media_ids": [$imploded_media_ids]}
EOT;
		} else
		{
			$media_string 	= "";
		}

		echo $media_string."\n";
	}

	if(!empty($parent_tweet))
	{
		//$reply_string	= " -d 'in_reply_to_status_id=$parent_tweet'";

		$reply_string	= <<<EOT
, "reply": { "in_reply_to_tweet_id": "$parent_tweet"}
EOT;

	} else
	{
		$reply_string	= "";
	}

	//Replace ampersand with something ampersandy but less breaky
	//?? is new character
	$tweet = str_replace("&", "??", $tweet);

	dy_discord("SpaceTFRs: $tweet");

//	$post_command = "twurl -X POST -H api.twitter.com '/1.1/statuses/update.json' -d 'status=$tweet' ".$media_string." ".$reply_string." -u '$acct_name'";

	$tweet	= str_replace("\n", "\\n", $tweet);

	//$tweet	= escapeshellcmd($tweet);

	$post_command	= <<<EOT
twurl -X POST -A "Content-type: application/json" -d '{"text": "$tweet" $reply_string $media_string}' "/2/tweets" -u '$acct_name'
EOT;

	echo "Twurl post: $post_command\n";

	$post_output = shell_exec($post_command);

//	echo "Twurl output: \n$post_output\n";

	if(!json_decode($post_output)->data->id)
	{
		dy_discord("TFR:".var_dump($post_output));
	}
	return json_decode($post_output)->data->id;

}


function new_tweet($conn, $str = '', $mediaArray = null, $parent_tweet) 
{
	/*
		Twitter OAuth keys
		Create your App and find your API keys here:
		https://developer.twitter.com/en/apps
	*/

	echo "Tweet function executing: $str $mediaArray[0]\n";
	$consumer_key = bot_cfg('twitter', 'consumer_key');
	$consumer_secret =bot_cfg('twitter', 'consumer_secret');
	$access_token = bot_cfg('twitter', 'access_token');
	$access_token_secret = bot_cfg('twitter', 'access_token_secret');

    
	/* init API */
	$connection = new TwitterOAuth($consumer_key, $consumer_secret, $access_token, $access_token_secret);
    	if(is_array($mediaArray) && !empty($mediaArray[0])) 
	{
        
        	$mediaIDS = array();
        
        	/*
            	Up to 4 media objects can be uploaded.
           	 https://developer.twitter.com/en/docs/media/upload-media/overview
        	*/
        
 		foreach($mediaArray AS $key => $media_path) 
		{
 			echo "In MediaArray: $media_path is file\n";
			/* Upload media to twitter API and get media ID back */
			if(strpos($media_path,'mp4'))
			{	
				echo "MP4 logic being used.\n";
				$mediaOBJ = $connection->upload('media/upload', [
				'media' => '/home/chairboy/tfrmonitor/rendered_maps/'.$media_path,
				'media_type' => 'video/mp4',
				'media_category' => 'tweet_video',
				], true);
			} else
			{
				echo "Catch-all media logic being used.\n";
				$mediaOBJ = $connection->upload('media/upload', [
				'media' => '/home/chairboy/tfrmonitor/rendered_maps/'.$media_path
				], true);
			}
			/* push uploaded media ID to array */	
			array_push($mediaIDS, $mediaOBJ->media_id_string);	
		}
        
		/* create comma delimited list of media ID:s */	
		$mediaIDstr = implode(',', $mediaIDS);	
	}
        
	/* API params */
	$arrayCfg['status'] = $str;
	$arrayCfg['media_ids'] = $mediaIDstr;
	if(isset($parent_tweet))
	{
		$arrayCfg['in_reply_to_status_id'] = $parent_tweet;
	}
    
	/*
		Make POST request to Twitter API
		https://developer.twitter.com/en/docs/tweets/post-and-engage/api-reference/post-statuses-update
	*/
	echo "Tweeting now.\n";
	sleep(1); //Added so videos can attach correctly
	$statuses = $connection->post("statuses/update", $arrayCfg);

	if ($connection->getLastHttpCode() == 200) {
	    // Tweet posted successfully
		echo "Tweet succeeded.\n";
	} else {
	    // Handle error case
		echo "Error during tweet:".$connection->getLastHttpCode()."\n";
		var_dump($statuses);
	}

	//Log it
	$tweet		= mysqli_real_escape_string($conn, $str);

	/* return payload */
	return $statuses;
}

function string_between_two_string($str, $starting_word, $ending_word) 
{ 
    $subtring_start = strpos($str, $starting_word); 
    //Adding the strating index of the strating word to  
    //its length would give its ending index 
    $subtring_start += strlen($starting_word);   
    //Length of our required sub string 
    $size = strpos($str, $ending_word, $subtring_start) - $subtring_start;   
    // Return the substring from the index substring_start of length size  
    return substr($str, $subtring_start, $size);   
} 

function find_parent_tweet($conn, $launch_date, $launch_site)
{
	//Find a likely looking non-canceled TFR with a tfr_start_time the same day as this weather report
	if(!empty($launch_site))
	{
		$sqlstring	= "SELECT twitter_status FROM `tfr_list` WHERE deleted_early = 0 AND DATE_FORMAT(tfr_start_time,'%Y-%m-%d') = '$launch_date' AND UPPER(tweet) LIKE UPPER('%$launch_site%') AND twitter_status IS NOT NULL ORDER BY date_added DESC LIMIT 1";
	} else
	{
		$sqlstring	= "SELECT twitter_status FROM `tfr_list` WHERE deleted_early = 0 AND DATE_FORMAT(tfr_start_time,'%Y-%m-%d') = '$launch_date' AND twitter_status IS NOT NULL ORDER BY date_added DESC LIMIT 1";
	}
	echo $sqlstring."\n";
	$twitter_status	= mysqli_query($conn,$sqlstring)->fetch_object()->twitter_status;

	if(isset($twitter_status))
	{
		return $twitter_status;
	} else
	{
		return false;
	}			

}

function oldpushbullet($msg)
{
	$msg	= str_replace("&", "and",$msg);
	$msg	= escapeshellcmd($msg);
	$__cfg_pushbullet_old_token	= bot_cfg('pushbullet', 'old_token');
	$pushbullet	= <<<EOD
curl --silent -u """{$__cfg_pushbullet_old_token}"":" -d type="note" -d body="$msg" -d title="New Space TFR" 'https://api.pushbullet.com/v2/pushes'
EOD;
	$output		= shell_exec($pushbullet);
	//dyeetnotify($msg);
}

function dy_discord($msg)
{
	pushbullet($msg);
}

function dy_quiet($msg)
{
	//escapeshellarg: messages often contain post text with quotes, apostrophes or $ from FAA/launch data
	exec("php /home/chairboy/dybot/manual_text.php 877218705023574066 ".escapeshellarg($msg));
}


function pushbullet($msg)
{
	exec("php /home/chairboy/dybot/manual_text.php 877218705023574066 ".escapeshellarg("<@433685325840252950> $msg"));
}


function attempt_to_match_launch($conn, $tfr_from, $tfr_to, $location)
{
	//Attempt to cross reference a launch with one from Rocketlaunch.live

//	echo "Debug: TO FROM is $tfr_to $tfr_from Location is $location\n";


	//Attempt to guess a launch location
//	$sqlstring	= "SELECT launch_location_slug FROM launch_sites WHERE '$location' LIKE CONCAT('%', faa_name, '%')";
	

	//Assuming From January 30, 2021 at 1139 UTC
	//convert_tz(STR_TO_DATE('$tfr_from', 'From %M %d, %Y at %H%i UTC'), '+00:00',@@global.time_zone)

//	$sqlstring	= "SELECT * FROM upcoming_launches WHERE DATE(convert_tz(STR_TO_DATE('$tfr_from', 'From %M %d, %Y at %H%i UTC'), '+00:00',@@global.time_zone)) = launch_est_date";
	$sqlstring	= "SELECT * FROM upcoming_launches, launch_sites WHERE DATE(convert_tz(STR_TO_DATE('$tfr_from', 'From %M %d, %Y at %H%i UTC'), '+00:00',@@global.time_zone)) = launch_est_date AND '$location' LIKE CONCAT('%', launch_sites.faa_name, '%') AND launch_sites.launch_location_slug = upcoming_launches.launch_location_slug";
	echo "$sqlstring\n";
	$result		= mysqli_query($conn, $sqlstring);
	while ($row	= mysqli_fetch_assoc($result))
	{
		$launch_name	= $row['launch_name'];
		$launch_provider= $row['launch_provider'];
		$launch_slug	= $row['launch_slug'];

		$rll_url	= "https://www.rocketlaunch.live/launch/$launch_slug";


	}
	//echo "RLL: $rll_url\n";

	if(isset($launch_name) && isset($rll_url))
	{
		return array($launch_name, $rll_url);
	} else
	{
		return false;
	}
}

function location_data($conn, $tfr_detail, $altitude)
{
	echo 		"Debug:\ntfr_detail: $tfr_detail\nAltitude: $altitude\nUppercasing tfr_detail...";
	$tfr_detail_uc	= strtoupper($tfr_detail);

	//Default search.  99% of the time, this is what we will use
	$sqlstring	= "SELECT * FROM tfr_locations WHERE '$tfr_detail_uc' LIKE CONCAT('%',tfr_location_matchstring,'%')";

	if(str_contains($altitude,"10000") && str_contains($tfr_detail_uc, "CANAVERAL"))
	{
		//Ugly hack - On occasion, a capsule lands off Cape Canaveral but short of adding a whole new altitude key,
		//the bot will confuse this for a launch and render the graphic normally
		//I will hack this.
		$sqlstring	= "SELECT * FROM tfr_locations WHERE tfr_location_matchstring = 'CAPSULEHACK'";
	}

	if(str_contains($altitude,"5000") && str_contains($tfr_detail_uc, "CANAVERAL"))
	{
		//Ugly hack - On occasion, a capsule lands off Cape Canaveral but short of adding a whole new altitude key,
		//the bot will confuse this for a launch and render the graphic normally
		//I will hack this.
		$sqlstring	= "SELECT * FROM tfr_locations WHERE tfr_location_matchstring = 'CAPSULEHACK'";
	}



	echo "$sqlstring\n";
	$result		= mysqli_query($conn, $sqlstring);

	while ($row	= mysqli_fetch_assoc($result))
	{
		$location			= $row['tfr_location_title'];
		$notes				= $row['tfr_location_notes'];
		$tfr_location_tweet_string 	= $row['tfr_location_tweet_string'];
		echo "Matched: $location ($notes)\n";
	}
		
	//If there isn't a match, then use the original text?
	if(mysqli_num_rows($result) == 0)
	{
		//Looks like the first two comma sep chunks can be used as location
		$location_working		= explode(",",$tfr_detail);
		$location			= $location_working[0].",".$location_working[1];
//		$location			= $tfr_detail;
//		$working_arr			= explode("\n",$tfr_detail);
//		$tfr_location_tweet_string	= $working_arr[0];
		$tfr_location_tweet_string	= $location;
//		$tfr_location_tweet_string	= $tfr_detail;
		echo "No location matched in database, using $tfr_detail";
	}

	//We have some Brownsville-specific stuff, this should somehow move to data too eventually
	if(str_contains($altitude, '1800 feet') && str_contains($location, 'Brownsville'))
	{
		$notes	= "SpaceX has renewed the standing 1,800ft TFR for static fires & cryotesting"; 
	} 

	if(str_contains($altitude, '7200 feet') && str_contains($location, 'Brownsville'))
	{
		$notes	= "SpaceX has re-filed a 7,200ft TFR, possibly for tests or static fires.  These TFRs are not used for flight."; 
	} 

	if(str_contains($altitude, '5000 feet') && str_contains($location, 'Brownsville'))
	{
		$notes	= "SpaceX has re-filed a 5,000ft TFR, possibly for tests or static fires.  These TFRs are not used for flight."; 
	} 

	if(str_contains($altitude, 'surface to space') && str_contains($location, 'Brownsville'))
	{
		$notes	= "SpaceX high-altitude flight TFR, typically used for Starship test flights to 10km and higher";
	}

	//Return an array
	$return_array['location']			= $location;
	$return_array['notes']				= $notes;
	$return_array['tfr_location_tweet_string']	= $tfr_location_tweet_string;

	echo "Location: $location\n";

	return $return_array;


}

function old_location_data($conn, $tfr_detail, $altitude)
{
		echo "Debug: $tfr_detail and $altitude\n";
		$tfr_detail = strtoupper($tfr_detail);
		//Get some details from the location of a TFR and return friendlier text for map render
		if(strpos($tfr_detail, 'ANAVERAL') !== false)
		{
			$location	= "Cape Canaveral/Kennedy Space Center, FL";
			$notes		= "These TFRs cover launches from both Kennedy Space Center and Cape Canaveral Space Force Station.";
		}

		if((strpos($tfr_detail, 'ANAVERAL') !== false) && strpos($altitude, '10000 feet'))
		{
			$location	= "Offshore Cape Canaveral/Kennedy Space Center, FL";
			$notes		= "Possible capsule landing";
		}

		if((strpos($tfr_detail, 'ANAVERAL') !== false) && strpos($altitude, '5000 feet'))
		{
			$location	= "Offshore Cape Canaveral/Kennedy Space Center, FL";
			$notes		= "Possible capsule landing";
		}



		if(strpos($tfr_detail, 'ROWNSVIL') !== false)
		{
			$brownsville	= "true";
			$location	= "Brownsville,TX\nSpaceX Boca Chica\nStarship Manufacturing Facility"; 

			if(strpos($altitude, '1800 feet') !== false)
			{
				$notes	= "SpaceX has renewed the standing 1,800ft TFR for static fires & cryotesting"; 
			} 

			if(strpos($altitude, '7200 feet') !== false)
			{
				$notes	= "SpaceX has re-filed a 7,200ft TFR, possibly for tests or static fires.  These TFRs are not used for flight."; 
			} 


			if(strpos($altitude, 'surface to space') !== false)
			{
				$notes	= "SpaceX high-altitude flight TFR, typically used for Starship test flights to 10km and higher";
			}

		}
		echo "Debug Brownsville: $notes\n";
		if(strpos($tfr_detail, 'VAN HORN') !== false)
		{
			$location	= "Van Horn, TX\nBlue Origin Corn Ranch spaceport";
			$notes		= "This TFR usually associated with New Shepard flights to 100+km";
		}

		if(strpos($tfr_detail, 'WALLOP') !== false)
		{
			$location	= "Wallops Island, VA\nMid-Atlantic Regional Spaceport (MARS)";
			$notes		= "This facility most commonly launches Northrop-Grumman Antares rockets and also hosts RocketLab's LC-2 for its Electron rocket.";
		}

		if(strpos($tfr_detail, 'TRUTH OR CON') !== false)
		{
			$location	= "Truth or Consequences, NM\nSpaceport America";
			$notes		= "Most commonly Virgin Galactic flights.";
		}

		if(strpos($tfr_detail, 'KODIAK') !== false)
		{
			$location	= "Kodiak, Alaska\nPacific Spaceport Complex";
			$notes		= "This facility hosts both suborbital and orbital launches.  Astra Rocket is most common orbital launch vehicle here currently.";
		}

		if(strpos($tfr_detail, 'AURORA') !== false)
		{
			$location	= "Brothers, OR\nOROC Amateur Rocket Launch";
			$notes		= "Oregon Rocketry (oregonrocketry.com) amateur high-altitude launches";
		}

		if(strpos($tfr_detail, 'DILLON') !== false)
		{
			$location	= "Twin Bridges, MT\nBSRA Amateur Rocket Launch";
			$notes		= "Tripoli Montana (tripolimontana.com) amateur high-altitude launches";
		}

		if(strpos(strtoupper($tfr_detail), 'MELBOURN') !== false)
		{
			$location	= "Florida Space Coast";
			$notes		= "Possible capsule landing";
		}

		if(strpos(strtoupper($tfr_detail), 'JACKSONV') !== false)
		{
			$location	= "Off Jacksonville, FL\nFlorida's Northern Space Coast";
			$notes		= "Possible capsule landing";
		}

		if(strpos(strtoupper($tfr_detail), 'DAYTONA') !== false)
		{
			$display_string	= "Off Daytona Beach, FL\nFlorida Space Coast";
			$notes		= "Possible capsule landing";
		}

		if(strpos(strtoupper($tfr_detail), 'TAMPA') !== false)
		{
			$display_string	= "Off Tampa, FL\nFlorida's Other Space Coast";
			$notes		= "Possible capsule landing";
		}

		if(strpos(strtoupper($tfr_detail), 'ENSACOLA') !== false)
		{
			$display_string	= "Off Pensacola, FL\nFlorida's Other Space Coast";
			$notes		= "Possible capsule landing";
		}

		if(strpos(strtoupper($tfr_detail), 'ANAMA CI') !== false)
		{
			$display_string	= "Off Panama City, FL\nFlorida's Other Space Coast";
			$notes		= "Possible capsule landing";
		}


		if(strpos(strtoupper($tfr_detail), 'BOISE') !== false)
		{
			$display_string	= "Tripoli Idaho's Swan Falls Launch Site for high powered amateur rocketry launches.\nVisit https://www.tripoliidaho.org/ for more information.";
			$notes		= "Tripoli Idaho (https://www.tripoliidaho.org/) amateur high-altitude launches.";
		}

		if(strpos(strtoupper($tfr_detail), 'RAZY WO') !== false)
		{
			$display_string	= "Wyoming High Flyers Launch Site for high powered amateur rocketry launches.\nVisit http://www.whfrocketclub.org/ for more information.";
			$notes		= "Wyoming High Flyers (http://www.whfrocketclub.org/) amateur high-altitude launches.";
		}

		if(strpos(strtoupper($tfr_detail), 'ULKANA') !== false)
		{
			$display_string	= "Alaska NorthStars Launch Site for high powered amateur rocketry launches.\nVisit https://www.nar.org/local_club/alaska-northstars-726/ for more information.";
			$notes		= "Alaska NorthStars (https://www.nar.org/local_club/alaska-northstars-726/) amateur high-altitude launches.";
		}

		if(strpos($tfr_detail, 'FAIRFIELD') !== false)
		{
			$display_string	= "Utah Rocket Club Launch Site for high powered amateur rocketry launches\nVisit http://uroc.org for more information.";
			$notes		= "Amateur high-powered rocketry event at Frank Hunt Field.  http://uroc.org";
		}

		if(strpos($tfr_detail, 'ENTERVILLE') !== false)
		{
			$display_string	= "Gorge Rocket Club Launch Site for high powered amateur rocketry launches\nVisit http://gorgerocketclub.com/ for more information.";
			$notes		= "Amateur high-powered rocketry event at Goldendale site.  http://gorgerocketclub.com/";
		}

	
	//Return an array
	$return_array['location']	= $location;
	$return_array['notes']		= $notes;

	return $return_array;


}


function get_tfr_details($conn, $tfr_url, $top_level_detail)
{
	//Get map coordinates from XML
//	$tfr_details['map_string'] = build_map_string($conn, $tfr_url);
//Commented out map string because it should already exist in database

//	echo "In get_tfr_details, map string is: ".$tfr_details['map_string']."\n";

	$html 	= file_get_contents($tfr_url);
	$dom = new domDocument; 
	$dom->loadHTML($html); 
	$dom->preserveWhiteSpace = false; 
	$tables = $dom->getElementsByTagName('table'); 
	$rows = $tables->item(4)->getElementsByTagName('tr'); 
	foreach ($rows as $row) 
	{
		$cols = $row->getElementsByTagName('td'); 
//		echo "0:".$cols->item(0)->nodeValue."\n";
//		echo "1:".$cols->item(1)->nodeValue."\n";
//		echo "2:".$cols->item(2)->nodeValue."\n";
//		echo "3:".$cols->item(3)->nodeValue."\n";
//		echo "4:".$cols->item(4)->nodeValue."\n";
//		echo "5:".$cols->item(5)->nodeValue."\n";
//		echo "6:".$cols->item(6)->nodeValue."\n";
//		echo "7:".$cols->item(7)->nodeValue."\n";
//		echo "8:".$cols->item(8)->nodeValue."\n";
//		echo "9:".$cols->item(9)->nodeValue."\n";
		unset($working);
		$working	= array();

		//	June 04, 2020 at 2358 UTC
		//Match start and end string
		if(preg_match_all('/From [A-Za-z]+ [0-9]{2}, [0-9]{4} at [0-9]{4} UTC/', $cols->item(2)->nodeValue, $working) >= 1)
		{
			//Matched on a FROM
			$tfr_details['from_date']	= $working[0][0];
			echo "From Date extracted: ".$tfr_details['from_date']."\n";

		}

		//	June 04, 2020 at 2358 UTC
		//Match start and end string
		if(preg_match_all('/To [A-Za-z]+ [0-9]{2}, [0-9]{4} at [0-9]{4} UTC/', $cols->item(2)->nodeValue, $working) >= 1)
		{
			//Matched on a To
			$tfr_details['to_date']	= $working[0][0];
			echo "To Date extracted: ".$tfr_details['to_date']."\n";
		}


		//FDC 0/4915 is format in item(1)
		if(strpos($cols->item(1)->nodeValue,'FDC '))
		{
			echo "FDC condition met\n";
			preg_match_all('/\d+/',$cols->item(1)->nodeValue, $working_array);		
			$tfr_sector	= $working_array[0][0];
			$tfr_id		= $working_array[0][1]; 
			echo		"tfr_id: $tfr_sector/$tfr_id\n";
			//https://tfr.faa.gov/save_maps/sect_0_  .gif
			$tfr_map	= "https://tfr.faa.gov/save_maps/sect_".$tfr_sector."_$tfr_id.gif";

//			echo "TFR Map: $tfr_map\n";
			$file_name = basename($tfr_map);
			$save_file_loc = "/home/chairboy/tfrmonitor/maps/$file_name";
			echo "save_file_loc: $save_file_loc\n";
			$png_name	= basename($save_file_loc,".gif").".png";

			if(file_exists("/home/chairboy/tfrmonitor/maps/".$png_name))
			{
				//No need to re-download it
				echo "PNG found, skipping download.\n";
			} else
			{
				echo "Downloading TFR map [$tfr_map] for records...\n";
				//sleep(45); //Trying to work around bug where it looks like the XML isn't populated right away, 12/31/2022
				$image 		= file_get_contents($tfr_map);
				file_put_contents($save_file_loc, $image);
				exec		("convert $save_file_loc /home/chairboy/tfrmonitor/maps/$png_name 2> /dev/null");
				unlink("$save_file_loc");
				$airspace_render_string	= "php /home/chairboy/tfrmonitor/render_airspace_map.php $tfr_id /home/chairboy/tfrmonitor/maps/$tfr_id.png > /home/chairboy/tfrmonitor/airspace_render.log";
				echo "Rendering: $airspace_render_string\n";
				exec($airspace_render_string);
			}
			
//			$tfr_details['file_path']="/home/chairboy/tfrmonitor/maps/$png_name";  //$save_file_loc;	
			$tfr_details['file_path']="/home/chairboy/tfrmonitor/maps/$tfr_id.png";  //$save_file_loc;	
//			echo "Converted map: ".$tfr_details['file_path']."\n";		
		} else
		{
			echo "The condition for FDC was not met.\n";
		}

		if(str_contains($cols->item(2)->nodeValue,'Altitude:'))
		{
			$tfr_altitude	= trim($cols->item(2)->nodeValue);
			echo "Altitude string: $tfr_altitude\n";
			if(($tfr_altitude <= 1) && isset($cols->item(3)->nodeValue) )
			{
				$tfr_altitude	= trim($cols->item(3)->nodeValue);
			}
			if(strpos($tfr_altitude,'Unlimited'))
			{
				$tfr_details['altitude_string']="Altitude: From the surface to space";
				$tfr_details['possible_flight']	="true";
			} else
			{
				$tfr_details['altitude_string']=$tfr_altitude;
			}
		}

		if(str_contains($cols->item(2)->nodeValue,'Radius:'))
		{
			$tfr_radius	= preg_replace('/[^0-9]/','',trim($cols->item(2)->nodeValue));
			if($tfr_radius <= 1)
			{
				echo "Radius was $tfr_radius, attempting secondary radius.\n";
				$tfr_radius	= preg_replace('/[^0-9]/','',trim($cols->item(3)->nodeValue));
			}
			echo "Secondary Radius string: ".trim($cols->item(3)->nodeValue)."\n";
			$tfr_details['radius_string']=$tfr_radius;

			echo "Radius string: $tfr_radius\n";
		}



		if(str_contains($cols->item(3)->nodeValue,'º'))
		{
			$tfr_details['raw_airspace'][]	= $cols->item(3)->nodeValue;
//			echo "AIRSPCE: Type 3\n";
		}

		if(str_contains($cols->item(4)->nodeValue,'º'))
		{
			$tfr_details['raw_airspace'][]	= $cols->item(4)->nodeValue;
//			echo "AIRSPCE: Type 4\n";

		}


		if(str_contains($cols->item(2)->nodeValue,'º'))
		{
			$tfr_details['raw_airspace'][]	= $cols->item(2)->nodeValue;
//			echo "AIRSPCE: Type 2\n";
		}

		if((strlen($tfr_details['altitude_string']) <= 12) && (str_contains($cols->item(2)->nodeValue,'From the surface')))
		{
			$tfr_details['altitude_string'] = trim($cols->item(2)->nodeValue);
		}

	}


	//8/4/21 - Busted NOTAM, no human readable text
	//Get TFR Coordinates [0-9]{5,8}[NS][0-9]{5,8}[WE]
	if(str_contains($html, "Plain Language text is not available for this NOTAM"))
	{
		preg_match_all('/[0-9]{5,8}[NS][0-9]{5,8}[WE]/', $html, $airspace_working);
		echo "Attempting to read a 'plain language not available' NOTAM\n";
		foreach($airspace_working[0] as $item)
		{
			//561943N1534623W
			if(str_contains($item, "N"))
			{
				//Positive latitude
				$lat_long	= explode("N",$item);
			} else
			{	
				$lat_long	= explode("S",$item);
				$lat_long[0]	= $lat_long[0]*-1;
			}

			if(str_contains($lat_long[1], "E"))
			{
				$lat_long[1]	= str_replace('E','',$lat_long[1]);
			} else
			{
				$lat_long[1]	= str_replace('W','',$lat_long[1]);
				$lat_long[1]	= -1 * $lat_long[1];
			} 
			$lat	= number_format($lat_long[0]*.0001,4);
			$long	= number_format($lat_long[1]*.0001,4);
			echo "Lat/Long: $lat/$long\n";		
			$formatted_airspace_array[]	= "$lat,$long";
		}
		$formatted_airspace	= implode('|',$formatted_airspace_array);
		$tfr_details['formatted_airspace']	= $formatted_airspace;
		echo "Formatted airspace: $formatted_airspace\n";
	}

	if(strpos($tfr_details['altitude_string'], "18,000ft"))
	{
		$tfr_details['possible_flight']	="true";
	}

	if(strpos($html,'SFC-UNL') && empty($tfr_details['altitude_string']))
	{
		echo "Altitude string gathered from SFC-UNL\n";
		$tfr_details['altitude_string']	="Altitude: From the surface to space";
		$tfr_details['possible_flight']	="true";
	}


	//If there is no $tfr_details['from_date'] or ['to_date'] then we use $top_level_detail which was passed
	if(empty($tfr_details['from_date']) && empty($tfr_details['to_date']))
	{
		//Fuck it, we'll do it live
		echo "Unable to match from_date conventionally, using $top_level_detail as source\n";
		//$tfr_details['from_date']	= substr(strstr(substr(strstr($top_level_detail," "), 1)," "), 1);
		echo "TLD debug: $top_level_detail\n";
		preg_match_all("/\w+ \d{1,2}, \d{4}/", $top_level_detail, $matches);
		var_dump($matches);
		$extracted_string = $matches[0][0];

//		$tld_json	= gpt_query("return a JSON containing the two dates in this string: '$top_level_detail' - Return both in the same format as 'May 26, 2023 at 1400 UTC'");
//		var_dump(json_decode($tld_json, true));

		// Format the extracted string for the SQL query
		$tfr_details['from_date'] = addslashes("From ".$matches[0][0]." at 1400 UTC");
		$tfr_details['to_date'] = addslashes("To ".$matches[0][1]." at 2000 UTC");
		echo "End result: ".$tfr_details['from_date']." and ".$tfr_details['to_date']."\n";


	}

	//Hail mary altitude
	if(strlen($tfr_details['altitude_string']) <= 10)
	{
		//We will find a flight-level string and use it instead
		preg_match('/FL\((\d{1,4})\)/',$html, $match_array);
		//This gives us 250 for FL(250)
		$altitude	= number_format($match_array[1] * 100);

		//From the surface up to and including 15075 feet MSL
		preg_match('/including (\d+) feet/',$html, $match_array2);
		if(!empty($match_array2))
		{
			$altitude	= number_format($match_array2[1]);
		}

		echo "Hail mary altitude: $altitude\n";
		$tfr_details['altitude_string']	= "Altitude: From the surface to $altitude feet";
	}



	$tfr_details['altitude_string'] = str_replace('surface  up to', 'surface up to', $tfr_details['altitude_string']);

	$tfr_details['raw_airspace']	= implode(",",massage_raw_airspace($tfr_details['raw_airspace']));

//	if(isset($tfr_details['raw_airspace']) && empty($tfr_details['formatted_airspace']))
//	{
//		//Raw is set but no formatted, I guess why not let's store that too because yolo maybe I should be storing the fixed airspace instead
//		
//	}

	return $tfr_details;
}

function extract_longlat($longlat)
{
	//Extract any lat/long and return it as an array
//	echo "Preg_matching: $longlat\n";
//	echo "DEBUG AIRSPACE: $longlat\n";
	preg_match_all('/[0-9]{1,3}.{1,2}[0-9]{1,3}.[0-9]{1,3}.[NSWE]/', $longlat, $output_array, PREG_PATTERN_ORDER);
//	var_dump($output_array);
	return $output_array;
}

function massage_raw_airspace($airspace)
{

	$return_array	= array();
	//It's an array.  Filter out entries that have to and From in them, link the remaining as a comma separated list of coordinates
	foreach($airspace as $key=>$value)
	{
		if(str_contains($value,"From:") || str_contains($value,"To:"))
		{
			//Remove this entry
//			echo "Matched a to or from on entry: $value\nDeleting.\n";
			unset($airspace[$key]);
		}
		$return_array	= array_merge($return_array,extract_longlat($value));
	}


	//Erase everything that's not a latitude or longitude
	return($return_array[0]);

}

if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle !== '' && mb_strpos($haystack, $needle) !== false;
    }
}

//echo distance(32.9697, -96.80322, 29.46786, -98.53506, "M") . " Miles<br>";
//echo distance(32.9697, -96.80322, 29.46786, -98.53506, "K") . " Kilometers<br>";
//echo distance(32.9697, -96.80322, 29.46786, -98.53506, "N") . " Nautical Miles<br>";

function cloneImg($img){
    //get dimensions
    $w = imagesx($img);
    $h = imagesy($img);
     //copy process
    $copy = imagecreatetruecolor($w, $h);
    imagecopy($copy, $img, 0, 0, 0, 0, $w, $h);

    return $copy;

  }

?>