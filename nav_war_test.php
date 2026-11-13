<?php
//Test Nav warning processing
//Take path to file as input

$nav_war	= file_get_contents($argv[1]) or die("Need file path\n");

$result_arr	= process_nav_war($nav_war);

var_dump($result_arr);


function process_nav_war($nav_war)
{
	$working_arr	= explode("\n", $nav_war);
	list($result_arr['nav_war_id'], $result_arr['war_month'], $result_arr['war_day'])	= explode(" ", $working_arr[0]);

	//Get list of date-time ranges
	preg_match_all('/\d{6}Z TO \d{6}Z.(?:[A-Z]{3})?/', $nav_war, $date_matches, PREG_OFFSET_CAPTURE);

	$current_day = 0;
	$current_month = "JAN";

	//Loop through $date_matches and assign months
	//$date_matches	= str_replace(" AND","", $date_matches);	
	array_walk($date_matches[0], function(&$item, $key) use ($current_day, $current_month)
	{
		global $current_month, $current_day;
		$item[0]	= str_replace(" AND","",$item[0]);
		$item[0]	= str_replace(",","",$item[0]);
		//Get first two digits.  If the next loop is lower, then assume a new month has started and increment months
		$previous_day	= $current_day;
		$current_day	= substr($item[0],0,2);
		echo "Day: $current_day\n";
		//If a month is included, get it
		
		if(preg_match('/[A-Z]{3}/',$item[0],$month_matches))
		{
			$current_month	= $month_matches[0];
			echo "Month $current_month detected\n";
		} else
		{
			echo "Month: $current_month\n";
			if ($current_day >= $previous_day)
			{
				//Same month
				$item[0]	= $item[0]." $current_month";
			} else
			{
				$current_month = strtoupper(date('M',strtotime('first day of +1 month',strtotime($current_month))));
				$item[0]	= $item[0]." $current_month";
			}
		}

	
	});

	var_dump($date_matches);

	//After all that work, we only return data if it's a rocket launch for now
	if(strpos($nav_war, 'ROCKET LAUNCHING'))
	{
		return $result_arr;
	} else
	{
		return false;
	}
}

?>