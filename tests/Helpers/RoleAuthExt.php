<?php

namespace Tests\Helpers;

use \RtmClient\Auth\RoleAuth;

class RoleAuthExt extends RoleAuth
{
    public function setAuthInProgress($value)
    {
        $this->auth_in_progress = $value;
    }
}
