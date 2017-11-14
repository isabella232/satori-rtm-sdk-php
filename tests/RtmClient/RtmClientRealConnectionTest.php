<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use Tests\Helpers\ConnectionExt;
use Tests\Helpers\WsClientExt;

use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;
use RtmClient\Exceptions\ApplicationException;
use RtmClient\WebSocket\Exceptions\ConnectionException;

use RtmClient\WebSocket\Client as Ws;

class RtmClientRealConnectionTest extends RtmClientBaseTestCase
{
    public function testWrongEndpoint()
    {
        $client = new RtmClient('ws://wrong-host-name.www', '123');
        $client->onError(function ($type, $err) {
            $this->assertContains('Failed establish connection', $err['message']);
        });
        if ($client->connect()) {
            $this->fail('Connected to wrong endpoint');
        }
    }

    public function testWrongAuth()
    {
        $this->checkCredentials();

        $options = array(
            'auth' => new RoleAuth('non-existing-role', 'wrong-secret-key'),
        );
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey'], $options);
        $client->onError(function ($type, $err) {
            $this->assertContains('Failed to authenticate', $err['message']);
        });
        if ($client->connect()) {
            $this->fail('Connected to wrong endpoint');
        }
    }

    public function testOnConnected()
    {
        $options = array(
            'auth' => new RoleAuth($this->credentials['auth_role_name'], $this->credentials['auth_role_secret_key']),
        );
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey'], $options);

        $connected = false;
        $client->onError(function ($type, $err) {
            $this->failed($err['message']);
        });
        $client->onConnected(function () use (&$connected) {
            $connected = true;
        });
        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }

        $this->assertTrue($connected);
    }

    public function testDoubleConnected()
    {
        $options = array(
            'auth' => new RoleAuth($this->credentials['auth_role_name'], $this->credentials['auth_role_secret_key']),
        );
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey'], $options);

        $connected = false;
        $client->onError(function ($type, $err) {
            $this->failed($err['message']);
        });
        $client->onConnected(function () use (&$connected) {
            $connected = true;
        });
        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }

        $this->assertTrue($connected);

        try {
            $client->connect();
        } catch (ApplicationException $e) {
            $this->assertContains('in use', $e->getMessage());
        }
    }

    /**
     * @dataProvider protocols
     */
    public function testCloseConnection($protocol)
    {
        $client = $this->establishConnection($protocol);
        $event = false;
        $client->onDisconnected(function ($code, $reason) use (&$event) {
            $this->assertEquals($code, 1000);
            $event = true;
        });
        $client->close();
        
        $this->assertTrue($event);
    }

    public function testUnexpectedCloseFrame()
    {
        $events = 0;
        $client = $this->establishConnection();
        $client->onDisconnected(function ($code, $reason) use (&$events) {
            // We assume that TCP code should be
            // 100 = Network is down
            $this->assertEquals($code, 100);
            $events++;
        });
        $client->OnError(function ($type, $err) use (&$events) {
            $events++;
        });

        $endpoint = $this->credentials['endpoint'] . 'v2?appkey=' . $this->credentials['appkey'];
        $connection = new ConnectionExt($endpoint);
        $connection->connect();
        $ws = $connection->getWs();
        $client->setConnection($connection);

        // Generate close frame
        $status_binstr = sprintf('%016b', 100);
        $status_str = '';
        foreach (str_split($status_binstr, 8) as $binstr) {
            $status_str .= chr(bindec($binstr));
        }
        $ws->send($status_str, true, 8); // send "close" frame
        try {
            $client->sockReadSync(1);
            $this->fail('Should not read from closed connection');
        } catch (ConnectionException $e) {
        }

        $this->assertEquals($events, 2);
    }

    /**
     * @dataProvider protocols
     */
    public function testUnsolicitedErrorPdu($protocol)
    {
        $events = 0;
        $client = $this->establishConnection($protocol);
        $client->onDisconnected(function ($code, $reason) use (&$events) {
            $this->assertEquals(1008, $code);
            $events++;
        });
        $client->OnError(function ($type, $err) use (&$events) {
            $this->assertEquals(RtmClient::ERROR_TYPE_CONNECTION, $type);
            $this->assertEquals(1008, $err['code']);
            $events++;
        });

        $endpoint = $this->credentials['endpoint'] . 'v2?appkey=' . $this->credentials['appkey'];
        $client_connection = $client->getConnection();
        $connection = new ConnectionExt($endpoint, array(
            'on_unsolicited_pdu' => $client_connection->on_unsolicited_pdu,
        ));
        $connection->connect();
        $ws = new WsClientExt($endpoint);
        $ws->connect();
        $connection->setWs($ws);
        $client->setConnection($connection);

        $ws->putIncomingData('{"action": "/error", "body": {"error": "some_error", "reason": "some_reason"}}');
        try {
            $client->sockReadSync(1);
        } catch (ConnectionException $e) {
        }

        $this->assertEquals(2, $events);
        $this->assertEquals(false, $client->isConnected());
    }

    public function protocols()
    {
        return [
            [Ws::PROTOCOL_JSON],
            [Ws::PROTOCOL_CBOR],
        ];
    }
}
