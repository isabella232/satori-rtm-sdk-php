<?php

require(__DIR__ . '/../src/autoloader.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;
use RtmClient\Subscription\Events;
use RtmClient\Auth\RoleAuth;

use RtmClient\WebSocket\Exceptions\ConnectionException;

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
    echo 'Connected to Satori RTM!' . PHP_EOL;
})->onError(function ($type, $error) use (&$state, &$client) {
    echo "Type: $type; Error: $error[message] ($error[code])" . PHP_EOL;
});

$client->connect() or die;

$callback = function ($ctx, $type, $data) {
    switch ($type) {
        case Events::SUBSCRIBED:
            echo 'Subscribed to: ' . $data['subscription_id'] . PHP_EOL;
            break;
        case Events::UNSUBSCRIBED:
            echo 'Unsubscribed from: ' . $ctx['subscription']->getSubscriptionId() . PHP_EOL;
            break;
        case Events::DATA:
            foreach ($data['messages'] as $message) {
                echo 'Got animal: ' . json_encode($message) . PHP_EOL;
            }
            break;
        case Events::ERROR:
            if (in_array($data['error'], array('expired_position', 'out_of_sync'))) {
                // We out of sync. Try to resubscribe without position
                $sub = $ctx['subscription'];
                $options = $sub->getOptions();
                unset($options['position']);
                $ctx['client']->subscribe($sub->getSubscriptionId(), $sub->getCallback(), $options);
            } else {
                echo 'Subscription failed. ' . $data['error'] . ': ' . $data['reason'] . PHP_EOL;
            }
            break;
    }
};

$client->subscribe('animals', $callback);

// We will read all incoming messages and publish data from time to time
while (true) {
    try {
        $client->sockReadFor(2);

        $animal = array(
            'who' => 'zebra',
            'where' => array(
                'lat' => 34.134358 + rand(0, 100)/10000,
                'lon' => -118.321506 + rand(0, 100)/10000,
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
    } catch (ConnectionException $e) {
        echo 'OK, we will reconnect now' . PHP_EOL;
        while (!$client->isConnected()) {
            sleep(1); // wait 1 second before reconnect

            // Create a new RtmClient using the old one.
            // All callbacks and subscriptions will be moved to the new client.
            $client = clone $client;
            $client->connect();
        }
    }
}
