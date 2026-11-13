<?php
require 'tootophp/autoload.php';
include 'common.inc'; 

$tootoPHP = new TootoPHP\TootoPHP('botsin.space');
$app = $tootoPHP->registerApp('SpaceTFRs', 'http://deltayeet.net');

$app->registerAccessToken(bot_cfg('tootophp', 'access_token')); //token from mastodon_1

$app->authentify("ben.hallert+spacetfrs@gmail.com", "iR~u*O+[^eF.");

?>