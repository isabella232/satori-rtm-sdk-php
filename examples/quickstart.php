<?php

require('./src/autoloader.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;
use RtmClient\Subscription\Events;
use RtmClient\WebSocket\ReturnCode as SocketRC;

const ENDPOINT = 'YOUR_ENDPOINT';
const APP_KEY = 'YOUR_APPKEY';
const ROLE = 'YOUR_ROLE';
const ROLE_SECRET_KEY = 'YOUR_ROLE_SECRET';

$options = array();

if (ROLE_SECRET_KEY != 'YOUR_SECRET') {
    $options['auth'] = new RoleAuth(ROLE, ROLE_SECRET_KEY);
}

echo 'RTM client config:' . PHP_EOL;
echo '	endpoint = ' . ENDPOINT . PHP_EOL;
echo '	appkey = ' . APP_KEY . PHP_EOL;
echo '	authenticate? = ' . json_encode(!empty($options['auth'])) . PHP_EOL;

$client = new RtmClient(ENDPOINT, APP_KEY, $options);
$client->onConnected(function () {
    echo 'Connected to Satori RTM and authenticated as ' . ROLE . PHP_EOL;
})->onDisconnected(function () {
    echo 'Disconnected' . PHP_EOL;
})->onError(function ($type, $error) {
    echo "Type: $type; Error: $error[message] ($error[code])" . PHP_EOL;
});

$client->connect() or die;

$callback = function ($ctx, $type, $data) {
    switch ($type) {
        case Events::DATA:
            foreach ($data['messages'] as $message) {
                if (isset($message['who']) && isset($message['where'])) {
                    echo 'Got animal ' . $message['who'] . ': ' . json_encode($message['where']) . PHP_EOL;
                } else {
                    echo 'Got message: ' . json_encode($message) . PHP_EOL;
                }
            }
            break;
        case Events::SUBSCRIBED:
            echo 'Subscribed to: ' . $data['subscription_id'] . PHP_EOL;
            break;
        case Events::ERROR:
            echo 'Subscription error! Error: ' . $err['error'] . '; Reason: ' . $err['reason'] . PHP_EOL;
            break;
    }
};
$client->subscribe('animals', $callback);

while (true) {
    // You must read from the incoming buffer to process incoming messages and avoid buffer overflow
    // Let's read incoming messages for 2 seconds
    $client->sockReadFor(2);

    $lat = 34.134358 + rand(0, 100)/10000;
    $lon = -118.321506 + rand(0, 100)/10000;

    $animal = array(
        'who' => 'zebra',
        'where' => array(
            'lat' => $lat,
            'lon' => $lon,
        ),
    );

    $client->publish("animals", $animal, function ($code, $response) use ($animal) {
        if ($code == RtmClient::CODE_OK) {
            echo 'Animal is published ' . json_encode($animal) . PHP_EOL;
        } else {
            echo 'Publish request failed. ';
            echo 'Error: ' . $response['error'] . '; Reason: ' . $response['reason'] . PHP_EOL;
        }
    });
}
