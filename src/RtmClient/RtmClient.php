<?php

namespace RtmClient;

use RtmClient\Logger\Logger;

use RtmClient\Pdu\Pdu;
use RtmClient\Pdu\Helper as PduHelper;
use RtmClient\Pdu\ReturnCode as PduRC;

use RtmClient\Exceptions\ApplicationException;
use RtmClient\Exceptions\AuthenticationException;

use RtmClient\WebSocket\Client as Ws;
use RtmClient\WebSocket\ReturnCode as SocketRC;
use RtmClient\WebSocket\Exceptions\ConnectionException;
use RtmClient\WebSocket\Exceptions\TimeoutException;
use RtmClient\WebSocket\Exceptions\BadSchemeException;

use RtmClient\Subscription\Subscription;

/**
 * RTM client.
 *
 * The **RtmClient** class is the main entry point to manage the
 * WebSocket connection from the PHP SDK to RTM.
 *
 * Use the RtmClient class to create a client instance from which you can
 * publish messages and subscribe to channels, create separate
 * Subscription objects for each channel to which you want to subscribe.
 *
 * RtmClient has a **single-threaded** model.
 * This model imposes some limitations:
 *  - You cannot read from a WebSocket connection and write to it in the same time;
 *  - You **MUST TO** read from a WebSocket connection from time to time to avoid buffer overflowing;
 *
 *
 * Events
 * =============================================
 * RTM Client allows to use Event-Based model for Events.
 * Use client.on&lt;Event&gt; function to continuously processing events.
 *
 * Base syntax: `$client->onEvent($callback_function);`
 *
 * Example:
 * ```php
 * <?php
 * $client = new RtmClient(ENDPOINT, APP_KEY);
 * $client->onConnected(function () {
 *     echo 'Connected to Satori RTM and authenticated as ' . ROLE . PHP_EOL;
 * })->onError(function ($type, $error) {
 *     echo "Type: $type; Error: $error[message] ($error[code])" . PHP_EOL;
 * });
 * $client->connect();
 * ```
 *
 *
 * Each event handler returns $client object, so you can register callbacks continuously.
 *
 *
 * You can register multiple callbacks on the same event:
 * ```php
 * $client = new RtmClient(ENDPOINT, APP_KEY);
 * $client->onConnected(function () {
 *      echo 'Callback 1';
 * });
 * $client->onConnected(function () {
 *      echo 'Callback 2';
 * });
 * $client->connect();
 * ```
 *
 * There are 4 base events:
 *  - CONNECTED     occurs when client is connected to the endpoint
 *  - DISCONNECTED  occurs when client lost connection
 *  - AUTHENTICATED occurs after successfull authentication
 *  - ERROR         occurs on each error
 *
 * Event Parametes
 * ------------------------------------------------
 *
 * **onConnected()**
 *  - not passed
 *
 * **onDisconnected($code, $message)**
 *  - int $code TCP socket error codes or internal application code
 *  - string $message Disconnect reason
 *
 * **onAuthenticated()**
 *  - not passed
 *
 * **onError($type, $error)**
 *  - ERROR_TYPE_APPLICATION|ERROR_TYPE_CONNECTION|ERROR_TYPE_AUTHENTICATION $type Error type
 *  - array $error Error details. Keys:
 *    - int $code Error code
 *    - string $message Error description
 *
 *
 * Authentication
 * =============================================
 *
 * You can specify role to get role-based permissions (E.g. get an access to
 * Subscribe/Publish to some channels) when connecting to the endpoint.
 * Follow the link to get more information: https://www.satori.com/docs/using-satori/authentication
 *
 * Use {@link \RtmClient\Auth\RoleAuth} to authenticate using role-based authentication:
 * ```php
 * $options = array(
 *     'auth' => new RtmClient\Auth\RoleAuth(ROLE, ROLE_SECRET_KEY),
 * );
 * $client = new RtmClient(ENDPOINT, APP_KEY, $options);
 * ```
 *
 * Subscriptions
 * =============================================
 *
 * RTM client allows to subscribe to channels:
 * ```
 * $client->subscribe('animals', function ($ctx, $type, $data) {
 *      print_r($data);
 * });
 * ```
 *
 * Check the {@link \RtmClient\Subscription\Subscription} class to get more information
 * about the possible options.
 *
 * A subscription callback is called when the following subscription events occur:
 * ```
 * SUBSCRIBED - after getting confirmation from Satori RTM about subscription
 * UNSUBSCRIBED - after successful unsubscribing
 * DATA - when getting rtm/subscription/data from Satori RTM
 * INFO - when getting rtm/subscription/info message
 * ERROR - on every rtm/subscription/error or rtm/subscribe/error
 * ```
 *
 * You should specify callback when creating a new subscription. Example:
 * ```
 * use RtmClient\Subscription\Events;
 *
 * $callback = function ($ctx, $type, $data) {
 *     switch ($type) {
 *         case Events::SUBSCRIBED:
 *             echo 'Subscribed to: ' . $ctx['subscription']->getSubscriptionId() . PHP_EOL;
 *             break;
 *         case Events::UNSUBSCRIBED:
 *             echo 'Unsubscribed from: ' . $ctx['subscription']->getSubscriptionId() . PHP_EOL;
 *             break;
 *         case Events::DATA:
 *             foreach ($data['messages'] as $message) {
 *                 if (isset($message['who']) && isset($message['where'])) {
 *                     echo 'Got animal ' . $message['who'] . ': ' . json_encode($message['where']) . PHP_EOL;
 *                 } else {
 *                     echo 'Got message: ' . json_encode($message) . PHP_EOL;
 *                 }
 *             }
 *             break;
 *         case Events::ERROR:
 *             echo 'Subscription failed. ' . $err['error'] . ': ' . $err['reason'] . PHP_EOL;
 *             break;
 *     }
 * };
 * $subscription = $client->subscribe('animals', $callback, array(
 *     'filter' => "SELECT * FROM `animals` WHERE who = 'zebra'",
 * ));
 * ```
 *
 * Read/Write workflow
 * =============================================
 *
 * Because of RtmClient has a **single-threaded** model you should alternate read and write operations
 *
 * Simple publish with ack example. We publish message and require acknowledge from
 * Sator RTM:
 * ```
 * $client = new RtmClient(ENDPOINT, APP_KEY);
 * $client->publish(CHANNEL, 'test message', function ($ack) {
 *      echo 'Got ack from Satori RTM';
 * });
 * $client->sockReadSync(); // Wait for reply from Satori RTM
 * ```
 *
 * In case if you do not want to wait too much time on reading use **timeout**:
 * ```
 * $client = new RtmClient(ENDPOINT, APP_KEY);
 * $client->publish(CHANNEL, 'test message', function ($ack) {
 *      echo 'Got ack from Satori RTM';
 * });
 * $client->sockReadSync(2); // Wait for incoming message for 2 seconds only
 * ```
 *
 * If you await multiple replies use {@see RtmClient::waitAllReplies()}
 * ```
 * $client = new RtmClient(ENDPOINT, APP_KEY);
 * $client->publish(CHANNEL, 'message', function ($ack) {
 *      echo 'Got ack 1 from Satori RTM';
 * });
 * $client->publish(CHANNEL, 'message-2', function ($ack) {
 *      echo 'Got ack 2 from Satori RTM';
 * });
 * $client->read(CHANNEL, function ($data) {
 *      echo 'Got read data from Satori RTM';
 * });
 * $client->waitAllReplies(); // Also you can specify wait timeout in seconds
 * echo 'Done!';
 *
 * // Output:
 * // Got ack 1 from Satori RTM
 * // Got ack 2 from Satori RTM
 * // Got read data from Satori RTM
 * // Done!
 * ```
 *
 * Also there is an **Async mode**. Reading in this mode means, that you will not be
 * blocked if there are no incoming messages in socket:
 * ```
 * use RtmClient\WebSocket\ReturnCode as RC;
 *
 * $client = new RtmClient(ENDPOINT, APP_KEY);
 * $client->publish(CHANNEL, 'test message', function ($ack) {
 *      echo 'Got ack from Satori RTM';
 * });
 * $code = $client->sockReadAsync();
 * switch ($code) {
 *  case RC::READ_OK:
 *      echo 'Read incoming message';
 *      break;
 *  case RC::READ_WOULD_BLOCK:
 *      echo 'There are no messages in socket at this moment';
 *      break;
 *  default:
 *      echo 'Another return code';
 * }
 * ```
 *
 * If you subscribe to the channels and want to publish messages in the same time you can
 * use sockReadAsync or Async helpers: {@see RtmClient::sockReadIncoming()} or {@see RtmClient::sockReadFor()}
 * ```
 * $messages_count = 0;
 * $client = new RtmClient(ENDPOINT, APP_KEY);
 * $client->subscribe(CHANNEL, function ($ctx, $type, $data) use (&$messages_count) {
 *     if ($type == Events::DATA) {
 *         foreach ($data['messages'] as $message) {
 *             echo 'Got message: ' . json_encode($message) . PHP_EOL;
 *             $messages_count++;
 *         }
 *     }
 * });
 *
 * while (true) {
 *      $client->sockReadFor(2); // Read possible messages for 2 seconds
 *      $client->publish(ANOTHER_CHANNEL, time(), function() {
 *          echo 'Sent time' . PHP_EOL;
 *      });
 *      $client->publish(MY_STAT_CHANNEL, $messages_count, function() {
 *          echo 'Sent messages count' . PHP_EOL;
 *      });
 * }
 * ```
 *
 * Reconnects
 * =============================================
 *
 * An RtmClient instance is a one-time connection. It means that you cannot continue using
 * client after connection is dropped.
 *
 * To make a new connection to Satori RTM you can clone previous client:
 * ```
 * $new_client = clone $old_client;
 * $new_client->connect();
 * ```
 *
 * All your callbacks will be moved to the new client. After calling `connect`
 * client will establish a new connection to Satori RTM.
 * Note that you need to restore your subscriptions manually.
 *
 * See *reconnects* examples.
 *
 * @example authenticate.php Authentication example
 * @example changing_subscription.php Change filter of existing subscription.
 * @example publish.php Publish example
 * @example reconnects_publish.php Continunously publish with processing disconnects.
 * @example reconnects_subscription.php Continunously publish and restore subscription on disconnects.
 * @example subscribe_to_channel.php Subscription example
 * @example test_installation.php Event handlers example
 */
