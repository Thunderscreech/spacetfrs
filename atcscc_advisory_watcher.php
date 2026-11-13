<?php
//Monitor https://www.fly.faa.gov/adv/advADB.jsp for changes and alert to Starship-related news
//ATCSCC ADVZY 038 DCC 05/04/2021 OPERATIONS PLAN
//ZHU - SPACE X STARSHIP ROCKET LAUNCH 1200Z-0100Z

include 'common.inc';

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

$html 	= html_entity_decode(file_get_contents("https://www.fly.faa.gov/adv/adv_spt.jsp"));
//$html	= html_entity_decode(file_get_contents("atcscc_test2.dat")); //Hardcoded test
//$html	= html_entity_decode(file_get_contents("atcscc_test3.dat")); //Hardcoded test broken
//$html	= html_entity_decode(file_get_contents("https://www.fly.faa.gov/adv/adv_otherdis.jsp?advn=6&adv_date=05182021&facId=DCC&title=OPERATIONS+PLAN&titleDate=05/18/21"));

file_put_contents('/home/chairboy/tfrmonitor/atcscc_recent.dat', $html);

//ATCSCC ADVZY 038 DCC 05/04/2021 OPERATIONS PLAN

if(preg_match('/ADVZY..[0-9]{3}..DCC..[0-9]{2}\/[0-9]{2}\/[0-9]{4}/', $html, $matches))
{
	$unique		= $matches[0];
	$daystring	= substr($unique, 17, 10);
	//05/05/2021
	$date_fix	= explode('/',$daystring);
	$date_search	= $date_fix[2].'-'.$date_fix[0].'-'.$date_fix[1]; //This turns it into 2021-05-05 for find_parent_tweet
	echo "Date search: $date_search\n";
	$sqlstring	= "SELECT * FROM atcscc WHERE atcscc_unique = '$unique'";
	echo "$sqlstring\n";
	$result		= mysqli_query($conn, $sqlstring);
	if(mysqli_num_rows($result) >= 1)
	{	
		//This is already in the system			
		echo "No action required, this is already in the system.\n";
	} else
	{
		//Break the string into an array by newline and extract any starship references
		echo "New ATCSCC advisory found.\n";
		$parse_array	= explode(PHP_EOL, $html);
//		var_dump($parse_array);
		$done_processing = false;
		foreach($parse_array as $key => $item)
		{
			echo "$item\n";
			//&& str_contains($item,'EN ROUTE CONSTRAINTS:') Was in next line, removing temporarily to see what chaos occurs
			if((str_contains($item, 'ROCKET') || str_contains($item, 'SPACEX') ||str_contains($item, ' LAUNCH'))  && !$done_processing)
			{
				//$done_processing	= true; //We don't want to pick up garbage from down later in the advisory
				//$text_to_render	= $parse_array[$key-1]."\n".$parse_array[$key]."\n".$parse_array[$key+1];
				$text_to_render	= $parse_array[$key-3]."\n".$parse_array[$key-2]."\n".$parse_array[$key-1]."\n".$parse_array[$key]."\n".$parse_array[$key+1]."\n".$parse_array[$key+2]."\n".$parse_array[$key+3];
				echo $item."\n";
				echo "Text to render:\n$text_to_render\n";

				if(str_contains($item,'ZHU'))
				{
					$launch_site	= 'BROWNSVILLE';
				}

				if(str_contains($item,'ZJX'))
				{
					$launch_site	= 'CANAVERAL';
				}

				if(str_contains($item,'ZDC'))
				{
					$launch_site	= 'WALLOP';
				}

				if(str_contains($item,'ZAB'))
				{
					$launch_site	= 'VAN HORN';
				}

				echo "Detected launch site: $launch_site\n";

				//SELECT * FROM `tfr_list` WHERE tfr_detail like '%CANAVERAL%' AND tfr_start_time > NOW() and tfr_start_time < date_add(NOW(), interval 1 day) ORDER BY tfr_id ASC LIMIT 1
				$sqlstring	= "SELECT * FROM `tfr_list` WHERE tfr_detail like '%$launch_site%' AND tfr_start_time > NOW() and tfr_start_time < date_add(NOW(), interval 1 day) ORDER BY tfr_id ASC LIMIT 1";
				$tweet_status	= mysqli_query($conn, $sqlstring)->fetch_object()->twitter_status;
				
				if(isset($tweet_status))
				{
					echo "Reply target found for ATCSCC notification if needed.\n";
					dy_discord("I have an ATCSCC advisory that I think should be a reply to https://twitter.com/spacetfrs/status/$tweet_status");
				}				


				//Render the image for tweeting
				$png_save_path	= "/home/chairboy/tfrmonitor/rendered_maps/";
				$font_path	= "/home/chairboy/tfrmonitor/fonts/";
				$preview_path	= "/var/www/html/rendered_maps/latest_atcscc.png";
				$template_path	= $png_save_path."ATCSCC_template.png";
				$logo_twitter	= $png_save_path."SpaceTFRs_twitter_Logo.png";
				$logo_facebook	= $png_save_path."SpaceTFRs_facebook_Logo.png";
				$rendered_oplan	= preg_replace("/[^a-z0-9.]+/i", "", $unique.".png");
				$save_file_path	= $png_save_path.$rendered_oplan;

				$im		= imagecreatetruecolor(1132, 284);
				$final_image	= imagecreatetruecolor(1132,284);
				$image_width	= imagesx($im); //Keeping this dynamic for future resize
				$image_height	= imagesy($im);
				$black 		= imagecolorallocate($im, 0, 0, 0);
				$white 		= imagecolorallocate($im, 255, 255, 255);
				$transparent 	= imagecolorallocatealpha($im, 255, 255, 255, 127);
				$font		= $font_path."courbd.ttf";

				imagealphablending	($im, false);
				imagesavealpha		($im, true);
				imagefilledrectangle($im, 0, 0, $image_width, $image_height, $transparent);

				imagettftext($im, 22, 0, 63, 54, $black, $font, $text_to_render);

				$template_image	= imagecreatefrompng($template_path);
				imagecopy	($template_image, $im, 0,0,0,0,$image_width, $image_height);
				imagepng	($template_image, $save_file_path);
				imagepng	($template_image, $preview_path);			


				if(preg_match_all('/[0-9]{4}/', $item, $zulu_match))
				{
					$start_time	= $zulu_match[0][0];
					$end_time	= $zulu_match[0][1];
					echo "Start: $start_time End: $end_time\n";
				}
				if(isset($start_time, $end_time))
				{
					$tweet_text	= "The FAA has just issued a notification to airlines advising them of a possible rocket launch between $start_time and $end_time UTC";
				} else
				{
					$tweet_text	= "The FAA has just issued the following En Route advisory to airlines:\n$item";
				}
	
				echo $tweet_text."\n";

				$item	= mysqli_real_escape_string($conn, $item);

				//Decide on whether to act on this based on if we've gotten an en-route notification within 24 hours so we avoid being spammy
				$sqlstring	= "SELECT * FROM `atcscc` WHERE atcscc_unique = '$unique' OR (atcscc_string = '$item' AND atcscc_date >= NOW() - INTERVAL 1 DAY)";
";// atcscc_date >= NOW() - INTERVAL 1 DAY";
				echo "$sqlstring\n";
				$result		= mysqli_query($conn, $sqlstring);
				if(mysqli_num_rows($result) >= 1)
				{	
					//We've already done something with this, no action required but we probably should log the string so we don't generate an alert
					$sqlstring	= "INSERT INTO atcscc (atcscc_unique,atcscc_string, atcscc_tweet) VALUES ('$unique','$item','No tweet suggested')";
					echo "Attempted insert: $sqlstring\n";
					$result		= mysqli_query($conn, $sqlstring);

					echo "No action required.\n";
				} else
				{
					//Let's do it!
					//TODO: Find the parent for the correct TFR and reply to it
					echo "Parent tweet search for $date_search and BROWNS\n";

					if($parent_tweet = find_parent_tweet($conn, $date_search, $launch_site))
					{
						//tweet at parent_tweet
						echo "$parent_tweet was found for this.\n";
						//$return_val	= new_tweet($conn, $tweet, array("$rendered_oplan"),$parent_tweet);
					}

					$tweet_text	= mysqli_real_escape_string($conn, $tweet_text);
					
					$sqlstring	= "INSERT INTO atcscc (atcscc_unique,atcscc_string, atcscc_tweet) VALUES ('$unique','$item','$tweet_text')";
					echo "Attempted insert: $sqlstring\n";
					$result		= mysqli_query($conn, $sqlstring);
	
					$sqlstring	= "SELECT atcscc_id FROM atcscc ORDER BY atcscc_id DESC LIMIT 1";
					$atcscc_id	= mysqli_query($conn, $sqlstring)->fetch_object()->atcscc_id;
	
					pushbullet("I have an ATCSCC advisory that I think should be a reply to https://twitter.com/spacetfrs/status/$tweet_status of $tweet_text.  Shall I? https://deltayeet.net/rendered_maps/latest_atcscc.png");

					//pushbullet("I want to tweet this ATCSCC en-route advisory");
					//INSERT a delete request into the deltaYeet DB
					$sqlstring	= "INSERT into dybot.command_requests (command_description, command_text) VALUES ('Request to tweet ATCSCC $atcscc_id', 'cd /home/chairboy/tfrmonitor/;php /home/chairboy/tfrmonitor/atcscc_tweet.php $atcscc_id $tweet_status')";
					$result		= mysqli_query($conn, $sqlstring);	

					
				}
			
			}

			if(str_contains($item, 'FLIGHT CHE') || str_contains($item, 'VIP'))
			{
				$done_processing = true;
			}

		}
	}

} else
{
	//Error condition
	echo "Not an operations plan.\n";
}

//Update last run
$sqlstring	= "update status set status_data = now() where status_item = 'atcscc_watcher_lastrun'";
$result		= mysqli_query($conn,$sqlstring);// or die "Error running query: $sqlstring".mysqli_error($conn);


?>