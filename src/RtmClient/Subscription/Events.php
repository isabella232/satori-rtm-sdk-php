<?php

namespace RtmClient\Subscription;

/**
 * Subscription events list.
 */
class Events
{
    const INIT = 'init';
    const SUBSCRIBED = 'subscribed';
    const UNSUBSCRIBED = 'unsubscribed';
    const DATA = 'data';
    const INFO = 'info';
    const ERROR = 'error';
}