class RtmClient extends Observable
{
    /**
     * Current supported version of RTM
     */
    const RTM_VER = 'v2';

    const CODE_OK    = 0;
    const CODE_ERROR = 1;

    const ERROR_TYPE_APPLICATION    = 'application';
    const ERROR_TYPE_CONNECTION     = 'connection';
    const ERROR_TYPE_AUTHENTICATION = 'authentication';

    const ERROR_CODE_EMPTY_ENDPOINT       = 1;
    const ERROR_CODE_EMPTY_APPKEY         = 2;
    const ERROR_CODE_NOT_AUTH_INTERFACE   = 3;
    const ERROR_CODE_UNKNOWN_SUBSCRIPTION = 4;
    const ERROR_CODE_NOT_CONNECTED        = 5;
    const ERROR_CODE_CLIENT_IN_USE        = 6;
    const ERROR_CODE_PERSISTENT_SUBSCRIBE = 7;

    /**
     * Connection instance
     *
     * @var Connection
     */
    protected $connection = null;

    /**
     * Any Auth\iAuth implementation
     *
     * @var Auth\iAuth
     */
    protected $auth = null;

    /**
     * Current RtmClient connection state
     *
     * @var boolean
     */
    protected $is_connected = false;

    /**
     * List of subscriptions
     *
     * @var array
     */
    protected $subscriptions = array();

