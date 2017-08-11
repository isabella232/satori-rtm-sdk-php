<?php

namespace Tests\Subscription;

use Tests\RtmClientBaseTestCase;
use RtmClient\Subscription\Subscription;
use RtmClient\Pdu\Pdu;

class SubscriptionIntegrationTest extends RtmClientBaseTestCase
{
    public function testSubscribeChannelPdu()
    {
        $subscription = new Subscription('animals');
        $sub_pdu = $subscription->subscribePdu();

        $this->assertInstanceOf(Pdu::class, $sub_pdu);
        $this->assertEquals($sub_pdu->action, 'rtm/subscribe');
        $this->assertEquals($sub_pdu->body['channel'], 'animals');
    }

    public function testSubscribeFilterPdu()
    {
        $subscription = new TestSubscription('animals', array(
            'filter' => 'select * from `animals`',
            'history' => array(
                'count' => 10,
            ),
            'fast_forward' => true,
        ));
        $sub_pdu = $subscription->subscribePdu();

        $this->assertInstanceOf(Pdu::class, $sub_pdu);
        $this->assertEquals($sub_pdu->action, 'rtm/subscribe');
        $this->assertEquals($sub_pdu->body['subscription_id'], 'animals');
        $this->assertEquals($sub_pdu->body['filter'], 'select * from `animals`');
        $this->assertEquals($sub_pdu->body['history'], array(
            'count' => 10,
        ));
        $this->assertTrue($subscription->getFastForward());
    }

    public function testUnsubscribePdu()
    {
        $subscription = new Subscription('animals');
        $updu = $subscription->unsubscribePdu();

        $this->assertEquals('rtm/unsubscribe', $updu->action);
        $this->assertEquals('animals', $updu->body['subscription_id']);
    }

    public function testCustomFields()
    {
        $subscription = new TestSubscription('animals', array(
            'aaa' => 123,
            'force' => false,
        ));
        $sub_pdu = $subscription->subscribePdu();

        $this->assertEquals($sub_pdu->body['aaa'], 123);
        $this->assertFalse($sub_pdu->body['force']);
    }

    public function testOptionsGetter()
    {
        $options = array(
            'filter' => 'select * from `animals`',
            'history' => array(
                'count' => 10,
            ),
            'fast_forward' => true,
        );
        $subscription = new Subscription('animals', $options);
        $this->assertEquals($options, $subscription->getOptions());
    }

    public function testTrackPosition()
    {
        $sub = new Subscription('animals');
        $pdu = new Pdu('rtm/subscribe/ok', array(
            'position' => '123',
            'subscription_id' => 'animals',
        ));

        $sub->onPdu($pdu);
        $this->assertEquals('123', $sub->getPosition());

        $pdu->body['position'] = '321';
        $sub->onPdu($pdu);
        $this->assertEquals('321', $sub->getPosition());
    }

    public function testEventSubscribeUnsubscribe()
    {
        $sub = new Subscription('animals');
        $events = 0;
        $sub->onSubscribed(function ($body) use (&$events) {
            $events |= 1;
            $this->assertEquals($body['position'], '123');
            $this->assertEquals($body['subscription_id'], 'animals');
        })->onUnsubscribed(function ($body) use (&$events) {
            $events |= 2;
            $this->assertEquals($body['position'], '321');
            $this->assertEquals($body['subscription_id'], 'animals');
        });

        $pdu = new Pdu('rtm/subscribe/ok', array(
            'position' => '123',
            'subscription_id' => 'animals',
        ));
        $sub->onPdu($pdu);

        $pdu = new Pdu('rtm/unsubscribe/ok', array(
            'position' => '321',
            'subscription_id' => 'animals',
        ));
        $sub->onPdu($pdu);

        $this->assertEquals($events, 3);
    }

    public function testSubscribeError()
    {
        $event = false;
        $sub = new Subscription('animals');
        $sub->onSubscribeError(function ($body) use (&$event) {
            $this->assertEquals($body['error'], 'Error');
            $this->assertEquals($body['reason'], 'Reason');
            $event = true;
        });

        $pdu = new Pdu('rtm/subscribe/error', array(
            'error' => 'Error',
            'reason' => 'Reason',
        ));
        $sub->onPdu($pdu);

        $this->assertTrue($event);
    }

    public function testSubscriptionError()
    {
        $event = 0;
        $sub = new Subscription('animals');
        $sub->onSubscriptionError(function ($body) use (&$event) {
            $this->assertEquals($body['error'], 'Error');
            $this->assertEquals($body['reason'], 'Reason');
            $event |= 1;
        })->onUnsubscribed(function () use (&$event) {
            $event |= 2;
        });

        $pdu = new Pdu('rtm/subscription/error', array(
            'error' => 'Error',
            'reason' => 'Reason',
        ));
        $sub->onPdu($pdu);

        $this->assertEquals($event, 3);
    }

    public function testSubscriptionInfo()
    {
        $event = false;
        $sub = new Subscription('animals');
        $sub->onSubscriptionInfo(function ($body) use (&$event) {
            $this->assertEquals($body['info'], 'Info');
            $this->assertEquals($body['reason'], 'Reason');
            $event = true;
        });

        $pdu = new Pdu('rtm/subscription/info', array(
            'info' => 'Info',
            'reason' => 'Reason',
        ));
        $sub->onPdu($pdu);

        $this->assertTrue($event);
    }

    public function testUnsubscribeError()
    {
        $event = false;
        $sub = new Subscription('animals');
        $sub->onUnsubscribeError(function ($body) use (&$event) {
            $this->assertEquals($body['error'], 'Error');
            $this->assertEquals($body['reason'], 'Reason');
            $event = true;
        });

        $pdu = new Pdu('rtm/unsubscribe/error', array(
            'error' => 'Error',
            'reason' => 'Reason',
        ));
        $sub->onPdu($pdu);

        $this->assertTrue($event);
    }

    public function testOnData()
    {
        $event = false;
        $messages = array(
            '1234',
            431,
            true,
            array(
                'a' => 1,
                'b' => 2,
            ),
        );
        $pdu = new Pdu('rtm/subscription/data', array(
            'messages' => $messages,
            'position' => '123',
        ));
        $sub = new Subscription('animals');
        $sub->onData(function ($body) use (&$messages, &$event) {
            $event = true;
            foreach ($body['messages'] as $message) {
                $msg = array_shift($messages);
                $this->assertEquals($msg, $message);
            }
        });

        $sub->onPdu($pdu);

        $this->assertTrue($event);
    }

    public function testDisconnect()
    {
        $sub = new Subscription('animals');
        $event = false;
        $sub->onUnsubscribed(function ($body) use (&$event) {
            $event = true;
            $this->assertNull($body['error']);
            $this->assertNull($body['reason']);
        });

        $sub->processDisconnect();
        $this->assertTrue($event);
    }
}

class TestSubscription extends Subscription
{
    public function getFastForward()
    {
        return $this->body['fast_forward'];
    }
}
