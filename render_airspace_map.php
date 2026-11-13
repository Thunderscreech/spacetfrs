<?php

error_reporting(E_ERROR | E_PARSE);

include 'common.inc';
echo "Start\n";
$png_save_path	= "/home/chairboy/tfrmonitor/rendered_maps/";
$font_path	= "/home/chairboy/tfrmonitor/fonts/";

$template_path	= $png_save_path."tfr_template_paper.png";

$tfr_id		= $argv[1];
$save_path	= $argv[2];

if(empty($tfr_id) || empty($save_path))
{
	dy_discord("Airspace render called with empty $tfr_id or $save_path");
	exit();
}

echo "Beginning map render execution for $tfr_id using $template_path saving to $save_path...\n";

$sqlstring	= "SELECT tfr_url, raw_airspace, formatted_airspace, radius, map_string, tweet FROM tfr_list WHERE notam like '%$tfr_id' ORDER BY tfr_id DESC LIMIT 1";
echo "$sqlstring\n";
$result		= mysqli_query($conn, $sqlstring) or die ('Unable to execute query. '. mysqli_error($conn));
echo "Rows returned: ".mysqli_num_rows($result)."\n";
while ($row	= mysqli_fetch_assoc($result))
{
	$tfr_url		= $row['tfr_url'];
	$raw_airspace		= $row['raw_airspace'];
	$formatted_airspace	= $row['formatted_airspace'];
	$radius			= $row['radius'];
	$map_string		= $row['map_string'];
	$tweet			= $row['tweet'];
	echo "Map string: $map_string\n";
}

if(empty($tfr_url))
{
	dy_discord("Aborting the posting of tfr $tfr_id because there were no rows returned when I launched airspace render");
	echo "Debug:\n tfrurl: $tfr_url\nRaw: $raw_airspace\nFormat:$formatted_airspace\nMap string: $map_string\nTweet: $tweet\n";
	unlink($png_save_path."temp_file.png");
	unlink($png_save_path."temp_mastodon_file.png");
	unlink($png_save_path."temp_fb_file.png");
	exit();
}

echo "Raw: $raw_airspace\nFormatted: $formatted_airspace\nRadius: $radius\nMap string: $map_string\n";

//If airspace is totally null, we use the FCC map
if(empty($raw_airspace) && empty($formatted_airspace) && empty($map_string))
{
	$rename_string	="/home/chairboy/tfrmonitor/maps/sect_1_$tfr_id.png";
	echo "Renaming: $rename_string to $save_path\n";
	$rename_string	="/home/chairboy/tfrmonitor/maps/sect_2_$tfr_id.png";
	echo "Renaming: $rename_string to $save_path\n";
	$rename_string	="/home/chairboy/tfrmonitor/maps/sect_3_$tfr_id.png";
	echo "Renaming: $rename_string to $save_path\n";

	rename($rename_string ,$save_path);
	//pushbullet('Airspace failure detected on map render.  Using failsafe map. Raw:'.$raw_airspace);
	echo "Airspace failure, using fallback failsafe map.\n";
}

//Sanity check on radius
if((substr_count($raw_airspace, ',') <= 1) && (substr_count($formatted_airspace, ',') <= 1)  && $radius <= 1)
{
	echo "Comma count: ".substr_count($raw_airspace, ',')."\n";
	$radius	= 2; //Just in case
}


//All of the above was really just a backup.  We will attempt to use the map string that was built already
//because it should be a lot better.
echo "Map string from database: $map_string\n";
if(!empty($map_string))
{
	$map_url	= $map_string; //We swap out the map_url with the one in the database
	echo "Using map_string from database.\n";
} else
{
	//Calculate it ourselves
	echo "No map string, attempting to calculate it ourselves.\n";
	$map_url	= build_map_string($conn, $tfr_url);
}

echo "Using map URL: $map_url\n";

