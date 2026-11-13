<?php
error_reporting(E_ERROR | E_PARSE);
include 'common.inc';
//use DG\Twitter\Twitter;
//require_once 'vendor/dg/twitter-php/src/twitter.class.php';

//Dry run: TFRMONITOR_DRY_RUN=1 php tfrmonitor.php [notam_id ...]
//Processes the given NOTAMs (or any new ones) without saving to the DB, rendering, or posting anywhere.
$dry_run	= getenv('TFRMONITOR_DRY_RUN') === '1';

//Only one run at a time. Cron fires every 3 minutes; a slow run (FAA timeouts, several TFRs) must not overlap
//the next one, or both runs see the same TFRs as new and post them twice.
//flock is released automatically if the process dies.
$lock_file	= __DIR__.'/tfrmonitor.lock';
$lock		= fopen($lock_file, 'c+');
if(!$lock || !flock($lock, LOCK_EX | LOCK_NB))
{
	$held_since	= (int)@file_get_contents($lock_file);
	echo "Another tfrmonitor run holds the lock (since ".($held_since ? date('c', $held_since) : 'unknown')."). Exiting...\n";
	if($held_since && time() - $held_since > 1800)
	{
		tfr_alert_once('lock', "tfrmonitor has been locked by a single run for ".round((time() - $held_since) / 60)." minutes; new TFRs are not being checked. A run may be hung (check ps for tfrmonitor/skeet/toot).");
	}
	exit();
}
ftruncate($lock, 0);
fwrite($lock, (string)time());
fflush($lock);

//Check if I'm supposed to be running
$sqlstring	= "SELECT status_data FROM status WHERE status_item = 'active'";
$active		= mysqli_query($conn,$sqlstring)->fetch_object()->status_data;
if($active == '1')
{
	//No action required, we are on like kong
} else
{
	echo "According to status.status_item.active, I am supposed to be asleep.  Exiting...\n";
	exit();
}



echo "Checking for Space TFRs...\n";

$pushbullet_token	= bot_cfg('pushbullet', 'token');

if($dry_run && count($argv) > 1)
{
	//Dry run against specific NOTAMs, e.g. ones already posted
	$tfr_list	= array();
	foreach(array_slice($argv, 1) as $forced_id)
	{
		$tfr_list[]	= array('notam_id' => $forced_id, 'description' => '(forced by dry run)');
	}
} elseif(!$tfr_list = get_new_tfrs($conn))
{
	echo "No new TFRs.\n";
	exit();
}

if($dry_run)
{
	echo "*** DRY RUN: nothing will be saved, rendered, or posted ***\n";
} else
{
	//Cron overwrites tfrmonitor_check.log every 3 minutes, which loses the output of the runs that
	//actually posted something. Keep a copy of those runs (only those) in tfrmonitor_posts.log.
	$posts_log	= __DIR__.'/tfrmonitor_posts.log';
	file_put_contents($posts_log, "\n=== ".date('Y-m-d H:i:s T')." ".count($tfr_list)." new TFR(s) ===\n", FILE_APPEND);
	ob_start(function($buffer) use ($posts_log)
	{
		file_put_contents($posts_log, $buffer, FILE_APPEND);
		return $buffer; //Still goes to stdout (tfrmonitor_check.log) too
	}, 1);
}

//Each TFR is processed on its own. A failure on one (bad XML, a DB error, an odd character in a name)
//must not stop the others, and must not fail silently: it gets an alert and is retried on the next run.
foreach($tfr_list as $notam)
{
	$notam_id	= $notam['notam_id'];
	$description	= $notam['description'];

	echo "New: $notam_id - $description\n";

	try
	{
		xml_tfr($notam_id);
	} catch(Throwable $e)
	{
		if($dry_run)
		{
			try { $conn->rollback(); } catch(Throwable $ignored) {}
		}
		$error	= get_class($e).": ".$e->getMessage()." (".basename($e->getFile()).":".$e->getLine().")";
		echo "FAILED processing $notam_id: $error\n";
		tfr_alert_once("notam_$notam_id", "TFR $notam_id ($description) failed to process: $error. It will be retried every run; this alert repeats at most every 6 hours.");
	}
}

