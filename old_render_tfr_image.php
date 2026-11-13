<?php
//Render an image to tweet with TFR announcement
//Take a tfr_id as command line argument and look up details in tfr_list to get text
//php render_tfr_image.php 329
error_reporting(E_ERROR | E_PARSE);

include 'common.inc';

$png_save_path	= "/home/chairboy/tfrmonitor/rendered_maps/";
$font_path	= "/home/chairboy/tfrmonitor/fonts/";
$template_path	= $png_save_path."tfr_template.png";
$logo_twitter	= $png_save_path."SpaceTFRs_twitter_Logo.png";
$logo_facebook	= $png_save_path."SpaceTFRs_facebook_Logo.png";

$tfr_id		= $argv[1];


echo "Beginning map render execution for $tfr_id using $template_path...\n";

//convert_tz('2021-02-08 09:00:00','US/Eastern','UTC')
//convert_tz(tfr_start_time,'US/Eastern','UTC')

//TFR start is stored as Eastern apparently
//Eastern, Pacific, Central, MSK, CST, NZST, JST

$sqlstring	= "SELECT tfr_detail, notam, tfr_url, tfr_map_image,tfr_start_time AS start_eastern, tfr_end_time AS end_eastern, convert_tz(tfr_start_time,'US/Eastern','UTC') AS start_utc, convert_tz(tfr_end_time,'US/Eastern','UTC') AS end_utc,
convert_tz(tfr_start_time,'US/Eastern','US/Pacific') AS start_pacific, convert_tz(tfr_end_time,'US/Eastern','US/Pacific') AS end_pacific,
convert_tz(tfr_start_time,'US/Eastern','US/Central') AS start_central, convert_tz(tfr_end_time,'US/Eastern','US/Central') AS end_central,
convert_tz(tfr_start_time,'US/Eastern','Europe/Moscow') AS start_msk, convert_tz(tfr_end_time,'US/Eastern','Europe/Moscow') AS end_msk,
convert_tz(tfr_start_time,'US/Eastern','Pacific/Auckland') AS start_nzt, convert_tz(tfr_end_time,'US/Eastern','Pacific/Auckland') AS end_nzt,
convert_tz(tfr_start_time,'US/Eastern','Asia/Tokyo') AS start_tokyo, convert_tz(tfr_end_time,'US/Eastern','Asia/Tokyo') AS end_tokyo
FROM tfr_list WHERE tfr_id = '$tfr_id'";
echo $sqlstring."\n";
$result		= mysqli_query($conn, $sqlstring);
while ($row	= mysqli_fetch_assoc($result))
{
	$tfr_detail	= $row['tfr_detail'];
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
}

//Build our time strings
$time_start_block	= "$start_utc UTC\n$start_eastern Eastern US\n$start_central Central US\n$start_pacific Pacific US\n$start_msk Moscow\n$start_tokyo Tokyo\n$start_nzt New Zealand";
$time_end_block		= "$end_utc UTC\n$end_eastern Eastern US\n$end_central Central US\n$end_pacific Pacific US\n$end_msk Moscow\n$end_tokyo Tokyo\n$end_nzt New Zealand";




//Extract some details
$tfr_analysis	= get_tfr_details($tfr_url, $tfr_detail); //We pass along the string from the top level page to use for date if TFR doesn't have it
$altitude	= $tfr_analysis['altitude_string'];
$file_path	= $tfr_analysis['file_path'];
$tfr_from	= $tfr_analysis['from_date'];
$tfr_to		= $tfr_analysis['to_date'];

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


$notes		= wordwrap ($notes, 80, "\n", false);

$fb_text	= "A new Space Operations TFR has been posted for ".$location_notes['location'].".\n\n$notes.\n\nSee complete details here: $tfr_url";

echo "Location: $location\nNotes:$notes\n";
echo "Start:\n$time_start_block\n\n$time_end_block\n";

echo "Rendering image...\n";
//Make our overlay
$im		= imagecreatetruecolor(880, 805);
$final_image	= imagecreatetruecolor(880, 805);

$image_width	= imagesx($im); //Keeping this dynamic for future resize
$image_height	= imagesy($im);

$black 		= imagecolorallocate($im, 0, 0, 0);
$white 		= imagecolorallocate($im, 255, 255, 255);
$transparent 	= imagecolorallocatealpha($im, 255, 255, 255, 127);
$font		= $font_path."arial.ttf";
$font_blk	= $font_path."ariblk.ttf";

imagealphablending	($im, false);
imagesavealpha		($im, true);
imagefilledrectangle($im, 0, 0, 880, 805, $transparent);
imagefilledrectangle($final_image, 0, 0, 880, 805, $white);

//Calculate Text location for header
$text_box	= imagettfbbox(35,0,$font_blk, $header_text);
$text_width	= $text_box[2]-$text_box[0];
$text_height	= $text_box[7]-$text_box[1];

$header_x	= ($image_width/2) - ($text_width/2);
$header_y	= 63;

imagettftext($im, 35, 0, $header_x, $header_y, $black, $font_blk, $header_text);
imagettftext($im, 12, 0, 12, 128, $black, $font, $location);
imagettftext($im, 12, 0, 12, 239, $black, $font, $altitude);
imagettftext($im, 12, 0, 12, 371, $black, $font, $time_start_block);
imagettftext($im, 12, 0, 12, 511, $black, $font, $time_end_block);
imagettftext($im, 12, 0, 12, 712, $black, $font, $notes);
imagepng($im, $png_save_path."$tfr_id.png");

//Overlay template over the map
echo "File path: $file_path - Logo: $logo_twitter\n";
$tfr_map_image	= imagecreatefrompng($file_path);
$template_image	= imagecreatefrompng($template_path);
$logo_twitter	= imagecreatefrompng($logo_twitter);
$logo_facebook	= imagecreatefrompng($logo_facebook);

imagealphablending	($final_image,true);

imagecopy		($final_image, $tfr_map_image, 269, 90, 0, 0, 600,600);
imagecopy		($final_image, $template_image, 0, 0, 0, 0, 880, 805);
imagecopy		($final_image, $im, 0, 0, 0, 0, 880, 805);

//Brand it for the destination site
//Make copy of the clean one for FB
$fb_image		= $final_image;
imagecopy		($final_image, $logo_twitter,659,691,0,0,209,106); //Twitter
imagepng		($final_image, $png_save_path."temp_file.png");

imagecopy		($fb_image, $logo_facebook,659,691,0,0,209,106); //Facebook
imagepng		($fb_image, $png_save_path."temp_fb_file.png");

echo "Copying rendered map for FB Posting....\n";
copy($png_save_path."temp_fb_file.png", "/var/www/html/rendered_maps/$tfr_id.png");
file_put_contents("/var/www/html/rendered_maps/$tfr_id.txt",$fb_text);

echo "Execution completed in an orderly fashion.\n";

?>