    /**
     * PSR-3 Psr\Log\LoggerInterface implementation
     *
     * @var Psr\Log\LoggerInterface
     */
    protected $logger = null;

    /**
     * Sets to true when connected. Never resets to false after
     *
     * @var boolean
     */
    protected $once_connected = false;

    /**
     * Creates new RtmClient instance.
     *
     * @param string $endpoint Endpoint for RTM. Available from the Dev Portal
     * @param string $appkey Appkey used to access RTM. Available from the Dev Portal
     * @param array $options Additional parameters for the RTM client instance
     *
     *     $options = [
     *       'auth'         => (Auth\iAuth) Any instance that implements iAuth instance
     *       'logger'       => (\Psr\Log\LoggerInterface Custom logger
     *       'sub_protocol' => (string) Websocket sub-protocol. Ws::SUB_PROTOCOL_JSON | Ws::SUB_PROTOCOL_CBOR
     *     ]
     *
     * @throws ApplicationException if endpoint is empty
     * @throws ApplicationException if appkey is empty
     * @throws ApplicationException if Auth does not implement iAuth interface
     * @throws ApplicationException if wrong arguments count passed
     * @throws BadSchemeException if endpoint has bad schema
     */
    public function __construct($endpoint, $appkey, $options = array())
    {
        $default_options = array(
            'auth' => null,
            'logger' => new Logger(),
            'connection_id' => null,
            'sub_protocol' => Ws::SUB_PROTOCOL_JSON,
        );

        if (strlen($endpoint) == 0) {
            throw new ApplicationException(
                'Endpoint is empty', self::ERROR_CODE_EMPTY_ENDPOINT
            );
        }

        if (strlen($appkey) == 0) {
            throw new ApplicationException(
                'Appkey is empty', self::ERROR_CODE_EMPTY_APPKEY
            );
        }

        $this->options = array_merge($default_options, $options);

        // Set logger
        $this->logger = $this->options['logger'];

        $this->auth = $this->options['auth'];
        $this->connection_url = $this->buildConnectionUrl($endpoint, $appkey);
        $this->connection_id = $this->options['connection_id'];
        if (!is_null($this->auth) && !$this->auth instanceof Auth\iAuth) {
            throw new ApplicationException(
                'Auth must implement iAuth interface', self::ERROR_CODE_NOT_AUTH_INTERFACE
            );
        }
        $this->persistent_connection = false;
    }