function tfr_alert_once($key, $msg, $every = 21600)
{
	//Send a Discord alert for $key at most once every $every seconds, so a persistent failure
	//doesn't alert on every 3-minute run
	global $dry_run;

	$state_file	= __DIR__.'/tfr_alerts.json';
	$state		= json_decode((string)@file_get_contents($state_file), true);
	if(!is_array($state))
	{
		$state	= array();
	}

	if(time() - ($state[$key] ?? 0) < $every)
	{
		echo "Alert suppressed (sent recently): $msg\n";
		return;
	}

	if($dry_run)
	{
		echo "DRY RUN: would alert Discord: $msg\n";
		return;
	}

	dy_discord($msg);

	//Forget alerts older than a week so the file doesn't grow forever
	foreach($state as $k => $t)
	{
		if(time() - $t > 604800)
		{
			unset($state[$k]);
		}
	}
	$state[$key]	= time();
	file_put_contents($state_file, json_encode($state));
}

function tfr_already_recorded($conn, $notam_id)
{
	//See tfr_notam_recorded() in common.inc: NOTAM numbers get reused, so this compares against current TFRs only
	return tfr_notam_recorded($conn, $notam_id);
}

function tfr_location_row($conn, $city)
{
	$stmt	= $conn->prepare("SELECT tfr_location_tweet_string, tfr_location_matchstring FROM tfr_locations WHERE ? LIKE CONCAT('%',tfr_location_matchstring,'%')");
	$stmt->bind_param("s", $city);
	$stmt->execute();
	$row	= $stmt->get_result()->fetch_assoc();
	$stmt->close();
	return $row;
}



//Old logic
//foreach ($rows as $row) {
//	$cols = $row->getElementsByTagName('td'); 
//	if(strpos($cols->item(4)->nodeValue,'SPACE'))
//	{
//		$tfr_href	= $cols->item(4)->getElementsByTagName('a');
//		$notam		= str_replace(array("\r", "\n", "\t"), '', trim($cols->item(1)->nodeValue));
//		$notam_list[]	= $notam; //We make an array too to check against later
//		$tfr_url	= str_replace("..","https://tfr.faa.gov",$tfr_href->item(0)->getAttribute('href'));
//		//If the NOTAM is not in the database, we process it
//		$is_processed	= mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM tfr_list WHERE notam = '$notam'")->fetch_object()->cnt;
//		if($is_processed)
//		{
//			//No action required, let's update the last seen
//			$sqlstring	= "UPDATE tfr_list SET last_seen = NOW() WHERE notam = '$notam'";
//			$result		= mysqli_query($conn, $sqlstring);
//			echo "Update: $sqlstring\n";
//		} else
//		{
//			echo "New NOTAM $notam detected\n";
//			//get the XML and populate variables to the database
//			if(str_contains($tfr_url, 'save_pages/detail_'))
//			{
//				//Looks like TFR link
//				xml_tfr($tfr_url);
//			} else
//			{
//				//disregard
//			}
//		}
//	}
//
//}

