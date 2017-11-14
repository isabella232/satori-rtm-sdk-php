<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Helpers\RtmClientExt;
use RtmClient\Auth\RoleAuth;

use RtmClient\WebSocket\Client as Ws;

abstract class RtmClientBaseTestCase extends TestCase
{
    protected $credentials_filename = 'credentials.json';
    protected $project_dir;
    protected $credentials;

    public function setUp()
    {
        parent::setUp();

        $this->project_dir = dirname(__DIR__);

        // Get credentials path from the ENV
        $credentials_path = getenv('CREDENTIALS');
        if (empty($credentials_path)) {
            // Try to find credentials.json file in the root of the project
            $credentials_path = $this->project_dir . DIRECTORY_SEPARATOR . $this->credentials_filename;
        }
        
        if (file_exists($credentials_path)) {
            $content = file_get_contents($credentials_path);
            $this->credentials = json_decode($content, true);
        }
    }

    public function checkCredentials()
    {
        if (empty($this->credentials)) {
            $this->markTestSkipped(
                'Missing credentials'
            );
        }
    }

    public function establishConnection($protocol = Ws::PROTOCOL_CBOR)
    {
        $this->checkCredentials();

        $options = array(
            'auth' => new RoleAuth($this->credentials['auth_role_name'], $this->credentials['auth_role_secret_key']),
            'protocol' => $protocol,
        );
        $client = new RtmClientExt($this->credentials['endpoint'], $this->credentials['appkey'], $options);

        if (!$client->connect()) {
            $this->fail('Unable to connect');
        }

        return $client;
    }

    public function getChannel()
    {
        return 'channel-' . substr(md5(mt_rand()), 0, 7);
    }
}
