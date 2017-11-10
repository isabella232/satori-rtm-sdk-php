<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use RtmClient\RtmClient;
use RtmClient\Auth\RoleAuth;

use RtmClient\WebSocket\Client as Ws;

class RtmClientKVTest extends RtmClientBaseTestCase
{
    /**
     * @dataProvider protocols
     */
    public function testWrite($protocol)
    {
        $client = $this->establishConnection($protocol);
        $event = false;

        $client->write($this->getChannel(), 123, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $event = true;
        });

        $client->sockReadSync(5);
        $this->assertTrue($event);
    }

    /**
     * @dataProvider protocols
     */
    public function testPublish($protocol)
    {
        $client = $this->establishConnection($protocol);
        $event = false;

        $client->publish($this->getChannel(), 123, function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $event = true;
        });

        $client->sockReadSync(5);
        $this->assertTrue($event);
    }

    /**
     * @dataProvider protocols
     */
    public function testReadInt($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    /**
     * @dataProvider protocols
     */
    public function testReadStruct($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    /**
     * @dataProvider protocols
     */
    public function testReadEmpty($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    /**
     * @dataProvider protocols
     */
    public function testReadWrongChannel($protocol)
    {
        $client = $this->establishConnection($protocol);
        $event = false;

        $client->read('', function ($code, $body) use (&$event) {
            $this->assertEquals($code, RtmClient::CODE_ERROR);
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    /**
     * @dataProvider protocols
     */
    public function testReadSecondValue($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    /**
     * @dataProvider protocols
     */
    public function testReadUnicode($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    /**
     * @dataProvider protocols
     */
    public function testDeleteExisting($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    /**
     * @dataProvider protocols
     */
    public function testRWPermissions($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    /**
     * @dataProvider protocols
     */
    public function testReadPosition($protocol)
    {
        $client = $this->establishConnection($protocol);
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

    public function testCborBin()
    {
        $client = $this->establishConnection('cbor');
        $channel = $this->getChannel();
        $event = false;

        $data = array(
            'bin' => pack("nvc*", 0x1234, 0x5678, 65, 66),
            'bin2' => [0b1, 0b10, 0xFF, 0x2134],
        );
        $client->write($channel, $data, function () {
        });
        $client->sockReadSync(5); // wait for write

        $client->read($channel, function ($code, $body) use (&$event, $data) {
            $this->assertEquals($code, RtmClient::CODE_OK);
            $this->assertEquals($body['message'], $data);
            $event = true;
        });
        $client->sockReadSync(5); // wait for read
        $this->assertTrue($event);
    }

    public function protocols()
    {
        return [
            [Ws::SUB_PROTOCOL_JSON],
            [Ws::SUB_PROTOCOL_CBOR],
        ];
    }
}