function xml_tfr($notam_id)
{
	//This function will populate the database with a new TFR or error out to Discord

	global $conn, $dry_run;

	//Re-check right before doing anything: guards against a TFR being recorded between
	//get_new_tfrs() and now (e.g. by a previous run that was still finishing)
	if(tfr_already_recorded($conn, $notam_id))
	{
		if(!$dry_run)
		{
			echo "$notam_id is already in tfr_list, skipping\n";
			return;
		}
		echo "DRY RUN: $notam_id is already in tfr_list (a real run would skip it); continuing anyway\n";
	}

//	$tfr_url_xml	= str_replace('.html','.xml',$tfr_url);
	$tfr_url_xml = "https://tfr.faa.gov/download/detail_" . str_replace("/", "_", $notam_id) . ".xml";
	echo "Getting XML\n";
	$xml = get_faa_content($tfr_url_xml);

	if(strlen($xml) <= 20 || stripos($xml, '<!DOCTYPE') !== false)
	{
		//Not recorded yet, so it is retried next run. Move on to the other TFRs instead of exiting.
		throw new RuntimeException("Could not download TFR XML from $tfr_url_xml (got ".strlen((string)$xml)." bytes)");
	}

	//Sanity check: the NOTAM ID we store must be the feed's ID, because that is what get_new_tfrs() checks
	//against. If the XML disagrees, say so, but keep using the feed's ID.
	$xml_notam	= xml_get_notam($xml);
	if($xml_notam !== $notam_id)
	{
		echo "WARNING: XML says NOTAM $xml_notam but the feed says $notam_id; storing $notam_id\n";
	}

	//Backup the FAA's map
	//download_backup_map($tfr_url);
	
	//Pass the XML to different functions to get the different variables

	//Pass XML to a modified build_map_string
	$tfr_url	= "https://tfr.faa.gov/tfr3/?page=detail_" . str_replace("/", "_", $notam_id);

	$map_url 	= xml_build_map_string($conn, $xml);
	$notam		= $notam_id;
	$start_time	= xml_get_start_time($xml);
	$end_time	= xml_get_end_time($xml);
	//A missing start time would otherwise turn into "now" and be posted as fact: fail this TFR instead
	//(it is alerted and retried). A missing end time is posted as "further notice".
	if(rll_utc_ts($start_time) === null)
	{
		throw new RuntimeException("TFR XML has no usable start time (dateEffective='$start_time')");
	}
	if(rll_utc_ts($end_time) === null)
	{
		echo "WARNING: TFR XML has no usable end time (dateExpire='$end_time'); posting it as 'further notice'\n";
		$end_time	= null;
	}
	$human_start	= tfr_human_time($start_time);
	$human_end	= $end_time === null ? "further notice" : tfr_human_time($end_time);
	$raw_airspace	= xml_get_raw_airspace($xml);
	$city		= xml_get_city($xml);
	$location_title	= xml_tfr_location_title($conn, $xml);
	$faa_title	= xml_tfr_location_faa_title($conn, $xml);
	$state		= xml_get_state($conn, $xml);
	$altitude		= xml_get_altitude($xml);
	$tfr_type		= "SPACE OPERATIONS"; //Hard coded for now 
	$map_string	= xml_build_map_string($conn, $xml);
	$rll_launch	= rll_find_launch($conn, $city, $start_time, $end_time); //Which rocketlaunch.live launch is this TFR probably for?
	$uniquekey	= xml_uniquekey($xml);
	$aux_capsule	= xml_capsule_secondary($xml); //Check for some text in the NOTAM related to spacecraft recovery that sometimes appears

	//Debug
	echo "map_url: $map_url\nnotam: $notam\nStart: $start_time\nEnd: $end_time\nHuman Start: $human_start\nHuman End: $human_end\nCity: $city, $state\nLocation: $location_title\nAlt: $altitude\nMap String: $map_string\n";

	//Make tfr_detail 
	$day_of_tfr	= date_format(date_create("$start_time"),"l, F jS, Y")." UTC";//Human readable string for "CAPE CANAVERAL, FL, Tuesday, January 03, 2023 UTC"
	$tfr_detail	= "$city, $state, $day_of_tfr";
	
	//Build tweet string
	//Example: Cape Canaveral/Kennedy Space Center, FL temporary restriction:
	//From January 03, 2023 at 1426 UTC to To January 03, 2023 at 1520 UTC
	//Altitude: From the surface up to and including 18,000ft
	//https://tfr.faa.gov/save_pages/detail_3_0121.html
	$tweet	= "$location_title temporary restriction:\nFrom $human_start to $human_end\n$altitude\n$tfr_url";
	$tweet	.= rll_tweet_suffix($tweet, $rll_launch); //Fits the launch in without going over Bluesky's 300 characters
	echo "Constructed tweet:\n$tweet\n";

	//Final validation, if we have everything, insert it into the database.  If no, then send an error message to Discord
	echo "Final data:\nMap: $map_url\nStart/end: $start_time/$end_time\nCity: $city\nAltitude: $altitude\nType: $tfr_type\nDetail: $tfr_detail\nTweet: $tweet\n";

	//Insert to Database (prepared statement: names and launch titles can contain quotes)
	//A failure here throws, so nothing is posted for a TFR that wasn't recorded
	if($dry_run)
	{
		$conn->begin_transaction(); //Rolled back below, so the dry run leaves no row behind
	}
	$tfr_map_image	= '';
	$raw_airspace	= (string)$raw_airspace;
	$map_string	= (string)$map_string;
	$stmt		= $conn->prepare("INSERT INTO tfr_list (tfr_unique, notam, tfr_url, tfr_map_image, tfr_type, tfr_start_time, tfr_end_time, tfr_detail, raw_airspace, formatted_airspace, map_string, altitude, date_added, last_seen, deleted_early, tweet) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '', ?, ?, NOW(), NOW(), 0, ?)");
	//A dry run may replay an already-posted NOTAM; give its (rolled back) row a different notam so the
	//unique key on (notam, tfr_start_time) doesn't reject it
	$db_notam	= $dry_run ? "$notam-dry" : $notam;
	$stmt->bind_param("ssssssssssss", $uniquekey, $db_notam, $tfr_url, $tfr_map_image, $tfr_type, $start_time, $end_time, $tfr_detail, $raw_airspace, $map_string, $altitude, $tweet);
	$stmt->execute();

	//Use the ID of the row we just inserted (not "newest row in the table", which can belong to another run)
	$tfr_id		= $conn->insert_id;
	$stmt->close();
	echo "Inserted as tfr_id $tfr_id\n";

	//Render an airspace map for tfr_map_image, it will be /home/chairboy/tfrmonitor/maps/$tfr_id.png
	//Save_path is the path to the file
	$save_path	= $dry_run ? sys_get_temp_dir()."/tfrmonitor_dryrun_$tfr_id.png" : __DIR__."/maps/$tfr_id.png";

	//Check if we can tell whether this is a capsule and add &zoom=8 if yes 
	if(str_contains($tweet, "capsule") || $aux_capsule == true)
	{
		$map_url	= $map_url."&zoom=8";
	}

	//Download the Google map. Only keep it if it really is a PNG: a failed request used to leave an empty
	//file behind, so the "map failure" alert below never fired.
	$map_error	= null;
	if($map_url === false)
	{
		$map_error	= "no airspace coordinates in the TFR XML";
	} else
	{
		//Timeout so a stalled Google request can't hold the run lock indefinitely
		$map	= @file_get_contents($map_url, false, stream_context_create(array('http' => array('timeout' => 30, 'ignore_errors' => true))));
		$status	= $http_response_header[0] ?? 'no response';
		if($map !== false && strncmp($map, "\x89PNG\r\n\x1a\n", 8) === 0)
		{
			file_put_contents($save_path, $map);
		} else
		{
			$map_error	= "$status, ".strlen((string)$map)." bytes: ".substr(trim(strip_tags((string)$map)), 0, 150);
		}
	}

	if($dry_run)
	{
		echo "DRY RUN: ".($map_error ? "map FAILED ($map_error)" : "map saved to $save_path (".filesize($save_path)." bytes)").". Would now render, post to Facebook, Mastodon and Bluesky, and alert Discord with:\n$tweet\n";
		echo "DRY RUN: Mastodon command would be: ".tfr_toot_command($tweet)."\n";
		$conn->rollback();
		echo "DRY RUN: DB insert rolled back\n";
		return;
	}

	if($map_error !== null)
	{
		//Posting continues: render_tfr_image.php draws the card without the map
		dy_discord("Google map failure for TFR $notam (tfr_id $tfr_id): $map_error. Posting without the map.");
		$error_state	= true;
	}

	//Clear the old images with non-unique names as a safety measure in case one of the render scripts fails
	//Better to tweet without image than wrong image
	//temp_bluesky_file.png is the one skeet.sh posts, so it must be cleared too
	@unlink(__DIR__.'/rendered_maps/temp_file.png');
	@unlink(__DIR__.'/rendered_maps/temp_fb_file.png');
	@unlink(__DIR__.'/rendered_maps/temp_mastodon_file.png');
	@unlink(__DIR__.'/rendered_maps/temp_bluesky_file.png');


	//Render TFR image
	$render_string	= "php ".__DIR__."/render_tfr_image.php $tfr_id";
	$output		= shell_exec($render_string);
	
	echo $output."\n";

	//Tweet and whatnot assuming we're still doing it in this script and not in a separate one
	echo "The tweet etc post would be: $tweet\n";

	$parent_status	= ""; //Clear this so it's only used if there's a matching tweet to respond to

	//See if there's another TFR with the same uniquekey, if so let's tweet this as a response to it
	$sqlstring	= "SELECT twitter_status FROM tfr_list WHERE tfr_unique = '$unique_key'";
	$parent_status	= mysqli_query($conn, $sqlstring)->fetch_object()->twitter_status;

	echo "Attempting to tweet (".strlen($tweet).") $tweet\nParent_status: $parent_status\n";
	//$return_val	= twute($conn, $tweet, array("/home/chairboy/tfrmonitor/rendered_maps/temp_file.png"), $parent_status);
			
	//$tweet_id	= $return_val->id;	//We'll track status updates so we can respond to existing threads with weather and stuff
	//$sqlstring	= "UPDATE tfr_list SET twitter_status = '$return_val' WHERE notam = '$notam'";
	//$result		= mysqli_query($conn, $sqlstring);

	echo "Posting to Facebook...\n";
	//Append each attempt (with a timestamp header) so multiple TFRs in one run are all kept
	file_put_contents("/home/chairboy/tfrmonitor/fb_post.log", "=== ".date('Y-m-d H:i:s T')." tfr_id $tfr_id ===\n", FILE_APPEND);
	exec('php /home/chairboy/tfrmonitor/fb_page_post.php '.$tfr_id.' >> /home/chairboy/tfrmonitor/fb_post.log 2>&1', $fb_out, $fb_rc);
	file_put_contents("/home/chairboy/tfrmonitor/fb_post.log", "--- exit code: $fb_rc\n\n", FILE_APPEND);

	echo "Posting to Mastodon...\n";
	//exec('php /home/chairboy/tfrmonitor/mastodon_post.php '.$tfr_id.' > /home/chairboy/tfrmonitor/mastodon_post.log');

	file_put_contents("/home/chairboy/tfrmonitor/mastodon_post.log", "=== ".date('Y-m-d H:i:s T')." tfr_id $tfr_id ===\n", FILE_APPEND);
	$mastodon_command	= tfr_toot_command($tweet)." >> /home/chairboy/tfrmonitor/mastodon_post.log 2>&1";
	exec($mastodon_command, $toot_out, $toot_rc);
	file_put_contents("/home/chairboy/tfrmonitor/mastodon_post.log", "--- exit code: $toot_rc\n\n", FILE_APPEND);

	file_put_contents("/home/chairboy/tfrmonitor/skeet.txt", $tweet); //We will post the text file instead

	echo "Posting to Bluesky...\n";
	//Append like the Facebook and Mastodon logs, so every attempt is kept
	file_put_contents("/home/chairboy/tfrmonitor/bluesky_post.log", "=== ".date('Y-m-d H:i:s T')." tfr_id $tfr_id ===\n", FILE_APPEND);
	$bluesky_command	= "cd /home/chairboy/tfrmonitor/; /home/chairboy/tfrmonitor/skeet.sh >> /home/chairboy/tfrmonitor/bluesky_post.log 2>&1";
	exec($bluesky_command, $bsky_out, $bsky_rc);
	file_put_contents("/home/chairboy/tfrmonitor/bluesky_post.log", "--- exit code: $bsky_rc\n\n", FILE_APPEND);

	//Bsky

// This seems wrong	$bsky_post		= skeet($tweet,array('/home/chairboy/tfrmonitor/rendered_maps/temp_file.png'));

	if (stripos($tweet, 'Boca Chica') !== false) 
	{
        	// If 'Brownsville' is present, tell offnom
		//exec("php /home/chairboy/Nostradambot/manual_text.php 754432168293433354 https://vxtwitter.com/SpaceTFRs/status/$return_val");

	}


	//Rename the PNG so it doesn't get re-tweeted in the future by accident
	rename('/home/chairboy/tfrmonitor/rendered_maps/temp_file.png','/home/chairboy/tfrmonitor/rendered_maps/'.$tfr_id.'.png');
	dy_quiet($tweet); //Send a copy to myself so I can manually tweet it if something breaks
	dy_discord("New TFR Monitor executed: $tweet - https://deltayeet.net/rendered_maps/$tfr_id.png\nTweet: https://twitter.com/spacetfrs/status/$return_val");



}

