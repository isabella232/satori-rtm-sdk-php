<?php
/**
 * Webocket client implementation based on: https://github.com/Textalk/websocket-php
 */

namespace RtmClient\WebSocket;

use RtmClient\WebSocket\Exceptions\BadSchemeException;
use RtmClient\WebSocket\Exceptions\ConnectionException;
use RtmClient\WebSocket\Exceptions\TimeoutException;

use RtmClient\WebSocket\ReturnCode as RC;

// TODO: Add Proxy connection
// TODO: Add auto-ping

/**
 * WebSocket client implementation.
 */
class Client
{
    /**
     * Number of seconds until the connect() system call should timeout
     */
    const DEFAULT_TIMEOUT_SEC = 30; // 30 seconds

    /**
     * Split message to frames when exceeded fragment size limit
     */
    const DEFAULT_FRAGMENT_SIZE = 4096;

    const WEBSOCKET_MAGIC_KEY = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'; // https://tools.ietf.org/html/rfc4648

    /**
     * Sync mode code
     */
    const SYNC_READ = 1;
    /**
     * Async mode code
     */
    const ASYNC_READ = 2;

    /**
     * stream_socket_client instance
     *
     * @var resource
     */
    protected $socket;

    /**
     * Current client state
     *
     * @var bool
     */
    protected $is_connected = false;

    /**
     * Socket state after call close()
     *
     * @var boolean
     */
    protected $is_closing = false;

    /**
     * Creates new WebSocket client.
     *
     * @param string $url Endpoint URL with schema
     * @param array $options Websocket client options
     *
     *     $options = [
     *       'timeout'       => (int) Number of seconds until the connect() system call should timeout
     *       'fragment_size' => (int) Split message to frames when exceeded fragment size limit
     *     ]
     */
    public function __construct($url, $options = array())
    {
        $default = array(
            'timeout' => Client::DEFAULT_TIMEOUT_SEC,
            'fragment_size' => Client::DEFAULT_FRAGMENT_SIZE,
        );

        $this->options = array_merge($default, $options);
        $this->url = $this->parseUrl($url);
    }