    /**
     * Creates a new RtmClient instance with persistent connection or returns previously created instance.
     * Endpoint, appkey and optional connection_id is a key to check if instance has been previously created.
     *
     * Singleton.
     *
     * @param string $endpoint Endpoint for RTM. Available from the Dev Portal
     * @param string $appkey Appkey used to access RTM. Available from the Dev Portal
     * @param array $options Additional parameters for the RTM client instance
     *
     *     $options = [
     *       'auth'          => (Auth\iAuth) Any instance that implements iAuth instance
     *       'logger'        => (\Psr\Log\LoggerInterface Custom logger
     *       'connection_id' => (string) Connection identifier.
     *                          Provides ability to create different connections to the same endpoint
     *       'sub_protocol' => (string) Websocket sub-protocol. Ws::SUB_PROTOCOL_JSON | Ws::SUB_PROTOCOL_CBOR
     *     ]
     *
     * Usage:
     * ```
     *      $client = RtmClient::persistentConnection('wss://endpoint.satori.com', 'appkey1234', array(
     *          'connection_id' => 'connection1', // optional
     *      ));
     * ```
     *
     * @throws ApplicationException if endpoint is empty
     * @throws ApplicationException if appkey is empty
     * @throws ApplicationException if Auth does not implement iAuth interface
     * @throws ApplicationException if wrong arguments count passed
     * @throws BadSchemeException if endpoint has bad schema
     */
    public static function persistentConnection($endpoint, $appkey, $options = array())
    {
        static $instances = array();

        $connection_id = isset($options['connection_id']) ? $options['connection_id'] : '';
        $hash = self::buildConnectionUrl($endpoint, $appkey) . '#' . $connection_id;

        if (!isset($instances[$hash])) {
            $instances[$hash] = new RtmClient($endpoint, $appkey, $options);
            $instances[$hash]->persistent_connection = true;
        }

        return $instances[$hash];
    }

    /**
     * Creates new RtmClient instance using heritable client.
     * Uses previously added client callbacks and subscriptions.
     */
    public function __clone()
    {
        // We need to cleanup several properties from previous client
        $this->is_connected = $this->once_connected = false;

        // Cleanup current subscriptions
        $this->subscriptions = array();
    }

    /**
     * Establishes connection to endpoint.
     *
     * Upgrades connection to WebSocket
     * See throubleshoots section in the README.md file if you failed to connect to an endpoint
     *
     * @throws ApplicationException if client was connected before
     * @return bool true if connection has been established, false otherwise
     */
    public function connect()
    {
        $allow_reconnects = $this->persistent_connection || !$this->once_connected;
        if (!$allow_reconnects) {
            throw new ApplicationException(
                'Client is in use', self::ERROR_CODE_CLIENT_IN_USE
            );
        }

        $this->connection = new Connection($this->connection_url, array(
            'logger' => $this->logger,
            'on_unsolicited_pdu' => function ($pdu) {
                if (strncmp($pdu->action, 'rtm/subscription', 16) === 0) {
                    $this->processSubscriptionRequests($pdu);
                } elseif ($pdu->action == '/error') {
                    $status = 1008;
                    $reason = 'Unclassified RTM error is received: ' . $pdu->body['error'] . ' - ' . $pdu->body['reason'];
                    throw new ConnectionException(
                        $reason, $status
                    );
                }
            },
            'persistent_connection' => $this->persistent_connection,
            'connection_id' => $this->connection_id,
            'sub_protocol' => $this->options['sub_protocol'],
        ));

        $this->logger->info('Client: Connecting to endpoint');
        $this->logger->debug('  ' . $this->connection_url);

        try {
            $this->connection->connect();
        } catch (ConnectionException $e) {
            $this->Fire(RtmEvents::ERROR, self::ERROR_TYPE_CONNECTION, array(
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ));
            return false;
        }

        if (!is_null($this->auth)) {
            try {
                $this->auth->authenticate($this->connection);
                $this->Fire(RtmEvents::AUTHENTICATED);
            } catch (AuthenticationException $e) {
                $this->Fire(RtmEvents::ERROR, self::ERROR_TYPE_AUTHENTICATION, array(
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                ));
                return false;
            }
        }

        $this->is_connected = $this->once_connected = true;
        $this->Fire(RtmEvents::CONNECTED);

        if (!empty($this->subscriptions)) {
            foreach ($this->subscriptions as $sub) {
                $this->subscribe($sub->getSubscriptionId(), $sub->getCallback(), $sub->getOptions());
            }
        }

        return true;
    }

    /**
     * Closes connection.
     *
     * @param integer $status Close status code.
     * @param string $reason Any message that will be send in close frame.
     *
     * @return void
     */
    public function close($status = 1000, $reason = 'Connection closed')
    {
        if ($this->connection->close($status, $reason)) {
            $this->logger->info('Client: disconnected');

            $this->is_connected = false;
            $this->disconnectAllSubscriptions();

            $this->fire(RtmEvents::DISCONNECTED, $status, $reason);
        }
    }

    /**
     * Publishes a message to a channel.
     * The RtmClient client must be connected.
     *
     * Example:
     * ```
     * $animal = array(
     *      'who' => 'zebra',
     *      'where' => [34.134358, -118.321506],
     * );
     * $client->publish('animals', $animal, function ($code, $response) {
     *      if ($code == RtmClient::CODE_OK) {
     *          echo 'Publish confirmed!' . PHP_EOL;
     *      } else {
     *          echo 'Failed to publish. Error: ' . $response['error'] . '; Reason: ' . $response['reason'] . PHP_EOL;
     *      }
     * });
     * ```
     *
     * @param string $channel Channel name
     * @param mixed $message Any type that can be serialized via json_encode
     * @param callable $callback Function to attach and execute on the response PDU from
     *                           RTM. The response PDU body is passed as a parameter to this function.
     *                           RTM does not send a response PDU if a callback is not specified.
     * @param array $extra Additional request options. These extra values are sent to
     *                     RTM in the **body** element of the
     *                     PDU that represents the request.
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return true if message has been sent, false otherwise
     */
    public function publish($channel, $message, callable $callback = null, $extra = array())
    {
        $body = array(
            'channel' => $channel,
            'message' => $message,
        );
        $body = array_merge($extra, $body);
        return $this->socketSend('rtm/publish', $body, $this->processCallback($callback));
    }

