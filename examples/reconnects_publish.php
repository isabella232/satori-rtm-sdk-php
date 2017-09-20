<?php

require(__DIR__ . '/../src/autoloader.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;
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
})->onError(function ($type, $error) {
    echo "Type: $type; Error: $error[message] ($error[code])" . PHP_EOL;
});

$client->connect() or die;

$animal = array(
    'who' => 'zebra',
    'where' => [34.134358, -118.321506],
);

while (true) {
    $timeout = 1; // 1 sec
    // Publish message with acknowledge
    echo 'Publish: ' . json_encode($animal) . PHP_EOL;

    try {
        $client->publish("animals", $animal, function ($code, $response) {
            if ($code == RtmClient::CODE_OK) {
                echo 'Publish confirmed!' . PHP_EOL;
            } else {
                echo 'Failed to publish. Error: ' . $response['error'] . '; Reason: ' . $response['reason'] . PHP_EOL;
            }
        });

        // Read possible response from RTM (Publish Ack)
        $client->waitAllReplies($timeout);
    } catch (ConnectionException $e) {
        // Recreate a client and connect it once again
        while (!$client->isConnected()) {
            sleep(1); // wait 1 second before reconnect

            // Create a new RtmClient using the old one.
            // All callbacks and subscriptions will be moved to the new client.
            $client = new RtmClient($client);
            $client->connect();
        }
    }

    // Update zebra coords and publish again
    $animal['where'][0] += (rand(1, 100) - 50) / 100000;
    $animal['where'][1] += (rand(1, 100) - 50) / 100000;
    sleep(1);
}
