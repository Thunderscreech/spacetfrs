<?php
require 'tootophp/autoload.php';
include 'common.inc'; 

$tootoPHP = new TootoPHP\TootoPHP('botsin.space');

$app = $tootoPHP->registerApp('SpaceTFRs', 'http://deltayeet.net');

if ( $app === false) {
    throw new Exception('Problem during register app');
}
else {
    // Everything is good ;)
}


echo $app->getAuthUrl();


//$app->authentify("ben.hallert+spacetfrs@gmail.com", "iR~u*O+[^eF.");



?>