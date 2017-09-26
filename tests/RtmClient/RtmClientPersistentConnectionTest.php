<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use Tests\Helpers\RoleAuthExt;
use \Tests\Helpers\StorageLogger;
use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;

class RtmClientPersistentConnectionTest extends RtmClientBaseTestCase
{
    public function testPConnection()
    {
        $channel = $this->credentials['auth_restricted_channel'];
        $options = array(
            'auth' => new RoleAuth($this->credentials['auth_role_name'], $this->credentials['auth_role_secret_key']),
        );
        $client = RtmClient::persistentConnection($this->credentials['endpoint'], $this->credentials['appkey'], $options);
        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }

        $event = false;
        $client->publish($channel, 1, function ($code, $body) use (&$event) {
            $this->assertEquals(RtmClient::CODE_OK, $code);
            $event = true;
        });
        $client->waitAllReplies();
        $this->assertTrue($event);

        // Checks if we get error without using persistent connection
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey']);
        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }

        $event = false;
        $client->publish($channel, 1, function ($code, $body) use (&$event) {
            $this->assertEquals(RtmClient::CODE_ERROR, $code);
            $event = true;
        });
        $client->waitAllReplies();
        $this->assertTrue($event);

        // Now use persistent one without auth
        $client = RtmClient::persistentConnection($this->credentials['endpoint'], $this->credentials['appkey']);
        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }

        $event = false;
        $client->publish($channel, 1, function ($code, $body) use (&$event) {
            $this->assertEquals(RtmClient::CODE_OK, $code);
            $event = true;
        });
        $client->waitAllReplies();
        $this->assertTrue($event);
        $client->close();
    }

    public function testAuth()
    {
        $logger = new StorageLogger();
        $channel = $this->getChannel();
        $auth = new RoleAuthExt(
            $this->credentials['auth_role_name'],
            $this->credentials['auth_role_secret_key'],
            array('logger' => $logger)
        );
        $options = array(
            'auth' => $auth,
        );
        $client = RtmClient::persistentConnection($this->credentials['endpoint'], $this->credentials['appkey'], $options);
        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }
        //$auth->setAuthInProgress(true);

        // register_shutdown_function(function () use ($logger) {
        //     $last_log = array_pop($logger->storage);
        //     $this->assertEquals('error', $last_log['type']);
        //     $this->assertEquals('Connection dropped because auth still in progess, but script died', $last_log['message']);
        // });
        $client->close();
    }

    public function testCompareInstances()
    {
        $client = RtmClient::persistentConnection($this->credentials['endpoint'], $this->credentials['appkey']);
        $client2 = RtmClient::persistentConnection($this->credentials['endpoint'], $this->credentials['appkey']);
        $this->assertSame($client, $client2);

        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey']);
        $client2 = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey']);
        $this->assertNotSame($client, $client2);

        $client = RtmClient::persistentConnection($this->credentials['endpoint'], $this->credentials['appkey'], array(
            'connection_id' => 'hash1',
        ));
        $client2 = RtmClient::persistentConnection($this->credentials['endpoint'], $this->credentials['appkey'], array(
            'connection_id' => 'hash2',
        ));
        $this->assertNotSame($client, $client2);
    }

    public function testParallelConnections()
    {
        $channel = $this->credentials['auth_restricted_channel'];
        $endpoint = $this->credentials['endpoint'];
        $appkey = $this->credentials['appkey'];

        $client1 = RtmClient::persistentConnection($endpoint, $appkey, array(
            'auth' => new RoleAuth($this->credentials['auth_role_name'], $this->credentials['auth_role_secret_key']),
            'connection_id' => 'connection1',
        ));
        $client2 = RtmClient::persistentConnection($endpoint, $appkey, array(
            'auth' => new RoleAuth($this->credentials['auth_role_name'], $this->credentials['auth_role_secret_key']),
            'connection_id' => 'connection2',
        ));
        $this->assertNotSame($client1, $client2);

        $client1->connect() or $this->fail('Unable to connect');
        $client2->connect() or $this->fail('Unable to connect');

        // Close connection and check if still can use another one
        $client1->close();

        $event = false;
        $client2->publish($channel, 'test_message', function ($code, $body) use (&$event) {
            $this->assertEquals(RtmClient::CODE_OK, $code);
            $event = true;
        });
        $client2->waitAllReplies();
        $this->assertTrue($event);
        $client2->close();
    }
}
