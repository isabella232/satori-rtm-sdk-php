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

$subscription = $client->subscribe('animals');
$subscription->onSubscribed(function ($response) {
    echo 'Subscribed to: ' . $response['subscription_id'] . PHP_EOL;
})->onData(function ($data) {
    foreach ($data['messages'] as $message) {
        echo 'Got message: ' . json_encode($message) . PHP_EOL;
    }
})->onSubscribeError(function ($err) {
    echo 'Failed to subscribe! Error: ' . $err['error'] . '; Reason: ' . $err['reason'] . PHP_EOL;
});

// Wait for subscribe confirmation message from RTM
$client->waitAllReplies();

// Resubscribe using filter
$subscription = $client->subscribe('animals', array(
    'filter' => "select * from animals where who like 'z%'",
    'force' => true,
));
$subscription->onSubscribed(function ($response) {
    echo 'Resubscribed to: ' . $response['subscription_id'] . PHP_EOL;
});

while (true) {
    // Read all incoming messages
    $client->sockReadSync();
}
