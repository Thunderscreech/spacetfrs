<?php
//Render an image to tweet with TFR announcement
//Take a tfr_id as command line argument and look up details in tfr_list to get text
//php render_tfr_image.php 329
error_reporting(E_ERROR | E_PARSE);

include 'common.inc';

$png_save_path	= __DIR__."/rendered_maps/";

$template_base	= __DIR__."/templates/";
$font_path	= __DIR__."/fonts/";
$template_path	= $template_base."tfr_template_paper.png";
$logo_twitter	= $template_base."SpaceTFRs_twitter_Logo.png";
$logo_facebook	= $template_base."SpaceTFRs_facebook_Logo.png";
$logo_mastodon	= $template_base."SpaceTFRs_mastodon_Logo.png";
$logo_bluesky	= $template_base."SpaceTFRs_bluesky_Logo.png";
$legend_path	= $template_base."legend.png";
$capsule	= $template_base."capsulechute.png";


$tfr_id		= $argv[1];


echo "Beginning map render execution for $tfr_id using $template_path...\n";

//convert_tz('2021-02-08 09:00:00','US/Eastern','UTC')
//convert_tz(tfr_start_time,'US/Eastern','UTC')

//TFR start is stored as Eastern apparently
//Eastern, Pacific, Central, MSK, CST, NZST, JST

//$sqlstring	= "SELECT tfr_detail, tweet, notam, tfr_url, tfr_map_image,tfr_start_time AS start_eastern, tfr_end_time AS end_eastern, convert_tz(tfr_start_time,'US/Eastern','UTC') AS start_utc, convert_tz(tfr_end_time,'US/Eastern','UTC') AS end_utc,
//convert_tz(tfr_start_time,'US/Eastern','US/Pacific') AS start_pacific, convert_tz(tfr_end_time,'US/Eastern','US/Pacific') AS end_pacific,
//convert_tz(tfr_start_time,'US/Eastern','US/Central') AS start_central, convert_tz(tfr_end_time,'US/Eastern','US/Central') AS end_central,
//convert_tz(tfr_start_time,'US/Eastern','Europe/Moscow') AS start_msk, convert_tz(tfr_end_time,'US/Eastern','Europe/Moscow') AS end_msk,
//convert_tz(tfr_start_time,'US/Eastern','Pacific/Auckland') AS start_nzt, convert_tz(tfr_end_time,'US/Eastern','Pacific/Auckland') AS end_nzt,
//convert_tz(tfr_start_time,'US/Eastern','Asia/Tokyo') AS start_tokyo, convert_tz(tfr_end_time,'US/Eastern','Asia/Tokyo') AS end_tokyo
//FROM tfr_list WHERE tfr_id = '$tfr_id'";

$sqlstring	= "SELECT tfr_start_time, tfr_end_time, altitude, map_string, tfr_detail, tweet, notam, tfr_url, tfr_map_image,convert_tz(tfr_start_time,'UTC','America/New_York') AS start_eastern, convert_tz(tfr_end_time,'UTC','America/New_York') AS end_eastern, tfr_start_time AS start_utc, tfr_end_time AS end_utc,
convert_tz(tfr_start_time,'UTC','US/Pacific') AS start_pacific, convert_tz(tfr_end_time,'UTC','US/Pacific') AS end_pacific,
convert_tz(tfr_start_time,'UTC','US/Central') AS start_central, convert_tz(tfr_end_time,'UTC','US/Central') AS end_central,
convert_tz(tfr_start_time,'UTC','Europe/Moscow') AS start_msk, convert_tz(tfr_end_time,'UTC','Europe/Moscow') AS end_msk,
convert_tz(tfr_start_time,'UTC','Pacific/Auckland') AS start_nzt, convert_tz(tfr_end_time,'UTC','Pacific/Auckland') AS end_nzt,
convert_tz(tfr_start_time,'UTC','Asia/Tokyo') AS start_tokyo, convert_tz(tfr_end_time,'UTC','Asia/Tokyo') AS end_tokyo
FROM tfr_list WHERE tfr_id = '$tfr_id'";

