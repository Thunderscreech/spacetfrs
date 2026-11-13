<?php
include 'common.inc';
//Use SimpleXML to parse FAA TFRs

//$xml	= file_get_contents('https://tfr.faa.gov/save_pages/detail_1_6464.xml');

//$xml	= file_get_contents('https://tfr.faa.gov/save_pages/detail_1_3270.xml');

$xml	= file_get_contents('https://tfr.faa.gov/save_pages/detail_1_7455.xml');

//Test with 

$tfr	= new SimpleXMLElement($xml);

//foreach($tfr->Group->Add->Not->TfrNot->TFRAreaGroup->abdMergedArea->Avx as $avx_item)
//{
//	//print_r($avx_item);
//
//}

$map_url	= "https://maps.googleapis.com/maps/api/staticmap?size=600x600&maptype=terrain&sensor=false&"; //Base of url, add path(s)

//echo "Altitude: ".$tfr->Group->Add->Not->TfrNot->TfrAreaGroup->aseTFRArea->valDistVerLower." to ".$tfr->Group->Add->Not->TfrNot->TfrAreaGroup->aseTFRArea->valDistVerUpper.$tfr->Group->Add->Not->TfrNot->TfrAreaGroup->aseTFRArea->AseUid->uomDistVerUpper."\n";

foreach($tfr->xpath('//abdMergedArea') as $mergedarea)
{
	echo "Area: ".$mergedarea->AbdUid->AseUid->codeType."\n";
	unset($coordinates);
	foreach($mergedarea->Avx as $avx_item)
	{
		$lat	= $avx_item->geoLat;
		$long	= $avx_item->geoLong;

		if(str_contains($lat, "N"))
		{
			//Positive #, no other change
			$lat	= str_replace('N','',$lat);
		} else
		{
			$lat	= str_replace('S','',$lat);
			$lat	= $lat * -1;
		}

		if(str_contains($long, "E"))
		{
			//Positive #, no other change
			$long	= str_replace('E','',$long);
		} else
		{
			$long	= str_replace('W','',$long);
			$long	= $long * -1;
		}

		$coordinates[]	= "$lat,$long";

		//echo $avx_item->geoLat."/".$avx_item->geoLong;
	}
	$map_path[]	= implode("|", $coordinates);
}

//Make multiple Paths
foreach($map_path as $path)
{
	$map_url	.= "path=color%3ared|weight:1|fillcolor%3awhite|$path&";
}

$map_url	.= "key=" . bot_cfg('google', 'maps_api_key') . "";


echo $map_url."\n";

?>