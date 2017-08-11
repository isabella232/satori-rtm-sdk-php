<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;

class RtmClientKVTest extends RtmClientBaseTestCase
{
    public function testWrite()
    {
        $client = $this->establishConnection();
        $event = false;

        $client->write($this->getChannel(), 123, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $event = true;
        });

        $client->sockReadSync(5);
        $this->assertTrue($event);
    }

    public function testPublish()
    {
        $client = $this->establishConnection();
        $event = false;

        $client->publish($this->getChannel(), 123, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $event = true;
        });

        $client->sockReadSync(5);
        $this->assertTrue($event);
    }

    public function testReadInt()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $client->write($channel, 4, function () {
        });
        $client->sockReadSync(5); // wait for write

        $client->read($channel, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertEquals($body['message'], 4);
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testReadStruct()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;
        $struct = array(
            'name' => 'Mike',
            'age' => 19,
            'has_car' => true,
        );

        $client->write($channel, $struct, function () {
        });
        $client->sockReadSync(5); // wait for write

        $client->read($channel, function ($code, $body) use (&$event, $struct) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertEquals($body['message'], $struct);
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testReadEmpty()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $client->read($channel, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertNull($body['message']);
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testReadWrongChannel()
    {
        $client = $this->establishConnection();
        $event = false;

        $client->read('', function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_ERROR);
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testReadSecondValue()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $client->write($channel, 'hello1');
        $client->write($channel, 'hello2', function () {
        });
        $client->sockReadSync(5); // wait for write2

        $client->read($channel, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertEquals($body['message'], 'hello2');
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testReadUnicode()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $client->write($channel, 'Привет, мир!', function () {
        });
        $client->sockReadSync(5); // wait for write

        $client->read($channel, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertEquals($body['message'], 'Привет, мир!');
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testDeleteExisting()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $client->write($channel, 'message', function () {
        });
        $client->sockReadSync(5); // wait for write

        $client->read($channel, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertEquals($body['message'], 'message');
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);

        $event = false;
        $client->delete($channel);
        $client->sockReadSync(5); // wait for delete

        $client->read($channel, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertNull($body['message']);
            $event = true;
        });

        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testRWPermissions()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $client->publish('$system.channel', 'hello', function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_ERROR);
            $this->assertEquals($body['error'], 'authorization_denied');
            $this->assertEquals($body['reason'], 'Unauthorized');
            $event = true;
        });
        $client->sockReadSync(5); // wait for publish
        $this->assertTrue($event);

        $event = false;
        $client->write('$system.channel', 'hello', function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_ERROR);
            $this->assertEquals($body['error'], 'authorization_denied');
            $this->assertEquals($body['reason'], 'Unauthorized');
            $event = true;
        });
        $client->sockReadSync(5); // wait for write
        $this->assertTrue($event);

        $event = false;
        $client->read('$system.channel', function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_ERROR);
            $this->assertEquals($body['error'], 'authorization_denied');
            $this->assertEquals($body['reason'], 'Unauthorized');
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);

        $event = false;
        $client->delete('$system.channel', function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_ERROR);
            $this->assertEquals($body['error'], 'authorization_denied');
            $this->assertEquals($body['reason'], 'Unauthorized');
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function testReadPosition()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $position = $event = null;

        $client->write($channel, 'hello', function ($code, $body) use (&$position) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $position = $body['position'];
        });

        $client->write($channel, 'garbage1');
        $client->write($channel, 'garbage2');
        $client->write($channel, 'garbage3', function () use ($channel, &$event, &$position, $client) {
            $client->read($channel, function ($code, $body) {
                $this->assertEquals($code, RtmClient::CODE_OK);
                $this->assertEquals($body['message'], 'garbage3');
            });
            $client->read($channel, function ($code, $body) use (&$event) {
                $this->assertEquals($code, RtmClient::CODE_OK);
                $this->assertEquals($body['message'], 'hello');
                $event = true;
            }, array(
                'position' => $position
            ));
        });

        for ($i = 0; $i < 4; $i++) {
            $client->sockReadSync(2);
        }

        $this->assertTrue($event);
    }
}
