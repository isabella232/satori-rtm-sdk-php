<?php

namespace Tests\Helpers;

use \RtmClient\Connection;

class ConnectionExt extends Connection
{
    public function getWs()
    {
        return $this->ws;
    }
}
