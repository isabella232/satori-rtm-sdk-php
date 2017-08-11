<?php

namespace RtmClient\Auth;

use RtmClient\Connection;

/**
 * Authentication interface.
 */
interface iAuth
{
    /**
     * Makes authentication procedure.
     *
     * @param Connection $connection
     * @return boolean true if successfully authenticates, false otherwise
     */
    public function authenticate(Connection $connection);
}
