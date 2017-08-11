<?php

namespace Tests\Observer;

use Tests\RtmClientBaseTestCase;
use RtmClient\Observable;

class ObservableTest extends RtmClientBaseTestCase
{
    public function testObserverEvent()
    {
        $count = 0;
        $a = new MyObserver();
        $a->on('test-event', function () use (&$count) {
            $count++;
        });
        $a->fire('test-event');

        $this->assertEquals($count, 1);
    }

    public function testMultipleListeners()
    {
        $events = array();

        $a = new MyObserver();
        $a->on('event', function () use (&$events) {
            $events['a'] = true;
        });
        $a->on('event', function () use (&$events) {
            $events['b'] = true;
        });
        $a->fire('event');

        $this->assertCount(2, $events);
        $this->assertArrayHasKey('a', $events);
        $this->assertArrayHasKey('b', $events);
    }

    public function testDataTransfer()
    {
        $count = 0;
        $a = new MyObserver();
        $a->on('event', function ($data) use (&$count) {
            $this->assertEquals($data, 123);
            $count++;
        });
        $a->fire('event', 123);

        $this->assertEquals($count, 1);
    }

    public function testOff()
    {
        $count = 0;
        $callback = function () use (&$count) {
            $count++;
        };
        $a = new MyObserver();
        $a->on('test-event', $callback);
        $a->fire('test-event');

        $this->assertEquals($count, 1);

        $a->off('test-event', $callback);
        $a->fire('test-event');

        // Must be still 1 call
        $this->assertEquals($count, 1);
    }
}

class MyObserver extends Observable
{
}
