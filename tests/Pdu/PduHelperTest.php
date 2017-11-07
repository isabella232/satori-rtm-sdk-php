<?php

namespace Tests\Pdu;

use Tests\RtmClientBaseTestCase;

use RtmClient\Pdu\Pdu;
use RtmClient\Pdu\Helper;
use RtmClient\Pdu\ReturnCode as RC;

use RtmClient\Exceptions\ApplicationException;

class PduHelperTest extends RtmClientBaseTestCase
{
    public function testPDUResponseCode()
    {
        $pdu = new Pdu('bad_action', array());
        $code = Helper::pduResponseCode($pdu);
        $this->assertEquals($code, RC::CODE_BAD_REQUEST);

        $pdu = new Pdu('publish/ok', array());
        $code = Helper::pduResponseCode($pdu);
        $this->assertEquals($code, RC::CODE_OK_REQUEST);

        $pdu = new Pdu('data/error/ok', array());
        $code = Helper::pduResponseCode($pdu);
        $this->assertEquals($code, RC::CODE_OK_REQUEST);

        $pdu = new Pdu('subscribe/error', array());
        $code = Helper::pduResponseCode($pdu);
        $this->assertEquals($code, RC::CODE_ERROR_REQUEST);

        $pdu = new Pdu('search/data', array());
        $code = Helper::pduResponseCode($pdu);
        $this->assertEquals($code, RC::CODE_DATA_REQUEST);

        $pdu = new Pdu('publish/auth', array());
        $code = Helper::pduResponseCode($pdu);
        $this->assertEquals($code, RC::CODE_UNKNOWN_REQUEST);
    }

    public function testConvertToPduBadJson()
    {
        try {
            Helper::convertToPdu('{}}}}}{{{}{}}}');
            $this->fail('Should not be able to convert non-json string');
        } catch (ApplicationException $e) {
        }
    }

    public function testConvertToPduNoBody()
    {
        try {
            Helper::convertToPdu('{"action": "rtm/publish/ok", "id": 123}');
            $this->fail('Should not be able to convert to PDU without body');
        } catch (ApplicationException $e) {
        }
    }

    public function testConvertToPdu()
    {
        try {
            $struct = array(
                'action' => 'rtm/publish/ok',
                'body' => array(
                    'message' => 'aaa',
                ),
                'id' => 123,
            );
            $pdu = Helper::convertToPdu($struct);
            $this->assertEquals($pdu->action, 'rtm/publish/ok');
            $this->assertEquals($pdu->body, array(
                'message' => 'aaa',
            ));
            $this->assertEquals($pdu->id, 123);
        } catch (ApplicationException $e) {
            $this->fail('Unable to convert to PDU');
        }
    }
}
