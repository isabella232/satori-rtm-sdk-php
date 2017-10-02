<?php

namespace Tests\Connection;

use Tests\RtmClientBaseTestCase;
use Tests\Helpers\ConnectionExt;
use RtmClient\Connection;
use RtmClient\WebSocket\Client as Ws;
use RtmClient\WebSocket\Exceptions\BadSchemeException;
use RtmClient\WebSocket\Exceptions\ConnectionException;

class ConnectionTest extends RtmClientBaseTestCase
{
    public static $read_timeout = 5;

    public function testEmptyEndpoint()
    {
        try {
            $connection = new Connection('');
            $this->fail('Connected with empty endpoint, but should not');
        } catch (BadSchemeException $e) {
            return true;
        }
    }

    public function testBadSSLSelfSigned()
    {
        $connection = new Connection('wss://self-signed.badssl.com');
        try {
            $connection->connect();
            $this->fail('Connected to host with self-signed certificate');
        } catch (ConnectionException $e) {
            return true;
        }
    }

    public function testBadSSLExpired()
    {
        $connection = new Connection('wss://expired.badssl.com');
        try {
            $connection->connect();
            $this->fail('Connected to host with expired certificate');
        } catch (ConnectionException $e) {
            return true;
        }
    }

    public function testBasicConnection()
    {
        $connection = new Connection('ws://echo.websocket.org');
        try {
            $this->assertTrue($connection->connect());
        } catch (Exception $e) {
            $this->fail('Unable to connect to ws://echo.websocket.org');
        }
    }

    public function testCallbacks()
    {
        $connection = $this->connectionInstance();

        $got_callback = false;
        $connection->send('test', array(), function () use (&$got_callback) {
            $got_callback = true;
        });
        $connection->read(Ws::SYNC_READ, self::$read_timeout);

        $this->assertTrue($got_callback);
    }

    public function testSocketSend()
    {
        $connection = $this->connectionInstance();

        try {
            $sent = $connection->send('test123', array(
                'a' => 'b',
                'c' => 1,
            ));
            $this->assertTrue($sent);
        } catch (Exception $e) {
            $this->fail('Unable to send message via Socket');
        }
    }

    public function testClosedConnection()
    {
        $connection = $this->connectionInstance();
        $connection->close();

        try {
            $connection->send('action', array(1 => 'a'));
            $this->fail('Sent message to closed connection');
        } catch (ConnectionException $e) {
            return true;
        }
    }

    public function testCloseWithListeners()
    {
        $connection = $this->connectionInstance();

        $called = 0;
        $connection->send('test', array(), function ($pdu) use (&$called) {
            $called++;
            $this->assertEquals($pdu->body['error'], 'disconnected');
            $this->assertEquals($pdu->body['reason'], 'Connection closed');
        });

        $connection->close();
        try {
            $connection->read(Ws::SYNC_READ, self::$read_timeout);
        } catch (ConnectionException $e) {
        }

        $this->assertEquals($called, 1);
    }

    public function testRandomId()
    {
        $this->checkCredentials();

        $endpoint = $this->credentials['endpoint'] . 'v2?appkey=' . $this->credentials['appkey'];
        $connection = new ConnectionExt($endpoint, array(
            'persistent_connection' => true,
        ));
        try {
            $this->assertTrue($connection->connect());
        } catch (Exception $e) {
            $this->fail('Unable to connect to ' . $endpoint);
        }

        $id = $connection->getLastId();

        $connection = new ConnectionExt($endpoint, array(
            'persistent_connection' => true,
        ));
        try {
            $this->assertTrue($connection->connect());
        } catch (Exception $e) {
            $this->fail('Unable to connect to ' . $endpoint);
        }
        $id2 = $connection->getLastId();

        $this->assertNotEquals($id, $id2);

        // Check if can get a response with such id
        $event = false;
        $connection->send("rtm/publish", array('channel' => 'test', 'message' => 123), function () use (&$event) {
            $event = true;
        });
        $connection->waitAllReplies();
        $this->assertTrue($event);
    }

    protected function connectionInstance()
    {
        $this->checkCredentials();

        $endpoint = $this->credentials['endpoint'] . 'v2?appkey=' . $this->credentials['appkey'];
        $connection = new Connection($endpoint);
        try {
            $this->assertTrue($connection->connect());
        } catch (Exception $e) {
            $this->fail('Unable to connect to ' . $endpoint);
        }

        return $connection;
    }
}
