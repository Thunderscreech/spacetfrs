<?php
error_reporting(E_ERROR | E_PARSE);
$htmlContent = file_get_contents("https://tfr.faa.gov/tfr2/list.html");

$expStr		= explode("<table width=\"970", $htmlContent);
$htmlContent	= "<table width=\"970".$expStr[1];
$expStr		= explode("bgcolor=\"CC6666", $htmlContent);
$htmlContent	= "bgcolor=\"CC6666".$expStr[0];
$htmlContent	= trim($htmlContent,"<TR ");
$htmlContent	= str_replace("bgcolor=\"CC6666","",$htmlContent);
$htmlContent	= $htmlContent."</tbody>\n</table>";

$rows		= explode("<TR",$htmlContent);
foreach($rows as $row)
{
	$columns	= explode("<td",$row);
	foreach($columns as $variable)
	{
		echo "COLUMN:".$variable;
	}
	echo "\n";
}

//echo $htmlContent;

?>