//Check if we can tell whether this is a capsule and add &zoom=8 if yes 
if(str_contains($tweet, "capsule"))
{
	$map_url	= $map_url."&zoom=8";
}

if(isset($save_path))
{
	$map	= file_get_contents($map_url);
	file_put_contents($save_path, $map);
}

function get_circle_map($conn, $raw_airspace, $radius)
{
	//Draw a circle on a map vs polygon

	//Find local launchsites to add to the map
	$starting_point	= explode(',',pair_out($raw_airspace)[0]); //Get one of the locations in TFR and search for nearby launch sites
	//$sqlstring	="SELECT * FROM `launch_site_locations` WHERE site_latitude > ($starting_point[0]-.3) AND site_latitude < ($starting_point[0]+.3) AND site_longitude > ($starting_point[1] - .3) AND site_longitude < ($starting_point[1] + .3)";
	echo $sqlstring."\n";
	$result		= mysqli_query($conn, $sqlstring);
	while($row	= mysqli_fetch_assoc($result))
	{
		//Build markers
		$site_name	= $row['site_name'];
		$site_latitude	= $row['site_latitude'];
		$site_longitude	= $row['site_longitude'];
		$markers	.= "&markers=icon:https://deltayeet.net/airspace_rocket5.png%7Clabel:".urlencode("$site_name|$site_latitude,$site_longitude");
	}


	list($lat, $long) = explode(',',pair_out($raw_airspace)[0]);

	$result		= convert([$lat,$long], $radius, 30);
	foreach ($result['coordinates'][0] as $v) 
	{
		$working_airspace[]	= $v[1].",".$v[0];
//		echo "$v[1]\t$v[0]\n";
	}
	$airspace	= implode('|',$working_airspace);
	$google_maps_url	= "https://maps.googleapis.com/maps/api/staticmap?size=600x600&maptype=terrain$markers&sensor=false&path=color%3a0X8B0000|weight:1|fillcolor%3ared|$airspace&key=" . bot_cfg('google', 'maps_api_key') . "";
	return $google_maps_url;
}

function get_poly_map($conn, $raw_airspace, $formatted_airspace)
{
	//Build the polygon coordinates for our enclosed airspace

	//Some airspace is defined in a funky fashion by the TFR system re: lat/long, use some hardcoded alternatives for those
	//KSC is the only known one at this time, please let it stay that way.

	$sqlstring		= "SELECT replacement_string FROM corrected_airspace WHERE match_string = '".mysqli_real_escape_string($conn, $raw_airspace)."'";
	$corrected_airspace	= mysqli_query($conn, $sqlstring)->fetch_object()->replacement_string;

	echo "Corrected: $corrected_airspace\n";

	if(isset($corrected_airspace))
	{
		echo "Using corrected airspace\n";
		$airspace	= $corrected_airspace;
		$starting_point	= explode(',',pair_out($raw_airspace)[0]); //Get one of the locations in TFR and search for nearby launch sites
	} else
	{
		$airspace	= implode('|',pair_out($raw_airspace));
		$starting_point	= explode(',',pair_out($raw_airspace)[0]); //Get one of the locations in TFR and search for nearby launch sites
	}

	if(isset($formatted_airspace))
	{
		//Well let's just use the clean stuff instead because it's ready to go
		$airspace	= $formatted_airspace;
		$starting_point	=  explode('|',$airspace)[0];
	}

	print_r($starting_point);

	//Find local launchsites to add to the map
	$starting_point	= explode(',',pair_out($raw_airspace)[0]); //Get one of the locations in TFR and search for nearby launch sites
	$sqlstring	="SELECT * FROM `launch_site_locations` WHERE site_latitude > ($starting_point[0]-.3) AND site_latitude < ($starting_point[0]+.3) AND site_longitude > ($starting_point[1] - .3) AND site_longitude < ($starting_point[1] + .3)";
	echo "$sqlstring\n";
	$result		= mysqli_query($conn, $sqlstring);
	while($row	= mysqli_fetch_assoc($result))
	{
		//Build markers
		$site_name	= $row['site_name'];
		$site_latitude	= $row['site_latitude'];
		$site_longitude	= $row['site_longitude'];
		$markers	.= "&markers=icon:https://deltayeet.net/airspace_rocket5.png%7Clabel:".urlencode("$site_name|$site_latitude,$site_longitude");
	}
	$google_maps_url	= "https://maps.googleapis.com/maps/api/staticmap?size=600x600&maptype=terrain$markers&sensor=false&path=color%3a0X8B0000|weight:1|fillcolor%3ared|$airspace&key=" . bot_cfg('google', 'maps_api_key') . "";
	return $google_maps_url;
}