echo $sqlstring."\n";
$result		= mysqli_query($conn, $sqlstring);
var_dump($result);
while ($row	= mysqli_fetch_assoc($result))
{
	$altitude	= $row['altitude'];
	$map_string	= $row['map_string'];
	$tfr_detail	= $row['tfr_detail'];
	$tweet		= $row['tweet']; //We use the tweet text to detect capsule landings
	$notam		= $row['notam'];
	$tfr_url	= $row['tfr_url'];
	$tfr_map_path	= $row['tfr_map_image'];

	$start_eastern	= $row['start_eastern'];
	$end_eastern	= $row['end_eastern'];

	$start_central	= $row['start_central'];
	$end_central	= $row['end_central'];

	$start_pacific	= $row['start_pacific'];
	$end_pacific	= $row['end_pacific'];

	$start_msk	= $row['start_msk'];
	$end_msk 	= $row['end_msk'];

	$start_tokyo	= $row['start_tokyo'];
	$end_tokyo	= $row['end_tokyo'];

	$start_nzt	= $row['start_nzt'];
	$end_nzt	= $row['end_nzt'];

	$start_utc	= $row['start_utc'];
	$end_utc	= $row['end_utc'];
	
	$tfr_start_time	= $row['tfr_start_time'];
	$tfr_end_time	= $row['tfr_end_time'];
	echo "Debug: $start_utc/$end_utc\n";
}

//Build our time strings
$time_start_block	= "$start_utc UTC\n$start_eastern Eastern US\n$start_central Central US\n$start_pacific Pacific US\n$start_msk Moscow\n$start_tokyo Tokyo\n$start_nzt New Zealand";
$time_end_block		= "$end_utc UTC\n$end_eastern Eastern US\n$end_central Central US\n$end_pacific Pacific US\n$end_msk Moscow\n$end_tokyo Tokyo\n$end_nzt New Zealand";

echo "Time start block: $time_start_block\n";

//Find out if this is a capsule recovery so we can render one onto the map if it is.
if(str_contains($tweet, 'capsule'))
{
	$is_capsule_recovery = true;
}

//Extract some details
//$tfr_analysis	= get_tfr_details($conn, $tfr_url, $tfr_detail); //We pass along the string from the top level page to use for date if TFR doesn't have it
// Got from DB $altitude	= $tfr_analysis['altitude_string'];
//$file_path	= $tfr_analysis['file_path'];
//$tfr_from	= $tfr_analysis['from_date'];
$tfr_from	= $tfr_start_time;
//$tfr_to		= $tfr_analysis['to_date'];
$tfr_to		= $tfr_end_time;

$location_notes	= location_data($conn, $tfr_detail, $altitude);
$altitude	= wordwrap($altitude, 30, "\n", false);

$location	= wordwrap($location_notes['location'], 30, "\n", false);
$notes		= $location_notes['notes'];

$header_text	= "SPACE OPERATIONS TFR $notam";

echo "Notes from TFR_Detail: $notes\n";

//Check for RLL
list($rll_launch_name, $rll_url)	= attempt_to_match_launch($conn, $tfr_from, $tfr_to, $tfr_detail);
if(isset($rll_launch_name) && isset($rll_url))
{
	echo "RLL MATCH: $rll_launch_name at $rll_url\n";
	$notes		.= "\n\nPossibly:$rll_launch_name ($rll_url)";
} else
{
	$notes		.= "\n\nVisit http://rocketlaunch.live/ to see upcoming launches";
}


$fb_text	= "A new Space Operations TFR has been posted for ".$location_notes['location'].".\n\n$notes.\n\nSee complete details here: $tfr_url";

$notes		= wordwrap ($notes, 80, "\n", false);

echo "Location: $location\nNotes:$notes\n";
echo "Start:\n$time_start_block\n\n$time_end_block\n";

echo "Rendering image...\n";
//Make our overlay
$im		= imagecreatetruecolor(1000, 805);
$final_image	= imagecreatetruecolor(1000, 805);

$image_width	= imagesx($im); //Keeping this dynamic for future resize
$image_height	= imagesy($im);

$black 		= imagecolorallocate($im, 0, 0, 0);
$white 		= imagecolorallocate($im, 255, 255, 255);
$transparent 	= imagecolorallocatealpha($im, 255, 255, 255, 127);
$font		= $font_path."arial.ttf";
$font_blk	= $font_path."ariblk.ttf";