    /**
     * Writes a value to the specified channel.
     * The RtmClient client must be connected.
     *
     * @param string $channel Channel name
     * @param mixed $message Any type that can be serialized via json_encode
     * @param callable $callback Function to attach and execute on the response PDU from
     *                           RTM. The response PDU body is passed as a parameter to this function.
     *                           RTM does not send a response PDU if a callback is not specified.
     * @param array $extra Additional request options. These extra values are sent to
     *                     RTM in the **body** element of the
     *                     PDU that represents the request.
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return true if message has been sent, false otherwise
     */
    public function write($channel, $message, callable $callback = null, $extra = array())
    {
        $body = array(
            'channel' => $channel,
            'message' => $message,
        );
        $body = array_merge($extra, $body);
        return $this->socketSend('rtm/write', $body, $this->processCallback($callback));
    }

    /**
     * Reads the latest message written to specific channel.
     * The RtmClient client must be connected.
     *
     * Example:
     * ```
     * $client->read('animals', function ($code, $body) {
     *      if ($code == RtmClient::CODE_OK) {
     *          echo $body['message'];
     *      }
     * });
     * ```
     *
     * @param string $channel Channel name
     * @param callable $callback Function to attach and execute on the response PDU from
     *                           RTM. The response PDU body is passed as a parameter to this function.
     *                           RTM does not send a response PDU if a callback is not specified.
     * @param array $extra Additional request options. These extra values are sent to
     *                     RTM in the **body** element of the
     *                     PDU that represents the request.
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return true if read PDU has been sent to RTM, false otherwise
     */
    public function read($channel, callable $callback, $extra = array())
    {
        $body = array(
            'channel' => $channel,
        );
        $body = array_merge($extra, $body);
        return $this->socketSend('rtm/read', $body, $this->processCallback($callback));
    }

    /**
     * Deletes the value for the associated channel.
     * The RtmClient client must be connected.
     *
     * @param string $channel Channel name
     * @param callable $callback Function to attach and execute on the response PDU from
     *                           RTM. The response PDU body is passed as a parameter to this function.
     *                           RTM does not send a response PDU if a callback is not specified.
     * @param array $extra Additional request options. These extra values are sent to
     *                     RTM in the **body** element of the
     *                     PDU that represents the request.
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return true if delete PDU has been sent to RTM, false otherwise
     */
    public function delete($channel, callable $callback = null, $extra = array())
    {
        $body = array(
            'channel' => $channel,
        );
        $body = array_merge($extra, $body);
        return $this->socketSend('rtm/delete', $body, $this->processCallback($callback));
    }

    /**
     * Creates a subscription to the specified channel.
     *
     * When you create a channel subscription, you can specify additional properties,
     * for example, add a filter to the subscription and specify the
     * behavior of the SDK when resubscribing after a reconnection.
     *
     * For more information about the options for a channel subscription,
     * see **Subscribe PDU** in the online docs.
     *
     * Simple example:
     * ```
     * $client = new RtmClient(ENDPOINT, APP_KEY, array(
     *    'auth' => new RoleAuth(ROLE, ROLE_SECRET_KEY),
     * ));
     * $client->connect() or die;
     *
     * $subscription = $client->subscribe('animals');
     * $subscription->onData(function ($data) {
     *      foreach ($data['messages'] as $message) {
     *          echo 'Got message: ' . json_encode($message) . PHP_EOL;
     *      }
     * });
     * ```
     *
     * Subscribe with filter/view (Stream SQL):
     * ```
     * $client = new RtmClient(ENDPOINT, APP_KEY, array(
     *    'auth' => new RoleAuth(ROLE, ROLE_SECRET_KEY),
     * ));
     * $client->connect() or die;
     *
     * $subscription = $client->subscribe('animals', array(
     *      'filter' => "SELECT * FROM `animals` WHERE who = 'zebra'",
     * ))->onData(function ($data) {
     *      foreach ($data['messages'] as $message) {
     *          echo 'Got message: ' . json_encode($message) . PHP_EOL;
     *      }
     * });
     * ```
     *
     * @param string $subscription_id String that identifies the channel. If you do not
     *               use the **filter** parameter, it is the channel name. Otherwise,
     *               it is a unique identifier for the channel (subscription id).
     * @param callable $callback Custom callback. Such callback will be called on any subscription events,
     *                 described in {@see RtmClient\Subscription\Events}
     *                 Callback function will get 3 arguments:
     *                      $ctx - Context. Current subscription instance
     *                      $type - Event type: {@see RtmClient\Subscription\Events}
     *                      $data - Type-related data. Check Protocol Data Unit (PDU)
     *                           to get information about data content
     * @param array $options Additional subscription options for a channel subscription. These options
     *              are sent to RTM in the **body** element of the
     *              Protocol Data Unit (PDU) that represents the subscribe request.
     *              For more information about the **body** element of a PDU,
     *              see **RTM API** in the online docs.
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @throws ApplicationException when using persistent connection
     * @return void
     *
     * @example subscribe_to_channel.php Subscribe to channel
     */
    public function subscribe($subscription_id, callable $callback, $options = array())
    {
        if ($this->persistent_connection) {
            throw new ApplicationException(
                'It is forbidden to subscribe when using persistent connection',
                self::ERROR_CODE_PERSISTENT_SUBSCRIBE
            );
        }

        $subscription = new Subscription($subscription_id, $callback, $options);
        $subscription->setLogger($this->logger);
        $subscription->setContext('client', $this);

        $sub_pdu = $subscription->subscribePdu();

        $res = $this->socketSend($sub_pdu->action, $sub_pdu->body, function (Pdu $pdu) use ($subscription) {
            if (PduHelper::pduResponseCode($pdu) === PduRC::CODE_OK_REQUEST) {
                $this->subscriptions[$pdu->body['subscription_id']] = $subscription;
            }
            $subscription->onPdu($pdu);
        });
    }

