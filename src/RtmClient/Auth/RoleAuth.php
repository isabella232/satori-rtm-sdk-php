<?php

namespace RtmClient\Auth;

use RtmClient\Logger\Logger;
use RtmClient\Connection;

use RtmClient\Exceptions\AuthenticationException;

use RtmClient\Pdu\ReturnCode as PduRC;
use RtmClient\Pdu\Helper as PduHelper;

use RtmClient\WebSocket\Client as Ws;
use RtmClient\WebSocket\ReturnCode as SocketRC;

/**
 * RTM role-based authentication.
 *
 * Client connection to RTM is established in default role.
 * Client can acquire different the permissions by authenticating for a different role.
 * This is done in a two-step process using the Handshake and then Authenticate PDU.
 */
class RoleAuth implements iAuth
{
    /**
     * Timeout limit on read operations
     */
    const READ_TIMEOUT_SEC = 5;

    const ERROR_CODE_NONCE_NOT_FOUND = 100;
    const ERROR_CODE_FAILED_TO_AUTHENTICATE = 101;

    /**
     * Connection instance
     *
     * @var Connection
     */
    protected $connection;

    /**
     * Uses to determine if auth still in progress.
     *
     * @var boolean
     */
    protected $auth_in_progress = false;

    /**
     * Creates Role based authenticator.
     *
     * @param string $role Role name
     * @param string $role_secret Role secret key
     * @param array $options RoleAuth options
     *
     *     $options = [
     *       'logger' => (Psr\Log\LoggerInterface) Logger
     *     ]
     */
    public function __construct($role, $role_secret, $options = array())
    {
        $this->role = $role;
        $this->role_secret = $role_secret;

        $this->logger = !empty($options['logger']) ? $options['logger'] : new Logger();
    }

    /**
     * Makes authentication procedure.
     *
     * @param Connection $connection Connection instance
     * @throws AuthenticationException if unable to get nonce from RTM
     * @throws AuthenticationException if failed to authenticate
     * @return boolean true if successfully authenticates, false otherwise
     */
    public function authenticate(Connection $connection)
    {
        $this->auth_in_progress = true;
        $this->connection = $connection;

        $check_auth_state = function () {
            if ($this->auth_in_progress) {
                // Auth still in progress but script died. Drop the connection.
                $this->logger->error('Connection dropped because auth still in progess, but script died');
                $this->connection->close('Auth still in progress');
            }
        };
        register_shutdown_function($check_auth_state);

        if (!$this->connection->isReusedPersistentConnection()) {
            $this->logger->info('Auth: Starting authentication');
            try {
                $this->handshake();
            } catch (AuthenticationException $e) {
                $this->auth_in_progress = false;
                throw $e;
            }
        } else {
            $this->logger->info('Auth: Reused connection. Authentication is not needed.');
        }

        $this->auth_in_progress = false;
    }

    /**
     * Sends handshake request to Satori RTM
     *
     * @return boolean true if successfully authenticates, false otherwise
     */
    protected function handshake()
    {
        $action = 'auth/handshake';
        $body = array(
            'method' => 'role_secret',
            'data' => array(
                'role' => $this->role,
            ),
        );

        $this->connection->send($action, $body, function ($pdu) {
            if (!isset($pdu->body['data']) || !isset($pdu->body['data']['nonce'])) {
                $this->logger->error('Auth: Nonce not found in: ' . $pdu);
                throw new AuthenticationException(
                    'Nonce not found', self::ERROR_CODE_NONCE_NOT_FOUND
                );
            }

            $this->roleSecretAuth($pdu->body['data']['nonce']);
        });

        $this->connection->waitAllReplies(self::READ_TIMEOUT_SEC);
    }

    /**
     * Sends authenticate reply to RTM.
     *
     * @param string $nonce Nonce from RTM reply on handshake
     * @throws AuthenticationException if failed to authenticate
     * @return true if successfully authenticated
     */
    protected function roleSecretAuth($nonce)
    {
        $hash = hash_hmac('md5', $nonce, $this->role_secret, true);

        $action = 'auth/authenticate';
        $body = array(
            'method' => 'role_secret',
            'credentials' => array(
                'hash' => base64_encode($hash),
            ),
        );
        $this->connection->send($action, $body, function ($pdu) {
            if (PduHelper::pduResponseCode($pdu) !== PduRC::CODE_OK_REQUEST) {
                $this->logger->error('Auth: Failed to authenticate: ' . $pdu);
                throw new AuthenticationException(
                    'Failed to authenticate', self::ERROR_CODE_FAILED_TO_AUTHENTICATE
                );
            }

            $this->logger->info('Auth: Successfully authenticated');
        });
        $this->connection->waitAllReplies(self::READ_TIMEOUT_SEC);
    }
}
