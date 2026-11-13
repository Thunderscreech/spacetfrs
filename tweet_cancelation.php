<?php
//Accept a TFR ID and tweet a cancellation to it
include 'common.inc';

$tfr_id	= $argv[1];

	$sqlstring	= "SELECT tfr_id, notam, tfr_detail, tfr_url, altitude, twitter_status FROM tfr_list WHERE tfr_id = '$tfr_id'";
	echo $sqlstring;
	$result		= mysqli_query($conn, $sqlstring);
	while($row	= mysqli_fetch_assoc($result))
	{
		$notam		= $row['notam'];
		$tfr_id		= $row['tfr_id'];
		$tfr_detail	= $row['tfr_detail'];
		$tfr_url	= $row['tfr_url'];
		$altitude	= $row['altitude'];
		$twitter	= $row['twitter_status'];
		echo 		"Notam $notam has been cancelled.\n";
		$debug_string	.= "Notam $notam has been cancelled.\n";
		$sqlstring	= "UPDATE tfr_list SET deleted_early = 1 WHERE notam = '$notam'";
		$result		= mysqli_query($conn, $sqlstring);
		//Tweet it
		$tweet		= "CANCELED: $notam - $tfr_detail\n$altitude\n$tfr_url\nUpdate:This TFR has been withdrawn.";
		file_put_contents("/var/www/html/rendered_maps/$tfr_id-deleted.txt",$tweet);
		echo "(".strlen($tweet).")$tweet\n";	

		pushbullet("Canceled TFR: $tweet\nhttps://twitter.com/spacetfrs/$twitter");

		$png_save_path	= "/home/chairboy/tfrmonitor/rendered_maps/";
		$fb_save_path	= "/var/www/html/rendered_maps/";

		//Re-run the tfr render script
		if(isset($tfr_id))
		{
			echo "Render map script running for $tfr_id...\n";
			exec('php /home/chairboy/tfrmonitor/render_tfr_image.php '.$tfr_id.' withdrawn');
			//This should generate a temp_png.png for us to use
			rename($png_save_path."temp_file.png", $png_save_path."$tfr_id.png");
		}

		exec('php /home/chairboy/tfrmonitor/tfr_cancelled_image.php '.$tfr_id);

		//Post to Twitter
		echo $png_save_path."$tfr_id-canceled.png\n";
		new_tweet($conn, $tweet, array("$tfr_id-canceled.png"),$twitter);

		//Post to Facebook
		exec('php /home/chairboy/tfrmonitor/fb_page_post.php '.$tfr_id . ' withdrawn');

	} 


?>