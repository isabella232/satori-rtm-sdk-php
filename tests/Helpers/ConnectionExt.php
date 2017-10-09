<?php

namespace Tests\Helpers;

use \RtmClient\Connection;

class ConnectionExt extends Connection
{
    public function getWs()
    {
        return $this->ws;
    }

    public function setWs($ws)
    {
        $this->ws = $ws;
    }

    public function getLastId()
    {
        return $this->last_id;
    }
}
