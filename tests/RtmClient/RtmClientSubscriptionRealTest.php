<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use RtmClient\RtmClient;
use RtmClient\Subscription\Events;

use RtmClient\WebSocket\Client as Ws;

class RtmClientSubscriptionRealTest extends RtmClientBaseTestCase
{
    /**
     * @dataProvider protocols
     */
    public function testWrongPosition($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $events = array(
            'subscribed' => false,
            'error' => false,
        );

        $callback = function ($ctx, $type, $body) use (&$events) {
            switch ($type) {
                case Events::ERROR:
                    // We should not be subscribed at this moment
                    $this->assertFalse($events['subscribed']);
                    $this->assertEquals($body['error'], 'invalid_format');
                    $this->assertEquals($body['reason'], 'Invalid PDU: \'position\' was invalid');
                    break;
                case Events::SUBSCRIBED:
                    $this->assertTrue($events['error']);
                    break;
            }
        };

        $client->subscribe($channel, $callback, array(
            'position' => 'wrong_position',
        ));

        $client->waitAllReplies(5);

        // Subscribe without position
        $client->subscribe($channel, $callback);
    }

    /**
     * @dataProvider protocols
     */
    public function testExpiredPosition($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $event = false;

        $callback = function ($ctx, $type, $body) use (&$event) {
            switch ($type) {
                case Events::ERROR:
                    $event = true;
                    $this->assertEquals($body['error'], 'expired_position');
                    $this->assertEquals($body['reason'], 'Invalid PDU: \'position\' has expired');
                    break;
                case Events::SUBSCRIBED:
                    $this->fail('Should not subscribe with expired position');
                    break;
            }
        };
        $client->subscribe($channel, $callback, array(
            'position' => '123:123',
        ));

        $client->waitAllReplies(5);
        $this->assertTrue($event);
    }

    /**
     * @dataProvider protocols
     */
    public function testResubscribe($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $events = array(
            Events::ERROR => false,
            Events::SUBSCRIBED => false,
        );

        $callback = function ($ctx, $type, $body) use (&$events) {
            $events[$type] = true;
        };
        $client->subscribe($channel, $callback, array(
            'position' => 'wrong_position',
        ));
        $client->waitAllReplies(5);

        $this->assertTrue($events[Events::ERROR]);
        $this->assertFalse($events[Events::SUBSCRIBED]);
        $this->assertNull($client->getSubscription($channel));

        $events[Events::ERROR] = false;
        $client->subscribe($channel, $callback, array(
            'filter' => 'SELECT COUNT(*) FROM `test`',
        ));
        $client->waitAllReplies(5);
        $this->assertTrue($events[Events::SUBSCRIBED]);
        $this->assertFalse($events[Events::ERROR]);
        $this->assertNotNull($client->getSubscription($channel));

        $events[Events::SUBSCRIBED] = false;
        $client->subscribe($channel, $callback, array(
            'position' => 'wrong_position',
        ));
        $client->waitAllReplies(5);

        $this->assertTrue($events[Events::ERROR]);
        $this->assertFalse($events[Events::SUBSCRIBED]);
        $this->assertNotNull($client->getSubscription($channel));
        $sub = $client->getSubscription($channel);
        $opts = $sub->getOptions();
        $this->assertEquals($opts['filter'], 'SELECT COUNT(*) FROM `test`');
    }

    /**
     * @dataProvider protocols
     */
    public function testSimpleSubscription($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $received = 0;

        $messages = array(
            1234,
            'hello',
            array(
                'a' => null,
                'b' => false,
            ),
            1.123456789,
            2.432,
            7E-10,
            "test\message"
        );

        $client->subscribe($channel, function ($ctx, $type, $body) use (&$received, &$messages, $client, $channel) {
            switch ($type) {
                case Events::DATA:
                    foreach ($body['messages'] as $message) {
                        $expected = array_shift($messages);
                        $this->assertEquals($expected, $message);
                        $received++;
                    }

                    if (!empty($messages)) {
                        $client->publish($channel, reset($messages));
                    }
                    break;
            }
        });

        $client->waitAllReplies(5);
        $client->publish($channel, $messages[0]);

        for ($i = 0; $i <= 6 ; $i++) {
            $client->sockReadSync(5);
        }

        $this->assertEquals($received, 7);
    }

