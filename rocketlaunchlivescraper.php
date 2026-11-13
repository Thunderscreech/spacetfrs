<?php
include 'common.inc';
//Get upcoming rocket launches to seek correlation between TFRs and launches

//Prep vars
$sqlstring	= "SELECT status_data FROM status WHERE status_item = 'last_rocketscrape_run'";
$modified_since	= mysqli_query($conn,$sqlstring)->fetch_object()->status_data;
$after_date	= gmdate('Y-m-d');
//$api_url	= "https://fdo.rocketlaunch.live/json/launches?modified_since=$modified_since&after_date=$after_date";
$api_url	= "https://fdo.rocketlaunch.live/json/launches?after_date=$after_date";

$api_key	= bot_cfg('rocketlaunchlive', 'api_key');

echo "API URL: $api_url\n";

//https://fdo.rocketlaunch.live/json/launches?modified_since=2021-01-01T19:02:00Z&after_date=2021-01-25&key=(in config.ini [rocketlaunchlive] api_key)

//Use authorization header instead of in query

$context = stream_context_create(array(
    'http' => array(
        'header' => "Authorization: Bearer $api_key"),
    ),
);

$json	= file_get_contents($api_url, false, $context);
$result = json_decode($json,true);
file_put_contents(__DIR__.'/rocketlaunch.json', $json);

foreach($result['result'] as $key=>$value)
{
	$launch_name		= $value['name'];
	$launch_win_open	= $value['win_open'];
	$launch_win_close	= $value['win_close'];
	$launch_t0		= $value['t0'];
	$date_str		= $value['date_str'];
	$launch_id		= $value['id'];
	$launch_provider	= $value['provider']['name'];
	$launch_vehicle		= $value['vehicle']['name'];
	$launch_location_slug	= $value['pad']['location']['slug'];
	$launch_est_year	= $value['est_date']['year'];
	if(isset($value['est_date']['month']))
	{
		$launch_est_month	= sprintf("%02s", $value['est_date']['month']);
	} else
	{
		unset($launch_est_month);
	}
	if(isset($value['est_date']['day']))
	{
		$launch_est_day		= sprintf("%02s", $value['est_date']['day']);
	} else
	{
		unset($launch_est_day);
	}
//	$launch_est_date	= $value['est_date']['year']."-".$value['est_date']['month']."-".$value['est_date']['day'];
	unset($launch_est_date);
	$launch_est_date	= "$launch_est_year-$launch_est_month-$launch_est_day";
	$launch_slug		= $value['slug'];

	echo "$launch_name($date_str): ($launch_id/$launch_slug)$launch_provider $launch_vehicle from $launch_location_slug on $launch_est_date with win_open of $launch_win_open and win_close of $launch_win_close and T0 of $launch_t0\n";

	//If there's only an estimate date, use that.  If there's a win_open, use that

	if(isset($launch_win_open))
	{
		//Insert with $launch_win_open
		echo "Exact: $launch_win_open - $launch_name\n";
		$sqlstring		= "INSERT INTO upcoming_launches (launch_id, launch_name, launch_provider, launch_vehicle, launch_location_slug, launch_est_date, launch_slug) VALUES ('$launch_id','$launch_name','$launch_provider','$launch_vehicle','$launch_location_slug',DATE_FORMAT(STR_TO_DATE('$launch_win_open','%Y-%m-%dT%H:%iZ'), '%Y-%m-%d'),'$launch_slug') ON DUPLICATE KEY UPDATE launch_est_date=DATE_FORMAT(STR_TO_DATE('$launch_win_open','%Y-%m-%dT%H:%iZ'), '%Y-%m-%d');";

		echo "$sqlstring\n";
		$result			= mysqli_query($conn, $sqlstring);
		
	}

	//If we only have an estimate, use that
	if (!isset($launch_win_open) && isset($launch_est_year) && isset($launch_est_month) && isset($launch_est_day))
	{
		echo "Estimate: $launch_est_year-$launch_est_month-$launch_est_day - $launch_name\n";
		$sqlstring		= "INSERT INTO upcoming_launches (launch_id, launch_name, launch_provider, launch_vehicle, launch_location_slug, launch_est_date, launch_slug) VALUES ('$launch_id','$launch_name','$launch_provider','$launch_vehicle','$launch_location_slug','$launch_est_date','$launch_slug') ON DUPLICATE KEY UPDATE launch_est_date='$launch_est_date';";
		echo $sqlstring."\n";
		$result			= mysqli_query($conn, $sqlstring);
	} 
}


//Return is JSON

//Iterate through l

//TODO: Update the last run field with current Zulu time 
$new_after_date		= gmdate('Y-m-d\TH:i:s\Z');
$sqlstring		= "UPDATE status SET status_data = '$new_after_date' WHERE status_item = 'last_rocketscrape_run'";
$result			= mysqli_query($conn, $sqlstring);


?>