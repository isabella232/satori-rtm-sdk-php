<?php

require(__DIR__ . '/../vendor/autoload.php');

error_reporting(E_ALL);

use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;

use RtmClient\WebSocket\Client as Ws;

const ENDPOINT = 'YOUR_ENDPOINT';
const APP_KEY = 'YOUR_APPKEY';
const ROLE = 'YOUR_ROLE';
const ROLE_SECRET_KEY = 'YOUR_SECRET';

$options = array(
    'protocol' => Ws::PROTOCOL_CBOR,
);

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

$frame = array(
    'id' => 0,
    'payload' => pack("nvc*", 0x1234, 0x5678, 65, 66),
    'data' => [0b1, 0b10, 0xFF, 0x2134],
);

while (true) {
    // Publish message with acknowledge
    echo 'Publish: ' . json_encode($frame) . PHP_EOL;
    $client->publish("frames", $frame, function ($code, $response) {
        if ($code == RtmClient::CODE_OK) {
            echo 'Publish confirmed!' . PHP_EOL;
        } else {
            echo 'Failed to publish. Error: ' . $response['error'] . '; Reason: ' . $response['reason'] . PHP_EOL;
        }
    });

    // Read possible response from RTM (Publish Ack)
    $timeout = 1; // 1 sec
    $client->waitAllReplies($timeout);

    $frame['id']++;
    sleep(1);
}
