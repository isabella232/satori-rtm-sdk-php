<?php

namespace RtmClient\Subscription;

use RtmClient\Observable;
use RtmClient\Pdu\Pdu;
use RtmClient\Logger\Logger;

/**
 * RTM Subscription model.
 */
class Subscription extends Observable
{
    /**
     * Subscription identifier
     *
     * @var string
     */
    protected $subscription_id;

    /**
     * Stored subscription position
     *
     * @var string
     */
    protected $position = '';

    /**
     * PDU bodu content
     *
     * @var array
     */
    protected $body = array();

    /**
     * Subscription options
     *
     * @var array
     */
    protected $options;

    /**
     * Creates a subscription model with specified options.
     *
     * When you create a channel subscription, you can specify additional properties,
     * for example, add a filter to the subscription and specify the
     * behavior of the SDK when resubscribing after a reconnection.
     *
     * For more information about the options for a channel subscription,
     * see *Subscribe PDU* in the online docs
     *
     * @param string $subscription_id String that identifies the channel. If you do not
     *                                use the *filter* parameter, it is the channel name. Otherwise,
     *                                it is a unique identifier for the channel (subscription id).
     * @param array $options Subscription options. Additional subscription options for a channel subscription. These options
     *                    are sent to RTM in the *body* element of the
     *                    Protocol Data Unit (PDU) that represents the subscribe request.
     *
     *                    For more information about the *body* element of a PDU,
     *                    see *RTM API* in the online docs
     * @param \Psr\Log\LoggerInterface $logger Custom logger
     */
    public function __construct($subscription_id, $options = array(), $logger = null)
    {
        $this->logger = !empty($logger) ? $logger : new Logger();
        $this->options = $options;

        $this->subscription_id = $subscription_id;

        $this->body = array_merge($this->body, $options);

        if (!empty($this->body['filter'])) {
            $this->body['subscription_id'] = $subscription_id;
        } else {
            $this->body['channel'] = $subscription_id;
        }
    }
    
    /**
     * Processes PDU and executes relevant actions.
     *
     * @param Pdu $pdu PDU object
     * @return true if PDU has been processed, false otherwise
     */
    public function onPdu(Pdu $pdu)
    {
        $this->trackPosition($pdu->body);
        
        switch ($pdu->action) {
            case 'rtm/subscribe/ok':
                $this->processSubscribeOk($pdu->body);
                break;

            case 'rtm/subscribe/error':
                $this->processSubscribeError($pdu->body);
                break;

            case 'rtm/subscription/data':
                $this->processSubscriptionData($pdu->body);
                break;

            case 'rtm/subscription/error':
                $this->processSubscriptionError($pdu->body);
                break;

            case 'rtm/subscription/info':
                $this->processSubscriptionInfo($pdu->body);
                break;

            case 'rtm/unsubscribe/ok':
                $this->processUnsubscribeOk($pdu->body);
                break;

            case 'rtm/unsubscribe/error':
                $this->processUnsubscribeError($pdu->body);
                break;

            default:
                $this->logger->error('Unprocessed subscription PDU: ' . $pdu->stringify());
                $this->Fire(Events::ERROR, array(-2, 'Unprocessed subscription PDU: ' . $pdu->stringify()));
                
                return false;
        }

        return true;
    }

    /**
     * Processes disconnect event.
     *
     * @return void
     */
    public function processDisconnect()
    {
        $this->markUnsubscribe(array(
            'error' => null,
            'reason' => null,
        ));
    }

    /**
     * Generates Subscribe PDU.
     *
     * @return Pdu Subscribe PDU
     */
    public function subscribePdu()
    {
        if (!empty($this->position)) {
            $this->body['position'] = $this->position;
        }
        return new Pdu('rtm/subscribe', $this->body);
    }

    /**
     * Generates Unsubscribe PDU.
     *
     * @return Pdu Unsubscribe PDU
     */
    public function unsubscribePdu()
    {
        return new Pdu('rtm/unsubscribe', array(
            'subscription_id' => $this->subscription_id,
        ));
    }

    /**
     * Returns last known Position.
     *
     * @return int Position
     */
    public function getPosition()
    {
        return $this->position;
    }

    /**
     * Returns Subscription ID.
     *
     * @return mixed Subscription ID
     */
    public function getSubscriptionId()
    {
        return $this->subscription_id;
    }

    /**
     * Returns current Subscription model options.
     *
     * @return array Options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /* ================================================
     * Events helpers
     * ===============================================*/

    /**
     * Fires when getting subscribe/ok.
     * Helper: Fires callback on Events::SUBSCRIBED.
     *
     * @param callable $callback
     * @return $this
     */
    public function onSubscribed(callable $callback)
    {
        $this->on(Events::SUBSCRIBED, $callback);
        return $this;
    }

