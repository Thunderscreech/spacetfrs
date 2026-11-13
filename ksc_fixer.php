<?php
include 'common.inc';

echo get_circle_map($conn,"28.650,-80.621",16.5*3000)."\n";


function get_circle_map($conn, $raw_airspace, $radius)
{
	//Draw a circle on a map vs polygon

	//Find local launchsites to add to the map
	$starting_point	= explode(',',pair_out($raw_airspace)[0]); //Get one of the locations in TFR and search for nearby launch sites
	//$sqlstring	="SELECT * FROM `launch_site_locations` WHERE site_latitude > ($starting_point[0]-.1) AND site_latitude < ($starting_point[0]+.1) AND site_longitude > ($starting_point[1] - .1) AND site_longitude < ($starting_point[1] + .1)";
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


	list($lat, $long) = explode(',',$raw_airspace);

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