function tfr_toot_command($tweet)
{
	//escapeshellarg: post text can contain apostrophes, quotes, or $ (it comes from FAA and launch-name data)
	//temp_mastodon_file.png carries the Mastodon logo (temp_file.png is the Twitter-branded one)
	return "/home/chairboy/tfrmonitor/toot.sh ".escapeshellarg($tweet)." /home/chairboy/tfrmonitor/rendered_maps/temp_mastodon_file.png";
}

function tfr_human_time($utc)
{
	//"2026-11-14T22:30:00" -> "Saturday, November 14th, 2026 at 22:30 UTC"
	return date_format(date_create("$utc"), "l, F jS, Y \a\\t H:i")." UTC";
}

function xml_capsule_secondary($xml)
{
	if(str_contains($xml, "RECOVERY OF THE SPACE VEHICLE"))
	{
		return true;
	} else
	{
		return false;
	}

}

function xml_build_map_string($conn, $xml)
{
	//Extract coordinates from the XML

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


	echo "Generated xml map stringc: $map_url\n";
	
	if($good_coords == true)
	{
		return $map_url;
	} else
	{
		return false;
	}
}


function download_backup_map($tfr_url)
{
	//Extract the map URL from the URL to the TFR 
	preg_match_all('/sect_print_([0-9]{1,2}_[0-9]{4})/', $tfr_url, $output_array);
	$faa_map	= "https://tfr.faa.gov/save_maps/sect_".$output_array[1][0].".gif";
	$file_name	= "/home/chairboy/tfrmonitor/maps/".basename($faa_map);
	$png_name	= basename($file_name,".gif").".png";
	echo "Generated FAA map: $faa_map, saving to $file_name\n";
	$image = get_faa_content($faa_map);
	file_put_contents($file_name, $image);

	exec		("convert $file_name /home/chairboy/tfrmonitor/maps/$png_name 2> /dev/null");
	unlink("$file_name");
	echo "Backup map converted to $png_name\n";
}

