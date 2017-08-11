<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use RtmClient\RtmClient;

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

        $listeners = array(
            'onSubscribeError' => function ($err) use (&$events) {
                // We should not be subscribed at this moment
                $this->assertFalse($events['subscribed']);
                $this->assertEquals($err['error'], 'invalid_format');
                $this->assertEquals($err['reason'], 'Invalid PDU: \'position\' was invalid');
            },
            'onSubscribed' => function ($body) use (&$events) {
                // First we checked wrong position error
                $this->assertTrue($events['error']);
            },
        );
        $sub = $client->subscribe($channel, array(
            'position' => 'wrong_position',
        ))->onSubscribed($listeners['onSubscribed'])->onSubscribeError($listeners['onSubscribeError']);
        
        $client->sockReadSync(5);

        // Subscribe without position
        $sub = $client->subscribe($channel, array(
        ))->onSubscribed($listeners['onSubscribed'])->onSubscribeError($listeners['onSubscribeError']);
    }

    public function testExpiredPosition()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $sub = $client->subscribe($channel, array(
            'position' => '123:123',

        ))->onSubscribeError(function ($err) use (&$event) {
            $event = true;
            $this->assertEquals($err['error'], 'expired_position');
            $this->assertEquals($err['reason'], 'Invalid PDU: \'position\' has expired');
        })->onSubscribed(function ($body) use (&$events) {
            $this->fail('Should not subscribe with expired position');
        });
        
        $client->sockReadSync(5);
        $this->assertTrue($event);
    }

    public function testResubscribe()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $sub = $client->subscribe($channel, array(
            'position' => 'wrong_position',
        ))->onSubscribeError(function ($err) use (&$event) {
            $event = true;
        });
        $client->sockReadSync(5);

        $this->assertTrue($event);
        $this->assertNull($client->getSubscription($channel));

        $event = false;
        $sub = $client->subscribe($channel, array(
            'filter' => 'SELECT COUNT(*) FROM `test`',
        ))->onSubscribed(function ($err) use (&$event) {
            $event = true;
        });
        $client->sockReadSync(5);
        $this->assertTrue($event);
        $this->assertNotNull($client->getSubscription($channel));

        $event = false;
        $sub = $client->subscribe($channel, array(
            'position' => 'wrong_position',
        ))->onSubscribeError(function ($err) use (&$event) {
            $event = true;
        });
        $client->sockReadSync(5);

        $this->assertTrue($event);
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

        $sub = $client->subscribe($channel);
        $sub->onData(function ($data) use (&$received, &$messages, $client, $channel) {
            foreach ($data['messages'] as $message) {
                $expected = array_shift($messages);
                $this->assertEquals($expected, $message);
                $received++;
            }

            if (!empty($messages)) {
                $client->publish($channel, reset($messages));
            }
        });
        // Subscribe
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

        $sub = $client->subscribe($channel, array(
            'filter' => 'SELECT * FROM `' . $channel . '` WHERE test != 2'
        ));
        $sub->onData(function ($data) use (&$expected, &$received) {
            foreach ($data['messages'] as $message) {
                $exp = array_shift($expected);
                $this->assertEquals($exp, $message);
                $received++;
            }
        })->onSubscribed(function () use ($channel, $client) {
            for ($i = 1; $i <= 3; $i++) {
                $client->publish($channel, array('test' => $i));
            }
        });

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

        $sub = $client->subscribe($channel);
        $sub->onSubscribed(function () use ($client, $channel) {
            $client->publish($channel, 'test');
        })->onData(function ($data) use (&$received) {
            foreach ($data['messages'] as $message) {
                $received++;
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
