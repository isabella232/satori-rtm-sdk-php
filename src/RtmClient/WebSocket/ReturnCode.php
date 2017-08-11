<?php

namespace RtmClient\WebSocket;

/**
 * List of possible result of read and write operations.
 */
class ReturnCode
{
    const READ_OK = 'READ_OK';
    const READ_WOULD_BLOCK = 'READ_WOULD_BLOCK';
    const READ_ERROR = 'READ_ERROR';
    const READ_TIMEOUT = 'READ_TIMEOUT';
    const CLOSED = 'CLOSED';
    const PONG = 'PONG';
    const NOT_CONNECTED = 'NOT_CONNECTED';
}
