<?php
include 'common.inc';
$dom = new DOMDocument();
// load file 
$dom->load("launch_sites.kml");
// get coordinates tag
$placemark	= $dom->getElementsByTagName("Placemark");
var_dump($placemark);
foreach($placemark as $place)
{
	$site_name	= $place->getElementsByTagName("name")->item(0)->nodeValue;
	$coordinates_w	= explode(",",trim($place->getElementsByTagName("Point")->item(0)->nodeValue));
	$long		= $coordinates_w[0];
	$lat		= $coordinates_w[1];

	echo "Site: $site_name ($lat, $long)\n";

	$sqlstring	= "INSERT INTO launch_site_locations (site_name, site_latitude, site_longitude) VALUES ('$site_name','$lat','$long')";
	echo $sqlstring."\n";
	$result		= mysqli_query($conn, $sqlstring);
}

exit();
//dont dead open code

$coordinates = $dom->getElementsByTagName("coordinates");
foreach($coordinates as $coordinate)
{
    $lat_long[]	= $coordinate->nodeValue;
}

$site_names = $dom->getElementsByTagName("name");

foreach($site_names as $name)
{
    $site_name[] =  $name->nodeValue;
}

foreach ($site_name as $key => $site)
{
	echo "Site: $site (".$lat_long[$key].")\n";

}

?>