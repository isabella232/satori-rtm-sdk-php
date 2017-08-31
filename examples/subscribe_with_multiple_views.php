<?php

require('./src/autoloader.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;
use RtmClient\Subscription\Events;

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

// Use the same callback for different subscriptions
$callback = function ($ctx, $type, $data) {
    switch ($type) {
        case Events::SUBSCRIBED:
            echo 'Subscribed to: ' . $data['subscription_id'] . PHP_EOL;
            break;
        case Events::UNSUBSCRIBED:
            echo 'Unsubscribed from: ' . $data['subscription_id'] . PHP_EOL;
            break;
        case Events::DATA:
            foreach ($data['messages'] as $message) {
                if ($data['subscription_id'] == 'zebras') {
                    echo 'Got a zebra ' . json_encode($message) . PHP_EOL;
                } else {
                    echo 'Got a count ' . json_encode($message) . PHP_EOL;
                }
            }
            break;
        case Events::ERROR:
            echo 'Subscription failed. ' . $err['error'] . ': ' . $err['reason'] . PHP_EOL;
            break;
    }
};

$zebras = $client->subscribe('zebras', $callback, array(
    'filter' => "SELECT * FROM `animals` WHERE who = 'zebra'",
));
$stats = $client->subscribe('stats', $callback, array(
    'filter' => "SELECT count(*) as count, who FROM `animals` GROUP BY who",
));

// Read all incoming messages
while (true) {
    $client->sockReadSync();
}
