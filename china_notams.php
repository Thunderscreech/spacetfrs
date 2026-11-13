<?php
//Get JSON from FAA for ZBPE,ZHWH,ZGZU,ZPKM,ZLHW,ZWUQ,ZSHA,ZJSA,ZYSH

$url	= "https://notams.aim.faa.gov/notamSearch/search";

$data	= array(
'sortColummns' => '4 false',
'sortDirection' => 'false',
'latitudeDirection' => 'N',
'longitudeDirection' => 'W',
'notamsOnly: true',
'designatorsForLocation: ZBPE,ZHWH,ZGZU,ZPKM,ZLHW,ZWUQ,ZSHA,ZJSA,ZYSH'
);

$ch	= curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded; charset=UTF-8','user-agent: Mozilla/5.0 (Linux; Android 6.0; Nexus 5 Build/MRA58N) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/105.0.0.0 Mobile Safari/537.36 Edg/105.0.1343.42','cookie: '));
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$json	= curl_exec($ch); //Uncomment for live data

if(curl_errno($ch)){
    echo 'Curl error: ' . curl_error($ch);
}
print_r(curl_getinfo($ch));

file_put_contents(__DIR__.'/china.json', $json);

echo "Returned:\n$json\n";

echo "Run complete.\n";

//Payload
//sortColummns: 4 false
//sortDirection: false
//latitudeDirection: N
//longitudeDirection: W

//notamsOnly: true
//designatorsForLocation: ZBPE,ZHWH,ZGZU,ZPKM,ZLHW,ZWUQ,ZSHA,ZJSA,ZYSH




?>