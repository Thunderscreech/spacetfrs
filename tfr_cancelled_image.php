<?php
//Create a 'cancelled' image for a TFR by overlaying the canceld transparent PNG on the map

$tfr_id	= $argv[1];

//Twitter First
$map_path	= "/home/chairboy/tfrmonitor/rendered_maps/";
$fb_path	= "/var/www/html/rendered_maps/";
$map_file	= $tfr_id.".png";
$watermark	= "tfr_cancelled.png";

//Twitter First
$im1	= imagecreatefrompng($map_path.$map_file);
$im2	= imagecreatefrompng($map_path.$watermark);
$im3	= imagecreatefrompng($fb_path.$map_file);

list($width, $height) 				= getimagesize($map_path.$map_file);
list($watermark_width, $watermark_height)	= getimagesize($map_path.$watermark);
echo "Dimensions: $width/$height - Watermark: $watermark_width/$watermark_height\n";

$width	= imagesx($im1); //Keeping this dynamic for future resize
$height	= imagesy($im1);

$watermark_width	= imagesx($im2); //Keeping this dynamic for future resize
$watermark_height	= imagesy($im2);

//Get x/y for top left corner of where to put centered canceld watermark
$wm_x			= ($width/2) - ($watermark_width/2);
$wm_y			= ($height/2) - ($watermark_height/2);

$im			= imagecreatetruecolor($width, $height);
imagealphablending	($im2, false);
imagesavealpha		($im2, true);

imagecopy		($im1, $im2, $wm_x, $wm_y,0,0,$watermark_width, $watermark_height);
imagepng		($im1, $map_path."$tfr_id-canceled.png");

//Now Facebook
imagecopy		($im3, $im2, $wm_x, $wm_y,0,0,$watermark_width, $watermark_height);
imagepng		($im3, $fb_path."$tfr_id.png");

?>