    /**
     * Unsubscribes the specified subscription.
     *
     * @param string $subscription_id Subscription id or channel name.
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return self::ERROR_CODE_UNKNOWN_SUBSCRIPTION if no subscription found.
     *          true if Unsubscribe PDU has been sent,
     *          false otherwise.
     */
    public function unsubscribe($subscription_id)
    {
        if (!isset($this->subscriptions[$subscription_id])) {
            return self::ERROR_CODE_UNKNOWN_SUBSCRIPTION;
        }
        $subscription = $this->subscriptions[$subscription_id];
        $unsub_pdu = $subscription->unsubscribePdu();

        return $this->socketSend($unsub_pdu->action, $unsub_pdu->body,
        function (Pdu $pdu) use ($subscription_id, $subscription) {
            if (PduHelper::pduResponseCode($pdu) === PduRC::CODE_OK_REQUEST) {
                $this->subscriptions[$subscription_id]->onPdu($pdu);
                unset($this->subscriptions[$subscription_id]);
            } elseif (isset($pdu->body['error']) && $pdu->body['error'] == 'disconnected') {
                unset($this->subscriptions[$subscription_id]);
            } else {
                $subscription->processUnsubscribeError($pdu->body);
            }
        });
    }

    /**
     * Gets list of current Subscriptions.
     *
     * @return Subscription[]
     */
    public function getSubscriptions()
    {
        return $this->subscriptions;
    }

    /**
     * Gets subscription by the ID.
     *
     * @param string $subscription_id Subscription id or channel name
     * @return Subscription|null null if subscription not found
     */
    public function getSubscription($subscription_id)
    {
        return isset($this->subscriptions[$subscription_id]) ? $this->subscriptions[$subscription_id] : null;
    }

    /**
     * Reads all messages that already in the incoming buffer in Async mode.
     * It means reading message until Error or until no more messages in the buffer.
     *
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @throws ApplicationException if failed to parse incoming json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     * @return void
     */
    public function sockReadIncoming()
    {
        while (true) {
            $code = $this->sockReadAsync();
            if ($code != SocketRC::READ_OK) {
                break;
            }
        }
    }

    /**
     * Reads one message from the socket in Async mode.
     *
     * BE AWARE: Async mode is using only to determine if the incoming buffer contains any information.
     * It means that you WILL NOT BE blocked if no data is in it.

     * BUT SDK still uses Sync mode to read a whole WebSocket frame.
     * It means that you WILL BE blocked if incoming buffer has only part of the WebSocket frame.
     *
     * @TODO: SDK will support full Async mode in next versions
     *
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @throws ApplicationException if failed to parse incoming json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     * @return SocketRC|false false if not connected
     */
    public function sockReadAsync()
    {
        return $this->sockRead(Ws::ASYNC_READ);
    }

    /**
     * Reads one message from the socket in Sync mode.
     * Application will be blocked until the message arrives.
     * @param int $timeout_sec The seconds part of the timeout to be set
     * @param int $timeout_microsec The microseconds part of the timeout to be set
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @throws ApplicationException if failed to parse incoming json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     * @return SocketRC|false false if not connected
     */
    public function sockReadSync($timeout_sec = 0, $timeout_microsec = 0)
    {
        return $this->sockRead(Ws::SYNC_READ, $timeout_sec, $timeout_microsec);
    }

