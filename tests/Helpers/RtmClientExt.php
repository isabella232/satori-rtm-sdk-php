<?php

namespace Tests\Helpers;

use RtmClient\RtmClient;

class RtmClientExt extends RtmClient
{
    public function getConnectionUrl()
    {
        return $this->connection_url;
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
