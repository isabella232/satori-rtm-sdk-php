<?php

namespace Tests\RtmClient;

use Tests\RtmClientBaseTestCase;
use Tests\Helpers\RtmClientExt;
use RtmClient\RtmClient;
use RtmClient\Exceptions\ApplicationException;

class RtmClientBaseTest extends RtmClientBaseTestCase
{
    public function testEmptyAppKey()
    {
        try {
            $client = new RtmClient('ws://wrong-host-name.www', '');
            $this->fail('Empty appkey is not allowed');
        } catch (ApplicationException $e) {
            $this->assertEquals($e->getCode(), RtmClient::ERROR_CODE_EMPTY_APPKEY);
        }
    }

    public function testEmptyEndpoint()
    {
        try {
            $client = new RtmClient('', '123456789');
            $this->fail('Empty endpoint is not allowed');
        } catch (ApplicationException $e) {
            $this->assertEquals($e->getCode(), RtmClient::ERROR_CODE_EMPTY_ENDPOINT);
        }
    }

    public function testNonExistingSubscription()
    {
        $client = new RtmClientExt('ws://wrong-host-name.www', '123456789');
        $this->assertNull($client->getSubscription('non-existing'));
        $this->assertNull($client->getSubscription(null));
    }

    public function testSubscriptionsList()
    {
        $client = new RtmClientExt('ws://wrong-host-name.www', '123456789');
        $this->assertEmpty($client->getSubscriptions());
    }

    public function testVersionedEndpoint()
    {
        $client = new RtmClientExt('ws://wrong-host-name.www', '123456789');
        $this->assertEquals($client->getEndpoint(), 'ws://wrong-host-name.www/v2');

        $client = new RtmClientExt('ws://wrong-host-name.www/', '123456789');
        $this->assertEquals($client->getEndpoint(), 'ws://wrong-host-name.www/v2');

        $client = new RtmClientExt('wss://wrong-host-name.www/v2', '123456789');
        $this->assertEquals($client->getEndpoint(), 'wss://wrong-host-name.www/v2');

        $client = new RtmClientExt('wss://wrong-host-name.www/v3', '123456789');
        $this->assertEquals($client->getEndpoint(), 'wss://wrong-host-name.www/v3');
    }

    public function testIAuthInterface()
    {
        try {
            $client = new RtmClient('ws://some.host', '1234', array(
                'auth' => $this, // Bad Auth
            ));
            $this->fail('PHPUnit\Framework\TestCase has no iAuth interface implementation');
        } catch (ApplicationException $e) {
            $this->assertEquals('Auth must implement iAuth interface', $e->getMessage());
        }
    }
}