    /**
     * @dataProvider protocols
     */
    public function testSubscriptionFilter($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $received = 0;
        $expected = array(
            array('test' => 1),
            array('test' => 3),
        );

        $callback = function ($ctx, $type, $body) use (&$expected, &$received, $channel, $client) {
            switch ($type) {
                case Events::DATA:
                    foreach ($body['messages'] as $message) {
                        $exp = array_shift($expected);
                        $this->assertEquals($exp, $message);
                        $received++;
                    }
                    break;
                case Events::SUBSCRIBED:
                    for ($i = 1; $i <= 3; $i++) {
                        $client->publish($channel, array('test' => $i));
                    }
                    break;
            }
        };
        $client->subscribe($channel, $callback, array(
            'filter' => 'SELECT * FROM `' . $channel . '` WHERE test != 2'
        ));

        // Subscribe
        $client->waitAllReplies(5);

        // Got messages
        for ($i = 0; $i < 3; $i++) {
            $client->sockReadSync(1);
        }

        $this->assertEquals($received, 2);
    }

    /**
     * @dataProvider protocols
     */
    public function testUnsubscribe($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $received = 0;

        $client->subscribe($channel, function ($ctx, $type, $body) use ($client, $channel, &$received) {
            switch ($type) {
                case Events::SUBSCRIBED:
                    $client->publish($channel, 'test', function () {
                    });
                    break;
                case Events::DATA:
                    foreach ($body['messages'] as $message) {
                        $received++;
                    }
                    break;
            }
        });

        // Subscribe and wait for publish/ok
        $client->sockReadSync(1);
        $client->sockReadSync(1);

        $this->assertEquals(1, $received);

        $client->unsubscribe($channel);
        $client->publish($channel, 'test', function () {
        });

        // Check if we get more messages
        $client->waitAllReplies(1);

        // Still should be 1
        $this->assertEquals($received, 1);
    }

    /**
     * @dataProvider protocols
     */
    public function testUnsubscribeNonExisting($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();

        $this->assertEquals($client->unsubscribe($channel), RtmClient::ERROR_CODE_UNKNOWN_SUBSCRIPTION);
    }

    /**
     * @dataProvider protocols
     */
    public function testCloneClient($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $messages = 0;

        $client->subscribe($channel, function ($ctx, $type, $data) use (&$messages) {
            if ($type == Events::DATA) {
                $messages += count($data['messages']);
            }
        });
        $client->waitAllReplies(5);

        $client2 = clone $client;
        $client2->connect();
        $client2->waitAllReplies(5);

        $client->publish($channel, 'message');
        $client->sockReadSync(1);
        $client2->sockReadSync(1);

        $this->assertEquals(1, $messages);
    }

    /**
     * @dataProvider protocols
     */
    public function testWaitAllRepliesForData($protocol)
    {
        $client = $this->establishConnection($protocol);
        $channel = $this->getChannel();
        $messages = 0;

        $client->subscribe($channel, function ($ctx, $type, $data) use (&$messages) {
            if ($type == Events::DATA) {
                $messages += count($data['messages']);
            }
        });

        $client->waitAllReplies(5);

        $client->publish($channel, 1);
        $client->publish($channel, 2);
        $client->publish($channel, 3, function () {
        });

        $client->waitAllReplies(5);

        $this->assertGreaterThan(0, $messages);
    }

    public function protocols()
    {
        return [
            [Ws::PROTOCOL_JSON],
            [Ws::PROTOCOL_CBOR],
        ];
    }
}
