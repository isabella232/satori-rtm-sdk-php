<?php

namespace Tests\Helpers;

use \RtmClient\WebSocket\Client;
use \RtmClient\WebSocket\ReturnCode;

class WsClientExt extends Client
{
    protected $incoming = array();

    public function putIncomingData($data)
    {
        array_push($this->incoming, $data);
    }

    public function read($mode = self::SYNC_READ, $timeout_sec = 0, $timeout_microsec = 0)
    {
        if (!empty($this->incoming)) {
            return array(ReturnCode::READ_OK, array_pop($this->incoming));
        } else {
            return parent::read($mode, $timeout_sec, $timeout_microsec);
        }
    }
}
