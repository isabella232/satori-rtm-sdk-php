<?php

require(__DIR__ . '/../src/autoloader.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;
use RtmClient\Subscription\Events;

const ENDPOINT = 'YOUR_ENDPOINT';
const APP_KEY = 'YOUR_APPKEY';
const ROLE = 'YOUR_ROLE';
const ROLE_SECRET_KEY = 'YOUR_SECRET';

$options = array();

if (ROLE_SECRET_KEY != 'YOUR_SECRET') {
    $options['auth'] = new RoleAuth(ROLE, ROLE_SECRET_KEY);
}

echo 'RTM client config:' . PHP_EOL;
echo '	endpoint = ' . ENDPOINT . PHP_EOL;
echo '	appkey = ' . APP_KEY . PHP_EOL;
echo '	authenticate? = ' . json_encode(!empty($options['auth'])) . PHP_EOL;
if (!empty($options['auth'])) {
    echo '		(as ' . ROLE . ')' . PHP_EOL;
}
$client = new RtmClient(ENDPOINT, APP_KEY, $options);
$client->onConnected(function () {
    echo 'Connected to Satori RTM and authenticated as ' . ROLE . PHP_EOL;
})->onError(function ($type, $error) {
    echo "Type: $type; Error: $error[message] ($error[code])" . PHP_EOL;
});

$client->connect() or die;

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
                if (isset($message['who']) && isset($message['where'])) {
                    echo 'Got animal ' . $message['who'] . ': ' . json_encode($message['where']) . PHP_EOL;
                } else {
                    echo 'Got message: ' . json_encode($message) . PHP_EOL;
                }
            }
            break;
        case Events::ERROR:
            echo 'Subscription failed. ' . $err['error'] . ': ' . $err['reason'] . PHP_EOL;
            break;
    }
};
$subscription = $client->subscribe('animals', $callback, array(
    'history' => array(
        'count' => 10,
    ),
));

// Read all incoming messages
while (true) {
    $client->sockReadSync();
}