function pair_out($input_string)
{
	$working_array	= explode(",",$input_string);
	foreach($working_array as $key => $value)
	{
		$value	= translate_coordinate($value);
		if($key % 2 == 0)
		{
			//It's even
			$working_string = "$value,";
		} else
		{
			$working_string .= "$value";
			$output_array[]	= $working_string;
		}

	}
	return $output_array;
}

function translate_coordinate($coordinate)
{
	//Split up the lat/long example: 37º56'45"N
	//And process it to send off to DMS2Decimal
	preg_match_all('/([0-9]{1,3})º([0-9]{1,3}).([0-9]{1,3}).([NWSE])/', $coordinate, $output_array);
	
	return DMS2Decimal($output_array[1][0],$output_array[2][0],$output_array[3][0],$output_array[4][0]);
}

function DMS2Decimal($degrees = 0, $minutes = 0, $seconds = 0, $direction = 'n') {
   //converts DMS coordinates to decimal
   //returns false on bad inputs, decimal on success
    
   //direction must be n, s, e or w, case-insensitive
   $d = strtolower($direction);
   $ok = array('n', 's', 'e', 'w');
    
   //degrees must be integer between 0 and 180
   if(!is_numeric($degrees) || $degrees < 0 || $degrees > 180) {
      $decimal = false;
   }
   //minutes must be integer or float between 0 and 59
   elseif(!is_numeric($minutes) || $minutes < 0 || $minutes > 59) {
      $decimal = false;
   }
   //seconds must be integer or float between 0 and 59
   elseif(!is_numeric($seconds) || $seconds < 0 || $seconds > 59) {
      $decimal = false;
   }
   elseif(!in_array($d, $ok)) {
      $decimal = false;
   }
   else {
      //inputs clean, calculate
      $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);
       
      //reverse for south or west coordinates; north is assumed
      if($d == 's' || $d == 'w') {
         $decimal *= -1;
      }
   }
    
   return $decimal;
}

function convert($center, $radius, $numberOfSegments = 360)
{
    $n = $numberOfSegments;
    $flatCoordinates = [];
    $coordinates = [];
    for ($i = 0; $i < $n; $i++) {
        $bearing = 2 * M_PI * $i / $n;
        $flatCoordinates[] = offset($center, $radius, $bearing);
    }
    $flatCoordinates[] = $flatCoordinates[0];

    return [
        'type' => 'Polygon',
        'coordinates' => [$flatCoordinates]
    ];
}

function offset($c1, $distance, $bearing) {
    $lat1 = deg2rad($c1[0]);
    $lon1 = deg2rad($c1[1]);
    $dByR = $distance/6378137; // convert dist to angular distance in radians

    $lat = asin(
        sin($lat1) * cos($dByR) + 
            cos($lat1) * sin($dByR) * cos($bearing)
    );
    $lon = $lon1 + atan2(
        sin($bearing) * sin($dByR) * cos($lat1),
        cos($dByR) - sin($lat1) * sin($lat)
    );
    $lon = fmod(
        $lon + 3 * M_PI,
        2 * M_PI
    ) - M_PI;
    return [rad2deg($lon), rad2deg($lat)];
}

?>