<?php

require('./src/autoloader.php');

use RtmClient\RtmClient;

const ENDPOINT = 'YOUR_ENDPOINT';
const APP_KEY = 'YOUR_APPKEY';
const CHANNEL  = 'OPEN_CHANNEL';

$client = new RtmClient(ENDPOINT, APP_KEY);
$client->onConnected(function () {
    echo 'Connected to Satori RTM!' . PHP_EOL;
});

$client->connect() or die;

$subscription = $client->subscribe(CHANNEL);
$subscription->onData(function ($data) {
    foreach ($data['messages'] as $message) {
        echo 'Got message: ' . json_encode($message) . PHP_EOL;
    }
});

while (true) {
    $client->sockReadSync();
}
