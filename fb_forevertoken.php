<?php
//Get forever token

require_once __DIR__ . '/vendor/autoload.php';
include __DIR__ . '/common.inc';	// for bot_cfg(): the app id/secret and token live in config.ini

$fb = new \Facebook\Facebook([
  'app_id' => bot_cfg('facebook', 'app_id'),           //Replace {your-app-id} with your app ID
  'app_secret' => bot_cfg('facebook', 'app_secret'),   //Replace {your-app-secret} with your app secret
  'graph_api_version' => 'v5.0',
]);

$pageId	 = "106573441470619";

$userAccessToken	= bot_cfg('facebook', 'user_access_token');

$longLivedToken = $fb->getOAuth2Client()->getLongLivedAccessToken($userAccessToken);

$fb->setDefaultAccessToken($longLivedToken);

$response = $fb->sendRequest('GET', $pageId, ['fields' => 'access_token'])
    ->getDecodedBody();

$foreverPageAccessToken = $response['access_token'];

echo $foreverPageAccessToken;



//Upload rendered image to a readable directory to pass $params["picture"] url to Graph API


?>