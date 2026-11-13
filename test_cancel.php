<?php
include 'common.inc';
//test the cancel posting logic


$tfr_id	= $argv[1];

		$png_save_path	= "/home/chairboy/tfrmonitor/rendered_maps/";
		$fb_save_path	= "/var/www/html/rendered_maps/";

		//Re-run the tfr render script
		if(isset($tfr_id))
		{
			echo "Render map script running for $tfr_id...\n";
			exec('php /home/chairboy/tfrmonitor/render_tfr_image.php '.$tfr_id);
			//This should generate a temp_png.png for us to use
			rename($png_save_path."temp_file.png", $png_save_path."$tfr_id.png");
		}

		exec('php /home/chairboy/tfrmonitor/tfr_cancelled_image.php '.$tfr_id);

?>