<?php

namespace RtmClient;

use RtmClient\Logger\Logger;
use RtmClient\Pdu\Helper as PduHelper;
use RtmClient\Pdu\Pdu;

use RtmClient\WebSocket\Client as Ws;
use RtmClient\WebSocket\ReturnCode as RC;
use RtmClient\WebSocket\OpCode;
use RtmClient\WebSocket\Exceptions\BadSchemeException;
use RtmClient\WebSocket\Exceptions\ConnectionException;

/**
 * Satori RTM Connection.
 *
 * Access the RTM Service on the connection level to connect to the RTM Service, send and receive PDUs
 * Uses WebSocket client to connect to RTM.
 *
 * Does not handle any possible exceptions from the WS client.
 */
class Connection
{
    /**
     * Connection endpoint
     *
     * @var string
     */
    protected $endpoint;

    /**
     * Internal ID. Used to mark PDU requests
     *
     * @var integer
     */
    protected $last_id = 0;

    /**
     * WS client instance
     *
     * @var \Rtm\WebSocket\Client
     */
    protected $ws;

    /**
     * List of callbacks. Callback will be executed when getting PDU with ID matched to the callback
     *
     * @var array
     */
    protected $callbacks = array();

    /**
     * Creates \Rtm\Connection instance.
     *
     * @param string $endpoint
     * @param array $options Connection instance options
     *
     *     $options = [
     *       'logger' => (\Psr\Log\LoggerInterface Custom logger
     *     ]
     *
     * @throws BadSchemeException when endpoint schema is not starting from https://, http://, ws://, wss://
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     */
    public function __construct($endpoint, $options = array())
    {
        $default_options = array(
            'logger' => new Logger(),
            'on_unsolicited_pdu' => null,
            'connection_id' => null,
        );

        $options = array_merge($default_options, $options);
        $this->endpoint = $endpoint;
        $this->logger = $options['logger'];
        $this->on_unsolicited_pdu = $options['on_unsolicited_pdu'];

        try {
            $this->ws = new Ws($endpoint, $options);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }
    }

    /**
     * Starts connection to the endpoint.
     *
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return true
     */
    public function connect()
    {
        try {
            $this->ws->connect();
        } catch (ConnectionException $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }

        if ($this->isReusedPersistentConnection()) {
            $this->last_id = rand(0, PHP_INT_MAX);
        }
        return true;
    }
 
    /**
     * Forms and sends PDU using websocket connection.
     *
     * @param string $action PDU action
     * @param array $body PDU body
     * @param callable $callback callback to be called when getting back request confirmation from RTM
     * @throws ConnectionException when connection is broken, unable to connect or send/read from the connection
     * @return always true
     */
    public function send($action, $body, callable $callback = null)
    {
        $id = null;

        if (is_callable($callback)) {
            $id = $this->nextId();
            $this->callbacks[$id] = $callback;
        }

        $pdu = new Pdu(
            $action,
            $body,
            $id
        );
        $json = $pdu->stringify();
        $this->logger->debug('SEND> ' . $json);
        
        try {
            $this->ws->send($json);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }

        return true;
    }

    /**
     * Reads data from the connection and converts to PDU.
     *
     * @param int $mode \Rtm\WebSocket\Client::SYNC_READ or \Rtm\WebSocket\Client::ASYNC_READ
     * @param int $timeout_sec The seconds part of the timeout to be set
     * @param int $timeout_microsec The microseconds part of the timeout to be set
     * @throws \Exception possible exceptions from user callbacks
     * @throws ConnectionException when connection is broken, unable to read from the connection
     * @throws ApplicationException if failed to parse incoming json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     * @return array Combined \Rtm\WebSocket\ReturnCode and Rtm\Pdu\Pdu
     */
    public function read($mode, $timeout_sec = 0, $timeout_microsec = 0)
    {
        $pdu = null;

        try {
            list($code, $data) = $this->ws->read($mode, $timeout_sec, $timeout_microsec);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }

        if ($code == RC::READ_OK) {
            $this->logger->debug('RECV< ' . $data);
            $pdu = PduHelper::convertToPdu($data);

            if (isset($this->callbacks[$pdu->id])) {
                $callback = $this->callbacks[$pdu->id];
                unset($this->callbacks[$pdu->id]);
                $callback($pdu);
            } elseif (!is_null($this->on_unsolicited_pdu)) {
                $func = $this->on_unsolicited_pdu;
                $func($pdu);
            }
        } elseif ($code == RC::CLOSED || $code == RC::PONG) {
            $this->logger->debug('RECV< ' . $code . ' frame (' . json_encode($data) . ')');
            $pdu = $data;
        }
        return array($code, $pdu);
    }

    /**
     * Closes connection.
     *
     * @param integer $status Close status code
     * @param string $reason Any message that will be send in close frame. All unprocessed callbacks will get this reason.
     * @return true if websocket connection has been closed
     */
    public function close($status = 1000, $reason = 'Connection closed')
    {
        $this->closeCallbacks($reason);
        return $this->ws->close($status, $reason);
    }

    /**
     * Sends PING to server: https://tools.ietf.org/html/rfc6455#section-5.5.2
     *
     * @param string $text Text to be send as ping payload
     * @return true if sent
     */
    public function sendPing($text = 'ping')
    {
        try {
            $this->ws->send($text, true, OpCode::PING);
            $this->logger->debug('SEND> PING frame');
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw $e;
        }

        return true;
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
        list($usec, $sec) = explode(' ', microtime());
        $max_timestamp = (float)$sec + $timeout_sec + (float)$usec + $timeout_microsec / 1000000;

        while (count($this->callbacks) > 0) {
            $this->read(Ws::SYNC_READ, $timeout_sec, $timeout_microsec);

            list($usec, $sec) = explode(' ', microtime());
            $current_timestamp = (float)$sec + (float)$usec;
            $diff_timestamp = $max_timestamp - $current_timestamp;
            $timeout_sec = intval($diff_timestamp);
            $timeout_microsec = intval(($diff_timestamp - $timeout_sec) * 1000000);

            if ($current_timestamp > $max_timestamp) {
                break;
            }
        }
    }

    /**
     * Checks if the connection is persistent and was reused.
     *
     * @return boolean true if the connection is persistent and was reused.
     *                 false otherwise
     */
    public function isReusedPersistentConnection()
    {
        return $this->ws->isReusedPersistentConnection();
    }

    /**
     * Generates next ID for PDU.
     *
     * @return int Next id
     */
    protected function nextId()
    {
        if ($this->last_id >= PHP_INT_MAX) {
            $this->last_id = 0;
        }

        return (string)++$this->last_id;
    }

    /**
     * Closes awating callbacks.
     * Sends "disconnected" error to all callbacks that await for the response
     * from Satori RTM.
     *
     * @param string $reason Disconnect reason
     * @return void
     */
    protected function closeCallbacks($reason)
    {
        foreach ($this->callbacks as $callback) {
            $error = new Pdu(
                '/error',
                array(
                    'error' => 'disconnected',
                    'reason' => $reason,
                )
            );
            $callback($error);
        }
        $this->callbacks = array();
    }
}
