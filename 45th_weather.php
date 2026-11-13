<?php
//Scrape 45th Weather and report on changes if we can tie them to launches
//https://www.patrick.spaceforce.mil/About-Us/Weather/
error_reporting(E_ERROR | E_PARSE);
include 'common.inc';

require_once 'vendor/autoload.php';
use thiagoalessio\TesseractOCR\TesseractOCR;

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



echo "Starting weather check...\n";

$options  = 	array('http' => array('user_agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/43.0'));
$context  	= stream_context_create($options);

$html		= file_get_contents("https://www.patrick.spaceforce.mil/About-Us/Weather/", false, $context);

if(empty($html))
{
	echo "Nothing in HTML, we'll catch it the next time.";
	//dy_discord("Empty HTML for 45th weather");
	exit();
}

echo strlen($html)." bytes transferred from 45th Space Weather.\n";

$dom 	= new domDocument; 
$dom->loadHTML($html); 
$dom->preserveWhiteSpace = false; 
$links 	= $dom->getElementsByTagName('a'); 
$start_processing_weather	= false;

//Main loop
foreach($links as $link)
{
	$linkHref	= $link->getAttribute('href');
	echo "Link: $linkHref\n";
	//If I encounter a link to https://www.patrick.spaceforce.mil/Portals/14/Weather/45WS%20Launch%20Forecast%20FAQ%20final.pdf then process the next PDF links

//	if($start_processing_weather	== true)
	if(strpos($linkHref, "Launch.pdf"))
	{
		echo "Weather processing on, checking link...\n";
		echo "$linkHref\n";
		//As far as we know, any links we're getting are potentially weather links so we check if they are PDFs.  If they aren't, we turn off processing mode so the loop can finish
		if(strpos($linkHref, ".pdf"))
		{
			echo "Weather link detected ($linkHref).\n";
			if($file_name	= process_weather_pdf($conn, "https://www.patrick.spaceforce.mil".$linkHref))
			{
				//It was a new weather link and has now been added to the database
				echo "Rendering PDF to PNG...\n";
				//Render the PDF into a PNG and prep for OCR
				exec('/home/chairboy/tfrmonitor/prep_weather_pdf.sh '.$file_name);
				echo "Extracting text from PDF...\n";
				//Extract useful data from the PDF
				exec('pdftotext '.$file_name.' /home/chairboy/tfrmonitor/launch_forecasts/working.txt');
				$text	= file_get_contents('/home/chairboy/tfrmonitor/launch_forecasts/working.txt');

				$probability	= trim(string_between_two_string($text, "Launch Day

Probability of Violating Weather Constraints1", "%"));
				echo "Extracted probability: $probability\n";
				$concerns	= trim(string_between_two_string($text, "Primary Concerns: ", "Additional"));
				echo "Extracted concerns: $concerns\n";
				$day_later_p	= trim(string_between_two_string($text, "24-Hour Delay

Probability of Violating Weather Constraints", "%"));

				$launch_primitive	= trim(string_between_two_string($text, "Valid:","Forecast Discussion:"));
				$lp_array		= explode("\n",$launch_primitive);
				$lp_working		= $lp_array[2];

				echo strtotime($lp_working)."\n";
				$launch_date	= translate_spacewing_date($lp_working);

				$weather_string		= gpt_forecast_summary($text);
				echo "GPT returned: $weather_string\n";
				if(strlen($weather_string) <= 5)
				{
					//Error during GPT session presumbly, use old method
					dy_discord("GPT error during 45th weather attempt");
					$weather_string	= "Probability of violating weather constraints: $probability%\nConcerns: $concerns\nAfter 24 hour delay: $day_later_p%\n";
				} 

				echo "(".strlen($weather_string)."): \n$weather_string\n";

				//Move the PNG for tweeting
				rename("/home/chairboy/tfrmonitor/launch_forecasts/working.png-1.png", "/home/chairboy/tfrmonitor/rendered_maps/$launch_date-weather.png");

				if($parent_tweet = find_parent_tweet($conn, $launch_date,'CANAVER'))
				{
					echo "We want to tweet this as a reply to https://twitter.com/spacetfrs/status/$parent_tweet\n";
					$tweet	= "We found a parent tweet status https://twitter.com/spacetfrs/status/$parent_tweet tweeted '$weather_string'";
					//$return_val	= twute($conn, $weather_string, array("/home/chairboy/tfrmonitor/rendered_maps/$launch_date-weather.png"),$parent_tweet);
				} else
				{
					echo "We did not find a parent tweet to reference.\n";
					//$tweet	= "We did not find a parent tweet to reference, no action taken.\n";
				}	
				//Store the results why not
				//$sqlstring	= "UPDATE weather_reports SET launch_day_probability = '$violation_probability, launch_day_concerns = '$primary_concerns', next_day_probability = '$onedaydelay' WHERE 

				pushbullet($tweet);

				//Tweet the PDF

				
			}
		} else
		{
			//We're not processing weather
			$start_processing_weather	= false;
			echo "End of weather links.\n";
		}
	}

	if(strpos($linkHref, "/45WS Launch Forecast FAQ final.pdf") != false)
	{
		$start_processing_weather	= true;		
		echo "Weather block detected, starting processing.\n";
	}


}

echo "End of line.\n";
exit();

function process_weather_pdf($conn, $weather_link)
{
	//Check the weather link against the database.  If it's new, then we do stuff
	//echo "Received $weather_link in process_weather_pdf...\n";

	$weather_link	= file_url(substr($weather_link,0, strpos($weather_link, "?")));

	//echo "Trimmed name: $weather_link\n";

	$sqlstring	= "SELECT COUNT(*) AS cnt FROM weather_reports WHERE weather_href = '$weather_link'";
	echo "Debug: $sqlstring\n";
	if(mysqli_query($conn,$sqlstring)->fetch_object()->cnt >= 1)
	{
		echo "Known weather report, no action: $weather_link\n";		
		$file_name	= false;
	} else
	{
		//We do stuff with this
		echo "Unknown weather report, action: $weather_link\n";
		//Download it to /launch_forecasts
		$options  = 	array('http' => array('user_agent' => 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:41.0) Gecko/20100101 Firefox/43.0'));
		$context  	= stream_context_create($options);

		$file_name	= "/home/chairboy/tfrmonitor/launch_forecasts/".rawurlencode(basename($weather_link));
		
		echo "Transferring to $weather_link to $file_name...\n";

		$pdf_file	= file_get_contents($weather_link, false, $context);
		file_put_contents($file_name, $pdf_file);

		$returnval	= true;
		$sqlstring	= "INSERT INTO weather_reports (weather_href) VALUES ('$weather_link')";
		$result		= mysqli_query($conn, $sqlstring);
	}
	echo "Returning $file_name to calling function.\n";
	return $file_name;
	
}

function ocr_process($file_path)
{				
	$ocr_output = (new TesseractOCR($file_path))
 					->run();
		
	echo "The output was: $ocr_output\n";

	return $ocr_output;

}


function file_url($url)
{
  $parts = parse_url($url);
  $path_parts = array_map('rawurldecode', explode('/', $parts['path']));

  return
    $parts['scheme'] . '://' .
    $parts['host'] .
    implode('/', array_map('rawurlencode', $path_parts))
  ;
}

?>