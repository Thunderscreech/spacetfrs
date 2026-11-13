<?php

//Pull maritime warnings
//Search for entries with Rocket in the description
//Render a map
//post it to dy_discord 
//Figure out next steps

//https://msi.om.east.paas.nga.mil/api/swagger-ui.html#/Navigational%20Warnings/indexBWUsingGET
//https://msi.om.east.paas.nga.mil/api/publications/broadcast-warn?output=json

include 'common.inc';

$arrContextOptions=array(
    "ssl"=>array(
        "verify_peer"=>false,
        "verify_peer_name"=>false,
    ),
);  

$json		= file_get_contents("https://msi.om.east.paas.nga.mil/api/publications/broadcast-warn?output=json", false, stream_context_create($arrContextOptions));
$warnings_array	= json_decode($json, true);


foreach($warnings_array['broadcast-warn'] as $key => $value)
{
	$issueDate	= $value['issueDate'];
	$navArea	= $value['navArea'];
	$subregion	= $value['subregion'];
	$text		= mysqli_real_escape_string($conn, $value['text']);
	$authority	= $value['authority'];
	$msgNumber	= $value['msgNumber'];
	$sqlstring	= "INSERT INTO navsafety (msgNumber, navArea, subregion, text, authority, issueDate) VALUES ('$msgNumber','$navArea','$subregion','$text','$authority', str_to_date(UPPER('$issueDate'),'%d%H%iZ %b %Y')) ON DUPLICATE KEY UPDATE last_seen = NOW()";
	
	

	$result		= mysqli_query($conn, $sqlstring);
}

?>