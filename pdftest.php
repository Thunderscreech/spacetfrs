<?php
include 'common.inc';

				exec('pdftotext '.$argv[1].' /home/chairboy/tfrmonitor/launch_forecasts/working.txt');
				$text	= file_get_contents('/home/chairboy/tfrmonitor/launch_forecasts/working.txt');

				$probability	= trim(string_between_two_string($text, "Launch Day

Probability of Violating Weather Constraints", "%"));
				$concerns	= trim(string_between_two_string($text, "Primary Concerns: ", "Weather Conditions"));
				$day_later_p	= trim(string_between_two_string($text, "24-Hour Delay

Probability of Violating Weather Constraints", "%"));

				$launch_primitive	= trim(string_between_two_string($text, "Valid:","Forecast Discussion:"));
				$lp_array		= explode("\n",$launch_primitive);
				$lp_working		= $lp_array[2];

				echo strtotime($lp_working)."\n";
				$launch_date	= translate_spacewing_date($lp_working);

				$weather_string	= "Probability of violating weather constraints: $probability%\nConcerns: $concerns\nAfter 24 hour delay: $day_later_p%\n";

				echo "(".strlen($weather_string)."): \n$weather_string\n";


				if($parent_tweet = find_parent_tweet($conn, $launch_date))
				{
					echo "We want to tweet this as a reply to https://twitter.com/spacetfrs/status/$parent_tweet\n";
				} else
				{
					echo "We did not find a parent tweet to reference.\n";
				}	
				




?>