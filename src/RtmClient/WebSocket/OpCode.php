<?php

namespace RtmClient\WebSocket;

/**
 * Frame opcode.
 * https://tools.ietf.org/html/rfc6455
 */
class OpCode
{
    const CONTINUATION = 0;
    const TEXT = 1;
    const BINARY = 2;
    const CLOSE = 8;
    const PING = 9;
    const PONG = 10;
}
