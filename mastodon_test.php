<?php
require 'tootophp/autoload.php';
include 'common.inc'; 

$tootoPHP = new TootoPHP\TootoPHP('botsin.space');
$app = $tootoPHP->registerApp('SpaceTFRs', 'http://deltayeet.net');

print_r($app->getUser());

?>