function xml_get_altitude($xml)
{
	//Return a formatted altitude string like "Altitude: From the surface to space"
	$floor_alt	= xml_get_floor_alt($xml);
	$ceiling_alt	= xml_get_ceiling_alt($xml);

	$altitude	= "Altitude: From $floor_alt up to $ceiling_alt";
	return $altitude;
}

function xml_get_floor_alt($xml)
{
	preg_match_all('/<valDistVerLower>(.*?)<\/valDistVerLower>/', $xml, $output_array);
	$alt	= trim((string)($output_array[1][0] ?? '')); //Get raw number
	preg_match_all('/<uomDistVerLower>(.*?)<\/uomDistVerLower>/', $xml, $output_array);
	$uom	= trim((string)($output_array[1][0] ?? '')); //Get unit of measurement

	//Not a number (missing, "SFC", "GND"...): number_format() would throw and stop the whole TFR
	if(!is_numeric($alt))
	{
		return ($alt === '' || in_array(strtoupper($alt), array('SFC', 'GND', 'SURFACE'))) ? "the surface" : $alt;
	}
	$alt	= (float)$alt;

	if($uom == "FL")
	{
		$alt	= $alt * 100;
	}

	if($alt <= 0)
	{
		$alt	= "the surface";
	} else
	{
		$alt	= number_format($alt)."ft";
	}

	return $alt;

}

