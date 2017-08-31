<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use RtmClient\RtmClient;
use RtmClient\Subscription\Events;

class RtmClientSubscriptionRealTest extends RtmClientBaseTestCase
{
    public function testWrongPosition()
    {
        $client = $this->establishConnection();
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

        $sub = $client->subscribe($channel, $callback, array(
            'position' => 'wrong_position',
        ));

        $client->sockReadSync(5);

        // Subscribe without position
        $sub = $client->subscribe($channel, $callback);
    }

    public function testExpiredPosition()
    {
        $client = $this->establishConnection();
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
        $sub = $client->subscribe($channel, $callback, array(
            'position' => '123:123',
        ));

        $client->sockReadSync(5);
        $this->assertTrue($event);
    }

    public function testResubscribe()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $events = array(
            Events::ERROR => false,
            Events::SUBSCRIBED => false,
        );

        $callback = function ($ctx, $type, $body) use (&$events) {
            $events[$type] = true;
        };
        $sub = $client->subscribe($channel, $callback, array(
            'position' => 'wrong_position',
        ));
        $client->sockReadSync(5);

        $this->assertTrue($events[Events::ERROR]);
        $this->assertFalse($events[Events::SUBSCRIBED]);
        $this->assertNull($client->getSubscription($channel));

        $events[Events::ERROR] = false;
        $sub = $client->subscribe($channel, $callback, array(
            'filter' => 'SELECT COUNT(*) FROM `test`',
        ));
        $client->sockReadSync(5);
        $this->assertTrue($events[Events::SUBSCRIBED]);
        $this->assertFalse($events[Events::ERROR]);
        $this->assertNotNull($client->getSubscription($channel));

        $events[Events::SUBSCRIBED] = false;
        $sub = $client->subscribe($channel, $callback, array(
            'position' => 'wrong_position',
        ));
        $client->sockReadSync(5);

        $this->assertTrue($events[Events::ERROR]);
        $this->assertFalse($events[Events::SUBSCRIBED]);
        $this->assertNotNull($client->getSubscription($channel));
        $sub = $client->getSubscription($channel);
        $opts = $sub->getOptions();
        $this->assertEquals($opts['filter'], 'SELECT COUNT(*) FROM `test`');
    }

    public function testSimpleSubscription()
    {
        $client = $this->establishConnection();
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

        $sub = $client->subscribe($channel, function ($ctx, $type, $body) use (&$received, &$messages, $client, $channel) {
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

        $client->sockReadSync(5);
        $client->publish($channel, $messages[0]);

        for ($i = 0; $i <= 6 ; $i++) {
            $client->sockReadSync(5);
        }

        $this->assertEquals($received, 7);
    }

    public function testSubscriptionFilter()
    {
        $client = $this->establishConnection();
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
        $sub = $client->subscribe($channel, $callback, array(
            'filter' => 'SELECT * FROM `' . $channel . '` WHERE test != 2'
        ));

        // Subscribe
        $client->sockReadSync(5);

        // Got messages
        for ($i = 0; $i < 3; $i++) {
            $client->sockReadSync(1);
        }

        $this->assertEquals($received, 2);
    }

    public function testUnsubscribe()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();

        $sub = $client->subscribe($channel, function ($ctx, $type, $body) use ($client, $channel, &$received) {
            switch ($type) {
                case Events::SUBSCRIBED:
                    $client->publish($channel, 'test');
                    break;
                case Events::DATA:
                    foreach ($body['messages'] as $message) {
                        $received++;
                    }
                    break;
            }
        });

        // Subscribe
        $client->sockReadSync(5);
        // Get message
        $client->sockReadSync(2);
        $this->assertEquals($received, 1);

        $client->unsubscribe($channel);
        $client->publish($channel, 'test');

        // Check if we get more messages
        $client->sockReadSync(1);

        // Still should be 1
        $this->assertEquals($received, 1);
    }

    public function testUnsubscribeNonExisting()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();

        $this->assertEquals($client->unsubscribe($channel), RtmClient::ERROR_CODE_UNKNOWN_SUBSCRIPTION);
    }
}
