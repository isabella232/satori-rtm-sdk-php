<?php

namespace RtmClient\Pdu;

/**
 * Code number representation.
 */
class ReturnCode
{
    /**
     * Code for all requests without any slashes
     */
    const CODE_BAD_REQUEST = -1;

    /**
     * Code for all requests with "/ok" ending
     */
    const CODE_OK_REQUEST = 0;

    /**
     * Code for all requests with "/error" ending
     */
    const CODE_ERROR_REQUEST = 1;

    /**
     * Code for all requests with "/data" ending
     */
    const CODE_DATA_REQUEST = 2;

    /**
     * Code for all requests with ending that not in (ok, error. data)
     */
    const CODE_UNKNOWN_REQUEST = 3;
}
