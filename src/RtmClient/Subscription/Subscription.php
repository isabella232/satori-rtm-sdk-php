<?php

namespace RtmClient\Subscription;

use RtmClient\Observable;
use RtmClient\Pdu\Pdu;
use RtmClient\Logger\Logger;

/**
 * RTM Subscription model.
 */
class Subscription
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
     * @param callable $callback Custom callback. Such callback will be called on any subscription events,
     *                 described in {@see RtmClient\Subscription\Events}
     *                 Callback function will get 3 arguments:
     *                      $ctx - Context. Current subscription instance
     *                      $type - Event type: {@see RtmClient\Subscription\Events}
     *                      $data - Type-related data. Check Protocol Data Unit (PDU)
     *                           to get information about data content
     * @param array $options Subscription options. Additional subscription options for a channel
     *                    subscription. These options are sent to RTM in the *body* element of the
     *                    Protocol Data Unit (PDU) that represents the subscribe request.
     *
     *                    For more information about the *body* element of a PDU,
     *                    see *RTM API* in the online docs
     */
    public function __construct($subscription_id, callable $callback, $options = array())
    {
        $this->logger = new Logger();
        $this->options = $options;
        $this->user_callback = $callback;

        $this->subscription_id = $subscription_id;

        $this->body = array_merge($this->body, $options);
        $this->context = array(
            'subscription' => $this,
        );

        if (!empty($this->body['filter'])) {
            $this->body['subscription_id'] = $subscription_id;
        } else {
            $this->body['channel'] = $subscription_id;
        }
    }

    /**
     * Sets new logger that implements PSR3 Logger interface.
     * https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
     *
     * @param \Psr\Log\LoggerInterface $logger Custom logger
     * @return void
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function setContext($key, $context)
    {
        $this->context[$key] = $context;
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
                $this->callback(Events::ERROR, array(
                    'error' => 'unprocessed_pdu',
                    'reason' => 'Unprocessed subscription PDU: ' . $pdu->stringify(),
                ));
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

    /**
     * Returns user callback function for current subscription
     *
     * @return callable User callback
     */
    public function getCallback()
    {
        return $this->user_callback;
    }

    /* ================================================
     * Internal methods
     * ===============================================*/

     /**
     * Calls user callback.
     *
     * @param string $type Events::[NAME]
     * @param array $data Data to be passed to user callback
     * @return void
     */
    protected function callback($type, $data = null)
    {
        $func = $this->user_callback;
        $func($this->context, $type, $data);
    }

    /**
     * Processes subscribe/ok PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscribeOk($body)
    {
        $this->logger->info('Subscribed (' . $this->subscription_id . ')');
        $this->callback(Events::SUBSCRIBED, $body);
    }

    /**
     * Processes subscribe/ok PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscribeError($body)
    {
        $this->logger->error('Subscribe Error (' . $this->subscription_id . '): '
            . $body['error'] . ': ' . $body['reason']);
        $this->callback(Events::ERROR, $body);
    }

    /**
     * Processes unsubscribe/error PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processUnsubscribeError($body)
    {
        $this->logger->error('Unsubscribe Error (' . $this->subscription_id . '): '
            . $body['error'] . ': ' . $body['reason']);
        $this->callback(Events::ERROR, $body);
    }

    /**
     * Processes subscription/data PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscriptionData($body)
    {
        $this->callback(Events::DATA, $body);
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
        $this->logger->error('Subscription Error (' . $this->subscription_id . '): '
            . $body['error'] . ': ' . $body['reason']);
        $this->callback(Events::ERROR, $body);
    }

    /**
     * Processes subscription/info PDU and fires event.
     *
     * @param array $body
     * @return void
     */
    protected function processSubscriptionInfo($body)
    {
        $this->logger->info('Subscription Info (' . $this->subscription_id . '): '
            . $body['info'] . ': ' . $body['reason']);
        $this->callback(Events::INFO, $body);
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
        $this->callback(Events::UNSUBSCRIBED, $body);
    }
}