function xml_get_ceiling_alt($xml)
{
	preg_match_all('/<valDistVerUpper>(.*?)<\/valDistVerUpper>/', $xml, $output_array);
	$alt	= trim((string)($output_array[1][0] ?? '')); //Get raw number
	preg_match_all('/<uomDistVerUpper>(.*?)<\/uomDistVerUpper>/', $xml, $output_array);
	$uom	= trim((string)($output_array[1][0] ?? '')); //Get unit of measurement

	//Not a number: unlimited counts as space; anything else is passed through rather than throwing
	if(!is_numeric($alt))
	{
		if(in_array(strtoupper($alt), array('UNL', 'UNLTD', 'UNLIMITED')))
		{
			return "space";
		}
		return $alt === '' ? "an unspecified altitude" : "and including $alt";
	}
	$alt	= (float)$alt;

	if($uom == "FL")
	{
		$alt	= $alt * 100;
	}

	//"Space" is deliberate for FL180: at and above 18,000ft (Class A) all flight is under ATC control, so a
	//TFR from the surface to FL180 closes everything a TFR can close. 91000 and 99900 are unlimited.
	if($alt == 18000 || $alt == 91000 || $alt == 99900)
	{
		$alt	= "space";
	} else
	{
		$alt	= "and including ".number_format($alt)."ft";
	}


	return $alt;

}


