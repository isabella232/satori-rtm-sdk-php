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

// Use the same handlers for different subscriptions
$handlers = array(
    'onSubscribed' => function ($response) {
        echo 'Subscribed to: ' . $response['subscription_id'] . PHP_EOL;
    },
    'onUnsubscribed' => function ($response) {
        echo 'Unsubscribed from: ' . $response['subscription_id'] . PHP_EOL;
    },
    'onData' => function ($data) {
        foreach ($data['messages'] as $message) {
            if ($data['subscription_id'] == 'zebras') {
                echo 'Got a zebra ' . json_encode($message) . PHP_EOL;
            } else {
                echo 'Got a count ' . json_encode($message) . PHP_EOL;
            }
        }
    },
    'onSubscribeError' => function ($err) {
        echo 'Failed to subscribe! Error: ' . $err['error'] . '; Reason: ' . $err['reason'] . PHP_EOL;
    },
    'onSubscriptionError' => function ($err) {
        echo 'Subscription failed. RTM sent the unsolicited error ' . $err['error'] . ': ' . $err['reason'] . PHP_EOL;
    },
);

$zebras = $client->subscribe('zebras', array(
    'filter' => "SELECT * FROM `animals` WHERE who = 'zebra'",
));
$stats = $client->subscribe('stats', array(
    'filter' => "SELECT count(*) as count, who FROM `animals` GROUP BY who",
));

foreach (array($zebras, $stats) as $sub) {
    $sub->onSubscribed($handlers['onSubscribed']);
    $sub->onUnsubscribed($handlers['onUnsubscribed']);
    $sub->onData($handlers['onData']);
    $sub->onSubscribeError($handlers['onSubscribeError']);
    $sub->onSubscriptionError($handlers['onSubscriptionError']);
}

// Read all incoming messages
while (true) {
    $client->sockReadSync();
}