    /**
     * Waits for all ack responses from Satori RTM.
     *
     * @param int $timeout_sec The seconds part of the maximal awaiting time to be set
     * @param int $timeout_microsec The microseconds part of the maximal awaiting time to be set
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @throws ApplicationException if failed to parse incoming json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     * @return void
     */
    public function waitAllReplies($timeout_sec = 0, $timeout_microsec = 0)
    {
        $this->checkConnected('waitAllReplies');
        $this->connection->waitAllReplies($timeout_sec, $timeout_microsec);
    }

    /**
     * Reads all incoming messages for specified period of time.
     *
     * @param int $seconds The seconds part of the maximal reading time to be set
     * @param int $microseconds The microseconds part of the maximal reading time to be set
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @throws ApplicationException if failed to parse incoming json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     * @return void
     */
    public function sockReadFor($seconds, $microseconds = 0)
    {
        $this->checkConnected('sockReadFor');

        list($usec, $sec) = explode(' ', microtime());
        $max_timestamp = (float)$sec + $seconds + (float)$usec + $microseconds / 1000000;

        do {
            $this->sockReadSync($seconds, $microseconds);

            list($usec, $sec) = explode(' ', microtime());
            $current_timestamp = (float)$sec + (float)$usec;
            $diff_timestamp = $max_timestamp - $current_timestamp;
            $seconds = intval($diff_timestamp);
            $microseconds = intval(($diff_timestamp - $seconds) * 1000000);
        } while ($current_timestamp <= $max_timestamp);
    }

    /**
     * Constructs connection URL using endpoint, appkey and optional hash.
     *
     * @param string $endpoint Server URL with schema
     * @param string $appkey Application key
     * @param string $hash URL hash. Uses for persistent connections
     * @return string
     */
    protected static function buildConnectionUrl($endpoint, $appkey, $hash = '')
    {
        $endpoint = self::appendVersion($endpoint);
        $url = $endpoint . '?appkey=' . $appkey;

        if (!empty($hash)) {
            $url .= '#' . $hash;
        }

        return $url;
    }

    /**
     * Reads from socket connection.
     *
     * @param Ws::ASYNC_READ|Ws::SYNC_READ $mode Read mode
     * @param int $timeout_sec The seconds part of the timeout to be set
     * @param int $timeout_microsec The microseconds part of the timeout to be set
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @throws ApplicationException if failed to parse incoming json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     * @return SocketRC|false false if not connected
     */
    protected function sockRead($mode, $timeout_sec = 0, $timeout_microsec = 0)
    {
        $this->checkConnected('read');

        $code = SocketRC::READ_ERROR;

        try {
            list($code, $pdu) = $this->connection->read($mode, $timeout_sec, $timeout_microsec);

            if (in_array($code, array(SocketRC::READ_ERROR, SocketRC::NOT_CONNECTED))) {
                $this->logger->error('Unable to read from socket. Error: ' . $code);
            } elseif ($code === SocketRC::CLOSED) {
                throw new ConnectionException(
                    $pdu['payload'], $pdu['status']
                );
            }
        } catch (\Exception $e) {
            $this->processException($e);
            throw $e;
        }

        return $code;
    }

    /**
     * Sends PING to server: https://tools.ietf.org/html/rfc6455#section-5.5.2
     *
     * @param string $text Text to be send as ping payload
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return true if sent
     */
    public function sendWebSocketPing($text = 'ping')
    {
        $this->checkConnected('ping');

        try {
            $this->connection->sendPing($text);
        } catch (\Exception $e) {
            $this->processException($e);
            throw $e;
        }

        return true;
    }

    /* ================================================
     * Events helpers
     * ===============================================*/

    /**
     * Shorthand for on(RtmEvents::CONNECTED).
     *
     * Called when connection has been established and authentication (optional) is completed.
     * If connection type is persistent the event will be called after getting previously
     * established connection.
     *
     * callback function params:
     *  - none
     *
     * @param callable $callback
     * @return $this
     */
    public function onConnected(callable $callback)
    {
        $this->on(RtmEvents::CONNECTED, $callback);
        return $this;
    }

    /**
     * Shorthand for on(RtmEvents::DISCONNECTED).
     *
     * callback function params ($code, $message):
     *  - int $code TCP socket error codes or internal application code
     *  - string $message Disconnect reason
     *
     * @param callable $callback
     * @return $this
     */
    public function onDisconnected(callable $callback)
    {
        $this->on(RtmEvents::DISCONNECTED, $callback);
        return $this;
    }

    /**
     * Shorthand for on(RtmEvents::AUTHENTICATED).
     *
     * callback function params:
     *  - none
     *
     * @param callable $callback
     * @return $this
     */
    public function onAuthenticated(callable $callback)
    {
        $this->on(RtmEvents::AUTHENTICATED, $callback);
        return $this;
    }

    /**
     * Shorthand for on(RtmEvents::ERROR).
     *
     * callback function params ($type, $error):
     *  - ERROR_TYPE_APPLICATION|ERROR_TYPE_CONNECTION|ERROR_TYPE_AUTHENTICATION $type Error type
     *  - array $error Error details. Keys:
     *    - int $code Error code
     *    - string $message Error description
     *
     * @param callable $callback
     * @return $this
     */
    public function onError(callable $callback)
    {
        $this->on(RtmEvents::ERROR, $callback);
        return $this;
    }