function xml_uniquekey($xml)
{
	//Generate the value we use to recognize like TFRs for chaining doubletons like CCSFB and KSC do
	//Something like 05282023PanamaCityFLTuesdayMay302023
	preg_match_all('/<dateIssued>(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})<\/dateIssued>/', $xml, $matches);

	$issue_year 	= $matches[1][0];
	$issue_month 	= $matches[2][0];
	$issue_day 	= $matches[3][0];
	$issue_hour 	= $matches[4][0];
	$issue_minute 	= $matches[5][0];

	preg_match_all('/<txtNameCity>(.*)<\/txtNameCity>/', $xml, $output_array);
	$city	= str_replace(' ', '',$output_array[1][0]);

	preg_match_all('/<txtNameUSState>(.*)<\/txtNameUSState>/', $xml, $output_array);
	$state	= $output_array[1][0];

	preg_match_all('/<dateEffective>(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})<\/dateEffective>/', $xml, $matches);

	$effective_year 	= $matches[1][0];
	$effective_month 	= $matches[2][0];
	$effective_day 		= $matches[3][0];
	$effective_hour 	= $matches[4][0];
	$effective_minute 	= $matches[5][0];

	$uniquekey	= "$issue_month$issue_day$issue_year$city$state$effective_month$effective_day$effective_year$effective_hour";
	echo "UniqueKey: $uniquekey\n";

	return $uniquekey;

}


function xml_get_notam($xml)
{
//	preg_match_all('/<txtLocalName>(.*?)<\/txtLocalName>/', $xml, $output_array);
//	The above works most of the time, but sometimnes the localname is busted
//	Use this instead
//<dateIndexYear>2013</dateIndexYear>
//<noSeqNo>4341</noSeqNo>

	preg_match_all('/<dateIndexYear>(.*?)<\/dateIndexYear>/', $xml, $output_array);
	$index	= substr($output_array[1][0],-1);
	preg_match_all('/<noSeqNo>(.*?)<\/noSeqNo>/', $xml, $output_array);
	$sequence= $output_array[1][0];
	$notam	= "$index/$sequence";

	echo "Notam: $notam\n";

	return $index."/".$sequence;
}



function xml_get_city($xml)
{
	preg_match_all('/<txtNameCity>(.*?)<\/txtNameCity>/', $xml, $output_array);
	return $output_array[1][0];
}

function xml_get_start_time($xml)
{
	preg_match_all('/<dateEffective>(.*?)<\/dateEffective>/', $xml, $output_array);
	return $output_array[1][0];
}

function xml_get_end_time($xml)
{
	preg_match_all('/<dateExpire>(.*?)<\/dateExpire>/', $xml, $output_array);
	return $output_array[1][0];
}

function xml_get_raw_airspace($xml)
{
	//Steal the airspace loop from the map string code and use it for raw airspace
	echo "TODO: raw airspace code needed\n";
}


function xml_tfr_location_title($conn, $xml)
{
	//Look up the location title from the city name and generate a unique one if it's new, also send Discord alert
	preg_match_all('/<txtNameCity>(.*?)<\/txtNameCity>/', $xml, $output_array);
	$city	= (string)$output_array[1][0];

	$location_title	= tfr_location_row($conn, $city)['tfr_location_tweet_string'] ?? null;

	if(!isset($location_title))
	{
		//New launch location
		$location_title	= ucwords(strtolower(xml_get_city($xml))).", ".xml_get_state($conn, $xml);
	}

	return $location_title;
	
}

function xml_tfr_location_faa_title($conn, $xml)
{
	//Look up the location title from the city name and generate a unique one if it's new, also send Discord alert
	preg_match_all('/<txtNameCity>(.*?)<\/txtNameCity>/', $xml, $output_array);
	$city	= (string)$output_array[1][0];

	$faa_title	= tfr_location_row($conn, $city)['tfr_location_matchstring'] ?? null;

	if(!isset($faa_title))
	{
		//New launch location
		$faa_title	= ucwords(strtolower(xml_get_city($xml))).", ".xml_get_state($conn, $xml);
	}
	echo "Returning FAA title: $faa_title\n";
	return $faa_title;
	
}

?>

