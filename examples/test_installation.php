<?php

require(__DIR__ . '/../src/autoloader.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;

const ENDPOINT = 'YOUR_ENDPOINT';
const APP_KEY = 'YOUR_APPKEY';

$client = new RtmClient(ENDPOINT, APP_KEY);
$client->onConnected(function () {
    echo 'Connected to Satori RTM!' . PHP_EOL;
})->onError(function ($type, $error) {
    echo "Type: $type; Error: $error[message] ($error[code])" . PHP_EOL;
});

$client->connect() or die;