    /**
     * Closes socket connection and frees resources.
     */
    public function __destruct()
    {
        if ($this->socket) {
            if (get_resource_type($this->socket) === 'stream') {
                fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /**
     * Establishes connection to the endpoint and upgrades connection to WebSocket.
     *
     * @throws ConnectionException if failed to establish connection to the endpoint
     * @throws ConnectionException if server sent invalid upgrade response
     * @throws ConnectionException if unable to decode Sec-WebSocket-Accept key
     * @throws ConnectionException if Sec-WebSocket-Accept key was wrong
     * @return void
     */
    public function connect()
    {
        $endpoint = $this->url['socket_scheme'] . '://' . $this->url['host'] . ':' . $this->url['port'];
        $context = stream_context_create();

        if (getenv('SSL_CA_FILE') !== false || getenv('SSL_CA_PATH') !== false || getenv('SSL_VERIFY_PEER') !== false) {
            $ssl_opts = array();
            if (($ssl_cafile = getenv('SSL_CA_FILE')) !== false) {
                $ssl_opts['ssl']['cafile'] = $ssl_cafile;
            }
            if (($ssl_cafile = getenv('SSL_CA_FILE')) !== false) {
                $ssl_opts['ssl']['cafile'] = $ssl_cafile;
            }
            if (($ssl_verify_peer = getenv('SSL_VERIFY_PEER')) !== false) {
                $verify_peer = in_array(strtolower($ssl_verify_peer), array('y', 'true', '1')) ? true : false;
                $ssl_opts['ssl']['verify_peer'] = $verify_peer;
            }
            $context = @stream_context_create($ssl_opts);
        }

        $this->socket = @stream_socket_client(
            $endpoint,
            $errno,
            $errstr,
            $this->options['timeout'],
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($this->socket === false) {
            throw new ConnectionException(
                'Failed establish connection to ' . $this->url['host'] . ':' .
                $this->url['port'] . ": $errstr ($errno)", $errno
            );
        }

        stream_set_timeout($this->socket, $this->options['timeout']);

        $key = self::generateSecKey();

        $headers = array(
            'Host: ' . $this->url['host'] . ":" . $this->url['port'],
            'Connection: Upgrade',
            'Upgrade: websocket',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
        );

        $this->socketWrite(
            'GET ' . $this->url['path'] . '?' . $this->url['query'] . " HTTP/1.1\r\n"
            . implode("\r\n", $headers)
            . "\r\n\r\n"
        );

        $response = stream_get_line($this->socket, 1024, "\r\n\r\n");
        
        if (preg_match('#Sec-WebSocket-Accept:\s(.+)$#mUi', $response, $matches)) {
            $server_key = $matches[1];
            $decoded = base64_decode($server_key);

            if ($decoded === false) {
                throw new ConnectionException(
                   'WebSocket Upgrade Failure. Unable to decode Sec-WebSocket-Accept key:' . PHP_EOL
                    . $response, 1001
                );
            }

            if ($decoded != sha1($key . self::WEBSOCKET_MAGIC_KEY, true)) {
                throw new ConnectionException(
                   'WebSocket Upgrade Failure. Wrong Sec-WebSocket-Accept key:' . PHP_EOL
                    . $response, 1002
                );
            }
        } else {
            throw new ConnectionException(
               'WebSocket Upgrade Failure. Server sent invalid upgrade response:' . PHP_EOL
                . $response, 1003
            );
        }
        $this->is_connected = true;
    }

    /**
     * Closes connection.
     * Sends "close" frame.
     *
     * @param integer $status Close status code
     * @param string $message Any message that will be send in close frame
     * @param int $timeout Wait for response timeout
     * @return array read code and socket frame payload
     *
     *     $return = [
     *       '0' => (RtmClient\WebSocket\ReturnCode) Read result
     *       '1' => (string) Close frame payload
     *     ]
     */
    public function close($status = 1000, $message = null, $timeout = self::DEFAULT_TIMEOUT_SEC)
    {
        $status_binstr = sprintf('%016b', $status);
        $status_str = '';
        foreach (str_split($status_binstr, 8) as $binstr) {
            $status_str .= chr(bindec($binstr));
        }
        $this->send($status_str . $message, true, OpCode::CLOSE);
        $this->is_closing = true;

        // Receiving a close frame will close the socket
        return $this->read(self::SYNC_READ, $timeout);
    }

    /**
     * Returns current connection state.
     *
     * @return boolean true if connected, false otherwise
     */
    public function isConnected()
    {
        return $this->is_connected;
    }

    /**
     * Sends payload to server.
     *
     * @param string $payload Payload data
     * @param boolean $masked Use masking for message flag
     * @param OpCode $opcode Defines the interpretation of the $payload
     * @throws ConnectionException if client is not connected
     * @throws ConnectionException if data was not sent or if sent bytes are not equal to payload
     * @return true if payload was sent
     */
    public function send($payload, $masked = true, $opcode = OpCode::TEXT)
    {
        if (!$this->is_connected) {
            throw new ConnectionException(
               'Not connected', 1005
            );
        }

        $payload_length = strlen($payload);

        $fragment_cursor = 0;
        while ($payload_length > $fragment_cursor) {
            $sub_payload = substr($payload, $fragment_cursor, $this->options['fragment_size']);
            $fragment_cursor += $this->options['fragment_size'];
            $final = $payload_length <= $fragment_cursor;

            $this->sendFragment($final, $sub_payload, $opcode, $masked);
            $opcode = OpCode::CONTINUATION;
        }

        return true;
    }

    /**
     * Reads socket frame and gets payload.
     *
     * @param Client::SYNC_READ|Client::ASYNC_READ $mode Read mode
     * @param int $timeout_sec The seconds part of the timeout to be set if read in Sync mode
     * @param int $timeout_microsec The microseconds part of the timeout to be set  if read in Sync mode
     * @throws TimeoutException if timeout limit is exceeded
     * @throws ConnectionException if not connected
     * @throws ConnectionException if socket frame is broken
     * @throws ConnectionException if read from closed connection
     * @throws ConnectionException if unable to read from stream
     * @return array read code and socket frame payload
     *
     *     $return = [
     *       '0' => (RtmClient\WebSocket\ReturnCode) Read result
     *       '1' => (string) Frame payload
     *     ]
     */
    public function read($mode = self::SYNC_READ, $timeout_sec = 0, $timeout_microsec = 0)
    {
        if (!$this->is_connected) {
            throw new ConnectionException(
               'Not connected', 1006
            );
        }

        $this->continuous_payload = '';
        $response = null;
        $code = null;

        while (is_null($response)) {
            try {
                list($code, $response) = $this->readFrame($mode, $timeout_sec, $timeout_microsec);
                if ($mode == self::ASYNC_READ && $code == RC::READ_WOULD_BLOCK) {
                    return array($code, $response);
                }
            } catch (TimeoutException $e) {
                if (empty($timeout_sec) && empty($timeout_microsec)) {
                    throw $e;
                }

                return array(RC::READ_TIMEOUT, $response);
            }
        }

        return array($code, $response);
    }

    /**
     * Reads socket frame.
     *
     * @param Client::SYNC_READ|Client::ASYNC_READ $mode Read mode
     * @param int $timeout_sec The seconds part of the timeout to be set if read in Sync mode
     * @param int $timeout_microsec The microseconds part of the timeout to be set  if read in Sync mode
     * @return array Read result
     *
     *     $return = [
     *       '0' => (RtmClient\WebSocket\ReturnCode) Read result
     *       '1' => (string) Frame payload
     *     ]
     */
    protected function readFrame($mode, $timeout_sec = 0, $timeout_microsec = 0)
    {
        $status = -1;
        $payload = null;

        // Set timeout till first data
        stream_set_timeout($this->socket, $timeout_sec, $timeout_microsec);

        // Read first 2 bytes of frame header
        $data = $this->socketRead(2, $mode);

        if (empty($data)) {
            return array(RC::READ_WOULD_BLOCK, null);
        }

        if (empty($timeout_sec) && empty($timeout_microsec)) {
            // Reset timeout to previously specified value
            stream_set_timeout($this->socket, $this->options['timeout']);
        }

        // FIN:  1 bit
        $final = (boolean) (ord($data[0]) & 1 << 7);

        // RSV1, RSV2, RSV3:  1 bit each
        $rsv1  = (boolean) (ord($data[0]) & 1 << 6);
        $rsv2  = (boolean) (ord($data[0]) & 1 << 5);
        $rsv3  = (boolean) (ord($data[0]) & 1 << 4);

        // Opcode:  4 bits
        $opcode = ord($data[0]) & 31;

        // record the opcode if we are not receiving a continutation fragment
        if ($opcode !== OpCode::CONTINUATION) {
            $this->last_opcode = $opcode;
        }

        // Mask:  1 bit
        $mask = (boolean) (ord($data[1]) >> 7);

        // Payload length:  7 bits, 7+16 bits, or 7+64 bits
        // $payload = '';
        $payload_length = (integer) ord($data[1]) & 127;
        if ($payload_length > 125) {
            if ($payload_length === 126) {
                $data = $this->socketRead(2); // 126: Payload is a 16-bit unsigned int
            } else {
                $data = $this->socketRead(8); // 127: Payload is a 64-bit unsigned int
            }
            $payload_length = bindec($this->strToBin($data));
        }

        // Get masking key.
        if ($mask) {
            $masking_key = $this->socketRead(4);
        }

        // Get the actual payload, if any (might not be for e.g. close frames.
        if ($payload_length > 0) {
            $data = $this->socketRead($payload_length);
            if ($mask) {
                // Unmask payload.
                for ($i = 0; $i < $payload_length; $i++) {
                    $payload .= ($data[$i] ^ $masking_key[$i % 4]);
                }
            } else {
                $payload = $data;
            }
        }

        if ($opcode === OpCode::PONG) {
            return array(RC::PONG, $payload);
        }

        if ($opcode === OpCode::CLOSE) {
            if ($payload_length >= 2) {
                $status_bin = $payload[0] . $payload[1];
                $status = bindec(sprintf('%08b%08b', ord($payload[0]), ord($payload[1])));
                $this->close_status = $status;
                $payload = substr($payload, 2);
                if (!$this->is_closing) {
                    $this->send($status_bin . 'Close acknowledged: ' . $status, 'close', true);
                }
            }

            // A close response, connection has been closed properly
            if ($this->is_closing) {
                $this->is_closing = false;
            }
            // Close the socket
            fclose($this->socket);
            $this->is_connected = false;

            return array(RC::CLOSED, array(
                'status' => $status,
                'payload' => $payload,
            ));
        }

        if (!$final) {
            // Save payload and read next frame
            $this->continuous_payload .= $payload;
            return array(RC::READ_OK, null);
        } elseif ($this->continuous_payload) {
            $payload = $this->continuous_payload . $payload;
            $this->continuous_payload = null;
        }

        return array(RC::READ_OK, $payload);
    }

    /**
     * Sends fragment.
     *
     * @param bool $final Mark fragment as Final
     * @param string $payload Payload data
     * @param OpCode $opcode Fragmant OpCode
     * @param bool $masked Use mask for fragment
     * @throws ConnectionException if bytes sent did not match actual payload size
     * @return integer bytes sent
     */
    protected function sendFragment($final, $payload, $opcode, $masked)
    {
        $frame = '';
        $frame_header = '';

        // FIN:  1 bit
        // Indicates that this is the final fragment in a message.  The first
        // fragment MAY also be the final fragment.
        $frame_header .= (bool) $final ? '1' : '0';

        // RSV1, RSV2, RSV3:  1 bit each
        // MUST be 0 unless an extension is negotiated that defines meanings
        // for non-zero values.
        $frame_header .= '000';

        // Opcode:  4 bits
        // Defines the interpretation of the "Payload data".
        $frame_header .= sprintf('%04b', $opcode);

        // Mask:  1 bit
        // Defines whether the "Payload data" is masked.  If set to 1, a
        // masking key is present in masking-key, and this is used to unmask
        // the "Payload data"
        $frame_header .= $masked ? '1' : '0';

        // Payload length:  7 bits, 7+16 bits, or 7+64 bits
        // The length of the "Payload data", in bytes: if 0-125, that is the
        // payload length.  If 126, the following 2 bytes interpreted as a
        // 16-bit unsigned integer are the payload length.  If 127, the
        // following 8 bytes interpreted as a 64-bit unsigned integer (the
        // most significant bit MUST be 0) are the payload length.  Multibyte
        // length quantities are expressed in network byte order
        $payload_length = strlen($payload);
        if ($payload_length > 65535) {
            $frame_header .= decbin(127);
            $frame_header .= sprintf('%064b', $payload_length);
        } elseif ($payload_length > 125) {
            $frame_header .= decbin(126);
            $frame_header .= sprintf('%016b', $payload_length);
        } else {
            $frame_header .= sprintf('%07b', $payload_length);
        }

        // Append frame header to frame
        foreach (str_split($frame_header, 8) as $binstr) {
            $frame .= chr(bindec($binstr));
        }

        // Handle masking
        // TODO: Check without masking
        if ($masked) {
            $mask = '';
            for ($i = 0; $i < 4; $i++) {
                $mask .= chr(rand(0, 255));
            }
            $frame .= $mask;
        }

        // Append payload to frame
        for ($i = 0; $i < $payload_length; $i++) {
            $frame .= ($masked === true) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $this->socketWrite($frame);
    }

    /**
     * Writes data to socket.
     *
     * @param string $data Data to be send
     * @throws ConnectionException if bytes sent did not match actual data size
     * @return integer bytes sent
     */
    protected function socketWrite($data)
    {
        $w_bytes = fwrite($this->socket, $data);
        if ($w_bytes < strlen($data)) {
            $metadata = stream_get_meta_data($this->socket);
            throw new ConnectionException(
                "Wrote $w_bytes bytes out of " . strlen($data) . ' bytes: ' . json_encode($metadata), 17
            );
        }

        return $w_bytes;
    }

    /*
     * Checks if stream is not blocked for read operation.
     *
     * @return bool true if read will bot block stream
     */
    protected function streamIsReadyToRead()
    {
        $read = array($this->socket);
        $write = $except = null;

        return stream_select($read, $write, $except, 0) !== 0;
    }

    /**
     * Reads from socket.
     *
     * @param integer $length Amount of bytes to be read from socket
     * @param Client::SYNC_READ|Client::ASYNC_READ $mode Read mode
     * @throws TimeoutException if timeout limit is exceeded
     * @throws ConnectionException if not connected
     * @throws ConnectionException if socket frame is broken
     * @throws ConnectionException if read from closed connection
     * @throws ConnectionException if unable to read from stream
     * @return string Read data
     */
    protected function socketRead($length, $mode = self::SYNC_READ)
    {
        $data = '';

        while (strlen($data) < $length) {
            $metadata = stream_get_meta_data($this->socket);
            if ($metadata['eof']) {
                throw new ConnectionException(
                    'Read from closed connection: ' . json_encode($metadata), 1010
                );
            }

            if ($mode == self::ASYNC_READ) {
                if (!$this->streamIsReadyToRead()) {
                    return '';
                }
            }

            $buffer = fread($this->socket, $length - strlen($data));
            $metadata = stream_get_meta_data($this->socket);

            if ($buffer === false) {
                if ($metadata['timed_out']) {
                    throw new TimeoutException(
                        'Timeout', 18
                    );
                } else {
                    throw new ConnectionException(
                        'Frame is broken. Read ' . strlen($data) . ' of '
                        . $length . ' bytes. State: '
                        . json_encode($metadata), 1009
                    );
                }
            }

            if ($buffer === '') {
                if ($metadata['eof']) {
                    throw new ConnectionException(
                        'Read from closed connection: ' . json_encode($metadata), 1010
                    );
                } elseif ($metadata['timed_out']) {
                    throw new TimeoutException(
                        'Timeout', 20
                    );
                } elseif ($metadata['unread_bytes'] == 0 && $mode == self::SYNC_READ) {
                    // Socket is in non-blocking mode.
                    // Wait for data in socket buffer.
                    continue;
                } else {
                    throw new ConnectionException(
                        'Unable to read from stream: ' . json_encode($metadata), 1011
                    );
                }
            }
            $data .= $buffer;
        }

        return $data;
    }

    /**
     * Converts string to binary representation.
     *
     * @param string $str String to be converted to binary
     * @return string Binary representation
     */
    protected static function strToBin($str)
    {
        $return = '';
        for ($i = 0; $i < strlen($str); $i++) {
            $return .= sprintf('%08b', ord($str[$i]));
        }
        return $return;
    }

    /**
     * Splits url to parts.
     *
     * @param string $url URL with scheme
     * @return array URL parts
     *
     *     $return = [
     *       'scheme'        => (string) e.g. http, wss
     *       'socket_scheme' => (string) ssl or tcp
     *       'host'          => (string)
     *       'path'          => (string)
     *       'query'         => (string)
     *       'port'          => (string)
     *     ]
     */
    protected static function parseUrl($url)
    {
        $parsed = parse_url($url);

        $parts = array(
            'scheme' => isset($parsed['scheme']) ? $parsed['scheme'] : '',
            'host' => isset($parsed['host']) ? $parsed['host'] : '',
            'path' => isset($parsed['path']) ? $parsed['path'] : '/',
            'query' => isset($parsed['query']) ? $parsed['query'] : '',
        );

        $parts['port'] = isset($parsed['port']) ? $parsed['port'] : ($parts['scheme'] === 'wss' ? 443 : 80);
        $parts['socket_scheme'] = $parts['scheme'] == 'wss' ? 'ssl' : 'tcp';

        if (!in_array($parts['scheme'], array('ws', 'wss'))) {
            throw new BadSchemeException(
               'Wrong url scheme "' . $parts['scheme'] . '". The scheme MUST be "ws://" or "wss://"', 23
            );
        }

        return $parts;
    }

    /**
     * Generates pseudo bytes hex hash.
     *
     * @param integer $length Length of generated hash
     * @return string Hash
     */
    protected static function generateSecKey($length = 16)
    {
        return bin2hex(openssl_random_pseudo_bytes($length));
    }
}