    /**
     * Returns current connection status.
     *
     * @return boolean true if connected, false otherwise
     */
    public function isConnected()
    {
        return $this->is_connected;
    }

    /* ================================================
     * Internal methods
     * ===============================================*/

    /**
     * Sends PDU to socket connection.
     *
     * @param string $action PDU action
     * @param array $body PDU body
     * @param callable $callback user callback on getting response from Satori RTM
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return true if successfully sent the PDU, false otherwise
     */
    protected function socketSend($action, $body, callable $callback = null)
    {
        $this->checkConnected($action);

        try {
            $this->connection->send($action, $body, $callback);
        } catch (\Exception $e) {
            $this->processException($e);
            throw $e;
        }

        return true;
    }

    /**
     * Acts on possible exceptions.
     *
     * @param \Exception $e Instance of Exception
     * @return void
     */
    protected function processException($e)
    {
        $this->is_connected = false;
        $this->disconnectAllSubscriptions();
        $exception_type = 'EXCEPTION';

        if ($e instanceof ConnectionException || $e instanceof TimeoutException) {
            $exception_type = self::ERROR_TYPE_CONNECTION;
        } elseif ($e instanceof ApplicationException) {
            $exception_type = self::ERROR_TYPE_APPLICATION;
        }
        $this->logger->error($exception_type . '. Code: ' . $e->getCode() . '; Message: ' . $e->getMessage());
        $this->fire(RtmEvents::ERROR, $exception_type, array(
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
        ));
        $this->fire(RtmEvents::DISCONNECTED, $e->getCode(), $e->getMessage());

        $this->connection->close($e->getMessage());
    }

    /**
     * Checks if RtmClient is connected.
     * Fires ERROR with {@linksee RtmClient::ERROR_TYPE_APPLICATION} if not connected.
     *
     * @param string $action Which action checks the connection
     * @return true if connected, false otherwise
     */
    protected function checkConnected($action)
    {
        if (!$this->is_connected) {
            $this->logger->error('Failed to execute action: ' . $action . '. Client is not connected');

            $this->fire(RtmEvents::ERROR, self::ERROR_TYPE_APPLICATION, array(
                'code' => RtmClient::ERROR_CODE_NOT_CONNECTED,
                'message' => 'Failed to execute action: ' . $action . '. Client is not connected',
            ));

            throw new ApplicationException(
                'Not connected', self::ERROR_CODE_NOT_CONNECTED
            );
        }

        return true;
    }

    /**
     * Processes all incoming PDUs which has 'subscription_id' field.
     *
     * @param Pdu $pdu
     * @return true if we matched subscription_id in PDU with Subscription
     *              in internal subscriptions, false otherwise
     */
    protected function processSubscriptionRequests(Pdu $pdu)
    {
        if (isset($this->subscriptions[$pdu->body['subscription_id']])) {
            $subscription = $this->subscriptions[$pdu->body['subscription_id']];
            $subscription->onPdu($pdu);
            return true;
        }
        return false;
    }

    /**
     * Calls disconnect action for all subscriptions.
     *
     * @return void
     */
    protected function disconnectAllSubscriptions()
    {
        foreach ($this->subscriptions as $subscription) {
            $subscription->processDisconnect();
        }
    }

    /**
     * Wraps user callback function to simplify return codes.
     *
     * Converts {@link PduRC} codes to
     * {@link RtmClient::CODE_ERROR} and {@link RtmClient::CODE_OK}
     *
     * @param callable $callback User callback function
     * @return callable callback wrapper
     */
    protected function processCallback($callback)
    {
        $wrapper = null;

        if (is_callable($callback)) {
            $wrapper = function ($pdu) use ($callback) {
                $rc = self::CODE_OK;
                $body = null;

                if (is_null($pdu)) {
                    // Connection closed unexpectedly
                    $rc = self::CODE_ERROR;
                } elseif (PduHelper::pduResponseCode($pdu) !== PduRC::CODE_OK_REQUEST) {
                    $rc = self::CODE_ERROR;
                    $body = $pdu->body;
                } else {
                    $body = $pdu->body;
                }

                $callback($rc, $body);
            };
        }

        return $wrapper;
    }

    /**
     * Appends version param to the endpoint.
     *
     * ```
     * Before: wss://some.endpoint.com
     * After: wss://some.endpoint.com/v2
     * ```
     *
     * @param string $endpoint Custom endpoint.
     * @return string Endpoint with added RTM_VER.
     */
    protected static function appendVersion($endpoint)
    {
        if (preg_match('#v(?:\d+)$#', $endpoint, $matches)) {
            return $endpoint;
        }

        if (substr($endpoint, -1) != '/') {
            $endpoint .= '/';
        }

        return $endpoint . self::RTM_VER;
    }
}
