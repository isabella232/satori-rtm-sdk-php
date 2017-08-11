<?php

namespace Tests\Helpers;

use RtmClient\RtmClient;

class RtmClientExt extends RtmClient
{
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function setConnection(&$connection)
    {
        $this->connection = $connection;
    }
}
