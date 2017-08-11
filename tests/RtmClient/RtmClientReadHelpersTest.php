<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use RtmClient\WebSocket\ReturnCode;

class RtmClientReadHelpersTest extends RtmClientBaseTestCase
{
    public function testReadSync()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $event = false;

        $client->publish($channel, 'test', function () use (&$event) {
            $event = true;
        });

        $client->sockReadSync(2);
        $this->assertTrue($event);
    }

    public function testReadSyncMicrosecTimeout()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();

        list($usec, $sec) = explode(" ", microtime());
        $start = (float)$sec + (float)$usec;
        $client->sockReadSync(0, 300000); // 0.3 sec
        list($usec, $sec) = explode(" ", microtime());
        $stop = (float)$sec + (float)$usec;

        $max_error = 40000;

        $this->assertGreaterThan($stop - $start, $start);
        $this->assertLessThan(300000 + $max_error, $stop - $start);
    }
    
    public function testReadSyncTimeout()
    {
        $client = $this->establishConnection();

        // Nothing to publish, so there should not be anything in incoming buffer
        $code = $client->sockReadSync(1);
        $this->assertEquals($code, ReturnCode::READ_TIMEOUT);
    }

    public function testReadAsync()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        
        // Nothing to publish, so there should not be anything in incoming buffer
        $code = $client->sockReadAsync();
        $this->assertEquals(ReturnCode::READ_WOULD_BLOCK, $code);
        $event = false;

        $client->publish($channel, 'test', function () use (&$event) {
            $event = true;
        });
        sleep(1); // Assume that ack will be delivered in a second from RTM

        $code = $client->sockReadAsync();
        $this->assertEquals(ReturnCode::READ_OK, $code);

        $this->assertTrue($event);
    }

    public function testReadMultipleAsync()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $expected = array_fill(0, 5, ReturnCode::READ_WOULD_BLOCK);

        // Nothing to publish, so there should not be anything in incoming buffer
        $actual = array();
        for ($i = 0; $i < 5; $i++) {
            array_push($actual, $client->sockReadAsync());
        }

        $this->assertEquals($expected, $actual);
        $event = false;

        $client->publish($channel, 'test', function () use (&$event) {
            $event = true;
        });
        sleep(1); // Assume that ack will be delivered in a second from RTM

        $actual = array();
        for ($i = 0; $i < 5; $i++) {
            array_push($actual, $client->sockReadAsync());
        }
        $expected[0] = ReturnCode::READ_OK;
        $this->assertEquals($expected, $actual);

        $this->assertTrue($event);
    }

    public function testWaitAllReplies()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $events = 0;
        $callback = function () use (&$events) {
            $events++;
        };

        $client->publish($channel, 123, $callback);
        $client->publish($channel, 'abc', $callback);
        $client->publish($channel, array('a' => 2), $callback);
        $client->write($channel, 123, $callback);
        $client->read($channel, $callback);

        $client->waitAllReplies(3); // Max 3 seconds

        $this->assertEquals(5, $events);
    }

    public function sockReadFor()
    {
        $client = $this->establishConnection();
        $channel = $this->getChannel();
        $index = 0;

        $sub = $client->subscribe($channel);
        $sub->onData(function ($data) use ($client, $channel, &$index) {
            foreach ($data['messages'] as $message) {
                $this->assertEquals('message' . $index, $message);
            }
            $client->publish($channel, 'message' . (++$index));
        });

        $client->publish($channel, 'message' . $index);

        $client->sockReadFor(2);
        $this->assertGreaterThan(5, $index); // Assume we sent more than 5 messages
    }
}