    /**
     * Fires when getting subscribe/error.
     * Helper: Fires callback on Events::SUBSCRIBE_ERROR.
     *
     * @param callable $callback
     * @return $this
     */
    public function onSubscribeError(callable $callback)
    {
        $this->on(Events::SUBSCRIBE_ERROR, $callback);
        return $this;
    }

    /**
     * Fires when getting unsubscribe/ok.
     * Helper: Fires callback on Events::UNSUBSCRIBED.
     *
     * @param callable $callback
     * @return $this
     */
    public function onUnsubscribed(callable $callback)
    {
        $this->on(Events::UNSUBSCRIBED, $callback);
        return $this;
    }

    /**
     * Fires when getting unsubscribe/error.
     * Helper: Fires callback on Events::UNSUBSCRIBE_ERROR.
     *
     * @param callable $callback
     * @return $this
     */
    public function onUnsubscribeError(callable $callback)
    {
        $this->on(Events::UNSUBSCRIBE_ERROR, $callback);
        return $this;
    }

    /**
     * Fires when getting subscription/data.
     * Helper: Fires callback on Events::DATA (incoming messages).
     *
     * @param callable $callback
     * @return $this
     */
    public function onData(callable $callback)
    {
        $this->on(Events::DATA, $callback);
        return $this;
    }

    /**
     * Fires when getting subscription/error.
     * Helper: Fires callback on Events::SUBSCRIPTION_ERROR.
     *
     * @param callable $callback
     * @return $this
     */
    public function onSubscriptionError(callable $callback)
    {
        $this->on(Events::SUBSCRIPTION_ERROR, $callback);
        return $this;
    }

    /**
     * Fires when getting subscription/info.
     * Helper: Fires callback on Events::SUBSCRIPTION_INFO.
     *
     * @param callable $callback
     * @return $this
     */
    public function onSubscriptionInfo(callable $callback)
    {
        $this->on(Events::SUBSCRIPTION_INFO, $callback);
        return $this;
    }

    /**
     * Fires when PDU contains 'position' field.
     * Helper: Fires callback on Events::POSITION.
     *
     * @param callable $callback
     * @return $this
     */
    public function onPosition(callable $callback)
    {
        $this->on(Events::POSITION, $callback);
        return $this;
    }

    /* ================================================
     * Internal methods
     * ===============================================*/

    /**
     * Processes subscribe/ok PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscribeOk($body)
    {
        $this->logger->info('Subscribed (' . $this->subscription_id . ')');
        $this->Fire(Events::SUBSCRIBED, $body);
    }

    /**
     * Processes subscribe/ok PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscribeError($body)
    {
        $this->logger->error('Subscribe Error (' . $this->subscription_id . '): ' . $body['error'] . ': ' . $body['reason']);
        $this->Fire(Events::SUBSCRIBE_ERROR, $body);
    }

    /**
     * Processes unsubscribe/error PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processUnsubscribeError($body)
    {
        $this->logger->error('Unsubscribe Error (' . $this->subscription_id . '): ' . $body['error'] . ': ' . $body['reason']);
        $this->Fire(Events::UNSUBSCRIBE_ERROR, $body);
    }

    /**
     * Processes subscription/data PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscriptionData($body)
    {
        $this->Fire(Events::DATA, $body);
    }

    /**
     * Processes subscription/error PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscriptionError($body)
    {
        $this->markUnsubscribe();
        $this->logger->error('Subscription Error (' . $this->subscription_id . '): ' . $body['error'] . ': ' . $body['reason']);
        $this->Fire(Events::SUBSCRIPTION_ERROR, $body);
    }

    /**
     * Processes subscription/info PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscriptionInfo($body)
    {
        $this->logger->info('Subscription Info (' . $this->subscription_id . '): ' . $body['info'] . ': ' . $body['reason']);
        $this->Fire(Events::SUBSCRIPTION_INFO, $body);
    }

    /**
     * Processes unsubscribe/ok PDU and changes mark subscription as unsubscribed.
     *
     * @param array $body
     * @return void
     */
    protected function processUnsubscribeOk($body)
    {
        $this->markUnsubscribe($body);
    }

    /**
     * Processes all PDU and checks if PDU has 'position'. Fires POSITION event.
     *
     * @param array $body
     * @return void
     */
    protected function trackPosition($body)
    {
        if (isset($body['position'])) {
            $this->position = $body['position'];
            $this->Fire(Events::POSITION, $body['position']);
        }
    }

    /**
     * Marks subscription as unsubscribed. Fires UNSUBSCRIBED event.
     *
     * @param array $body
     * @return void
     */
    protected function markUnsubscribe($body = array())
    {
        $this->logger->info('Unsubscribed (' . $this->subscription_id . ')');
        $this->Fire(Events::UNSUBSCRIBED, $body);
    }
}
