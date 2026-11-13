<?php
//Get upcoming rocket launches from rocketlaunch.live so TFRs can be matched to the launch that caused them.
//
//  php rocketlaunchlivescraper.php                          normal run (cron): launches from yesterday on
//  php rocketlaunchlivescraper.php --after=2025-01-01 --max-pages=60   backfill history (for testing the matcher)
//
//Stores exact UTC times (t0 / window open / window close) where the feed has them, so the TFR matcher can
//compare times instead of just dates. Every launch is upserted, so slips, renames and pad changes are picked up.
error_reporting(E_ERROR | E_PARSE);
include 'common.inc';

$opts		= getopt('', array('after:', 'max-pages:'));
//Start from yesterday (UTC) so launches later today are never filtered out by the feed's date cutoff
$after_date	= $opts['after'] ?? gmdate('Y-m-d', time() - 86400);
$max_pages	= (int)($opts['max-pages'] ?? 10);

if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $after_date))
{
	die("--after must be YYYY-MM-DD\n");
}

$context = stream_context_create(array(
	'http' => array(
		'header'	=> "Authorization: Bearer ".bot_cfg('rocketlaunchlive', 'api_key'),
		'timeout'	=> 30,
		'ignore_errors'	=> true, //Return the body on HTTP errors so it can be logged
	),
));

$page		= 1;
$last_page	= 1;
$saved		= 0;
$failed		= 0;

do
{
	$api_url	= "https://fdo.rocketlaunch.live/json/launches?after_date=$after_date&page=$page";
	echo "API URL: $api_url\n";

	$json	= @file_get_contents($api_url, false, $context);
	$data	= json_decode((string)$json, true);

	if(!is_array($data) || !isset($data['result']) || !is_array($data['result']))
	{
		$msg	= "rocketlaunch.live fetch failed on page $page: ".substr(trim((string)$json), 0, 200);
		echo "$msg\n";
		if($page == 1)
		{
			//Nothing fetched at all: keep the existing data and say so (at most once a day)
			rll_scrape_alert($msg);
			exit(1);
		}
		break; //Keep what the earlier pages gave us
	}

	if($page == 1)
	{
		file_put_contents(__DIR__.'/rocketlaunch.json', $json);
	}

	$last_page	= (int)($data['last_page'] ?? 1);

	foreach($data['result'] as $launch)
	{
		try
		{
			rll_save_launch($conn, $launch);
			$saved++;
		} catch(Throwable $e)
		{
			//One odd record must not stop the rest
			$failed++;
			echo "Could not save launch ".($launch['id'] ?? '?')." (".($launch['name'] ?? '?')."): ".$e->getMessage()."\n";
		}
	}

	$page++;
} while($page <= $last_page && $page <= $max_pages);

if($last_page > $max_pages)
{
	echo "Stopped at --max-pages=$max_pages of $last_page pages\n";
}

echo "Saved $saved launches, $failed failed\n";

$stmt	= $conn->prepare("UPDATE status SET status_data = ? WHERE status_item = 'last_rocketscrape_run'");
$now	= gmdate('Y-m-d\TH:i:s\Z');
$stmt->bind_param("s", $now);
$stmt->execute();


function rll_utc($value)
{
	//"2026-11-14T05:30Z" or "2025-06-30T18:23:29+00:00" -> "2026-11-14 05:30:00" (UTC), or null
	if(empty($value))
	{
		return null;
	}
	try
	{
		$d	= new DateTime($value, new DateTimeZone('UTC'));
	} catch(Exception $e)
	{
		return null;
	}
	$d->setTimezone(new DateTimeZone('UTC'));
	return $d->format('Y-m-d H:i:s');
}

function rll_save_launch($conn, $v)
{
	$t0		= rll_utc($v['t0'] ?? null);
	$win_open	= rll_utc($v['win_open'] ?? null);
	$win_close	= rll_utc($v['win_close'] ?? null);
	$modified	= rll_utc($v['modified'] ?? null);

	$est		= $v['est_date'] ?? array();
	$anchor		= $t0 ?? $win_open;

	//How well do we know when it launches?
	if($anchor)
	{
		$precision	= 'time';
		$est_date	= substr($anchor, 0, 10); //UTC date
	} elseif(!empty($est['year']) && !empty($est['month']) && !empty($est['day']))
	{
		$precision	= 'day';
		$est_date	= sprintf('%04d-%02d-%02d', $est['year'], $est['month'], $est['day']);
	} else
	{
		$precision	= !empty($est['month']) ? 'month' : (!empty($est['quarter']) ? 'quarter' : (!empty($est['year']) ? 'year' : 'none'));
		$est_date	= null; //Not precise enough to match a TFR
	}

	$id		= (int)$v['id'];
	$name		= (string)($v['name'] ?? '');
	$provider	= (string)($v['provider']['name'] ?? '');
	$vehicle	= (string)($v['vehicle']['name'] ?? '');
	$location	= (string)($v['pad']['location']['slug'] ?? '');
	$slug		= (string)($v['slug'] ?? '');

	echo "$id $name | $provider $vehicle | $location | $precision ".($anchor ?? $est_date ?? '')."\n";

	$stmt	= $conn->prepare("INSERT INTO upcoming_launches
		(launch_id, launch_name, launch_provider, launch_vehicle, launch_location_slug, launch_est_date, launch_slug,
		 launch_t0, launch_win_open, launch_win_close, launch_date_precision, launch_modified, last_seen)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
		ON DUPLICATE KEY UPDATE
		 launch_name = VALUES(launch_name), launch_provider = VALUES(launch_provider), launch_vehicle = VALUES(launch_vehicle),
		 launch_location_slug = VALUES(launch_location_slug), launch_est_date = VALUES(launch_est_date), launch_slug = VALUES(launch_slug),
		 launch_t0 = VALUES(launch_t0), launch_win_open = VALUES(launch_win_open), launch_win_close = VALUES(launch_win_close),
		 launch_date_precision = VALUES(launch_date_precision), launch_modified = VALUES(launch_modified), last_seen = NOW()");
	$stmt->bind_param("isssssssssss", $id, $name, $provider, $vehicle, $location, $est_date, $slug, $t0, $win_open, $win_close, $precision, $modified);
	$stmt->execute();
	$stmt->close();
}

function rll_scrape_alert($msg)
{
	//At most one Discord alert a day; the scraper runs every 6 hours
	$state_file	= __DIR__.'/rll_last_alert.txt';
	if(time() - (int)@file_get_contents($state_file) < 86400)
	{
		return;
	}
	dy_discord($msg);
	file_put_contents($state_file, time());
}

?>