echo "Font: $font\nBlk: $font_blk\n";

imagealphablending	($im, false);
imagesavealpha		($im, true);
imagefilledrectangle($im, 0, 0, $image_width, $image_height, $transparent);
imagefilledrectangle($final_image, 0, 0, $image_width, $image_height, $white);

//Calculate Text location for header
$text_box	= imagettfbbox(35,0,$font_blk, $header_text);
$text_width	= $text_box[2]-$text_box[0];
$text_height	= $text_box[7]-$text_box[1];

$header_x	= ($image_width/2) - ($text_width/2);
$header_y	= 63;

imagettftext($im, 35, 0, $header_x, $header_y, $black, $font_blk, $header_text);
imagettftext($im, 12, 0, 72, 128, $black, $font, $location);
imagettftext($im, 12, 0, 72, 239, $black, $font, $altitude);
imagettftext($im, 12, 0, 72, 371, $black, $font, $time_start_block);
imagettftext($im, 12, 0, 72, 511, $black, $font, $time_end_block);
imagettftext($im, 12, 0, 72, 712, $black, $font, $notes);
imagepng($im, $png_save_path."$tfr_id.png");

//Overlay template over the map
$tfr_map_image	= imagecreatefrompng(__DIR__."/maps/$tfr_id.png");
$template_image	= imagecreatefrompng($template_path);
$logo_twitter	= imagecreatefrompng($logo_twitter);
$logo_facebook	= imagecreatefrompng($logo_facebook);
$logo_mastodon	= imagecreatefrompng($logo_mastodon);
$logo_bluesky	= imagecreatefrompng($logo_bluesky);
$legend		= imagecreatefrompng($legend_path);
$capsulechute	= imagecreatefrompng($capsule);

imagealphablending	($final_image,true);

//Make an overlay with the capsule under parachute for recovery ops
//TODO

echo "Template path: $template_path\n";

//imagecopy		($tfr_map_image, $legend, 0,0,0,0,600,600);
imagecopy		($final_image, $tfr_map_image, 329, 85, 0, 0, 600,600);
imagecopy		($final_image, $template_image, 0, 0, 0, 0, $image_width, $image_height);
imagecopy		($final_image, $im, 0, 0, 0, 0, $image_width, $image_height);

if($is_capsule_recovery == true)
{
	imagecopy	($final_image, $capsulechute, 609,366,0,0, 40,40);
}


//Brand it for the destination site
//Make copy of the clean one for FB
//$fb_image		= $final_image;
$fb_image		= cloneImg($final_image);

//Make copy of the clean one for Mastodon
$mastodon_image		= cloneImg($final_image);

//Make a bluesky copy
$bluesky_image		= cloneImg($final_image);

//Twitter image
imagecopy		($final_image, $logo_twitter,719,691,0,0,209,106); //Twitter
imagepng		($final_image, $png_save_path."temp_file.png");

//Mastodon image
imagecopy		($mastodon_image, $logo_mastodon,719,691,0,0,209,106); //Mastodon
imagepng		($mastodon_image, $png_save_path."temp_mastodon_file.png");

//Bluesky
imagecopy		($bluesky_image, $logo_bluesky,719,691,0,0,209,106); //bluesky
imagepng		($bluesky_image, $png_save_path."temp_bluesky_file.png");

//Facebook image
imagecopy		($fb_image, $logo_facebook,719,691,0,0,209,106); //Facebook
imagepng		($fb_image, $png_save_path."temp_fb_file.png");



echo "Copying rendered map for FB Posting....\n";
copy($png_save_path."temp_fb_file.png", "/var/www/html/rendered_maps/$tfr_id.png");
file_put_contents("/var/www/html/rendered_maps/$tfr_id.txt",$fb_text);

echo "Copying rendered map for Mastodon posting...\n";
copy($png_save_path."temp_mastodon_file.png", "/var/www/html/rendered_maps/mastodon_$tfr_id.png");

//echo "Copying rendered map for Bluesky posting...\n";
//copy($png_save_path."temp_bluesky_file.png", "/var/www/html/rendered_maps/bluesky_$tfr_id.png");


echo "Execution completed in an orderly fashion.\n";

?>