<?php

namespace RtmClient\Subscription;

/**
 * Subscription events list.
 */
class Events
{
    const SUBSCRIBED = 'subscribed';
    const SUBSCRIBE_ERROR = 'subscribe_error';
    const UNSUBSCRIBED = 'unsubscribed';
    const UNSUBSCRIBE_ERROR = 'unsubscribe_error';
    const DATA = 'data';
    const SUBSCRIPTION_ERROR = 'subscription_error';
    const SUBSCRIPTION_INFO = 'subscription_info';
    const POSITION = 'position';
}
