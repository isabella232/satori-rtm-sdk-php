<?php

namespace Tests\Pdu;

use Tests\RtmClientBaseTestCase;
use RtmClient\Pdu\Pdu;

class PduTest extends RtmClientBaseTestCase
{
    public function testBasePDU()
    {
        $action = 'action';
        $body = 'body';
        $pdu = new Pdu($action, $body);

        $this->assertEquals($action, $pdu->action);
        $this->assertEquals($body, $pdu->body);
        $this->assertEquals(null, $pdu->id);
    }

    public function testPDUId()
    {
        $id = 123;
        $pdu = new Pdu('a', 'b', $id);

        $this->assertEquals($id, $pdu->id);
    }

    public function testPDUStruct()
    {
        $body = array(
            'history' => array(
                'count' => 10,
            ),
            'message' => 'hello',
        );
        $pdu = new Pdu('rtm/publish', $body);
        $pdu_id = new Pdu('rtm/publish', $body, 123);

        $this->assertEquals($pdu->struct(), array(
            'action' => 'rtm/publish',
            'body' => $body,
        ));

        $this->assertEquals($pdu_id->struct(), array(
            'action' => 'rtm/publish',
            'body' => $body,
            'id' => 123,
        ));
    }

    public function testStringify()
    {
        $pdu = new Pdu('rtm/publish', array(
            'message' => 1234,
            'zzz' => 'hell\'o\\',
        ));

        $this->assertEquals($pdu->stringify(), '{"action":"rtm/publish","body":{"message":1234,"zzz":"hell\'o\\\\"}}');
    }
}
