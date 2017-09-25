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
            'persistent_connection' => true,
        );
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey'], $options);
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
        $options = array(
            'persistent_connection' => false,
        );
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey'], $options);
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
        $options = array(
            'persistent_connection' => true,
        );
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey'], $options);
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
            'persistent_connection' => true,
        );
        $client = new RtmClient($this->credentials['endpoint'], $this->credentials['appkey'], $options);
        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }
        $auth->setAuthInProgress(true);

        register_shutdown_function(function() use ($logger) {
            $last_log = array_pop($logger->storage);
            $this->assertEquals('error', $last_log['type']);
            $this->assertEquals('Connection dropped because auth still in progess, but script died', $last_log['message']);
        });
    }
}
