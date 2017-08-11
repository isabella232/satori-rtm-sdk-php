<?php

require('./src/autoloader.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;

const ENDPOINT = 'YOUR_ENDPOINT';
const APP_KEY = 'YOUR_APPKEY';
const ROLE = 'YOUR_ROLE';
const ROLE_SECRET_KEY = 'YOUR_ROLE_SECRET';

$options = array(
    'auth' => new RoleAuth(ROLE, ROLE_SECRET_KEY),
);
$client = new RtmClient(ENDPOINT, APP_KEY, $options);
$client->onConnected(function () {
    echo 'Connected to Satori RTM and authenticated as ' . ROLE . PHP_EOL;
})->onError(function ($type, $error) {
    echo "Type: $type; Error: $error[message] ($error[code])" . PHP_EOL;
});

$client->connect() or die;

$subscription = $client->subscribe('animals', array(
    'filter' => "SELECT * FROM `animals` WHERE who = 'zebra'",
))->onSubscribed(function ($response) {
    echo 'Subscribed to: ' . $response['subscription_id'] . PHP_EOL;
})->onUnsubscribed(function ($response) {
    echo 'Unsubscribed from: ' . $response['subscription_id'] . PHP_EOL;
})->onData(function ($data) {
    foreach ($data['messages'] as $message) {
        if (isset($message['who']) && isset($message['where'])) {
            echo 'Got animal ' . $message['who'] . ': ' . json_encode($message['where']) . PHP_EOL;
        } else {
            echo 'Got message: ' . json_encode($message) . PHP_EOL;
        }
    }
})->onSubscribeError(function ($err) {
    echo 'Failed to subscribe! Error: ' . $err['error'] . '; Reason: ' . $err['reason'] . PHP_EOL;
})->onSubscriptionError(function ($err) {
    echo 'Subscription failed. RTM sent the unsolicited error ' . $err['error'] . ': ' . $err['reason'] . PHP_EOL;
});

// Read all incoming messages
while (true) {
    $client->sockReadSync();
}
