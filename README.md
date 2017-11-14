Satori RTM SDK for PHP
============================================================

[![GitHub tag](https://img.shields.io/github/tag/satori-com/satori-rtm-sdk-php.svg)](https://github.com/satori-com/satori-rtm-sdk-php/tags)

Use the PHP SDK for [the Satori platform](https://www.satori.com/) to create applications that use the
RTM to publish and subscribe.

## Requirements

  - PHP 5.4+ (PHP 5.6+ recommended)
  - PHP Composer (optional, recommended)

## PHP SDK Installation and Usage

### Via Composer

To install the PHP SDK from the [Central Composer repository](https://packagist.org) use [composer](https://getcomposer.org/download):
```bash
composer require satori-com/satori-rtm-sdk-php=dev-master
```

Highly recommended to use version tag when installing SDK:
```bash
composer require satori-com/satori-rtm-sdk-php:1.2.3.4
```

Detailed information about the PHP SDK package and a list of available versions can be found here:
https://packagist.org/packages/satori-com/satori-rtm-sdk-php

Include the SDK into your PHP file using the Composer autoloader:
```php
<?php

require('vendor/autoload.php');
```

### Via Source Code

Clone the PHP SDK to your project (or any directory on your computer):
```bash
cd /path/to/your/project
git clone git@github.com:satori-com/satori-rtm-sdk-php.git
```

Include the SDK into your PHP file using the SDK autoloader:
```php
<?php

require('satori-rtm-sdk-php/autoloader.php');
```

## Documentation and Examples

https://satori-com.github.io/satori-rtm-sdk-php/

## Logging and Debugging

PHP SDK logs information to STDOUT/STDERR. To enable debug level,
set DEBUG_SATORI_SDK environment variable to `true`:
```bash
$ DEBUG_SATORI_SDK=true php <your_program.php>
```

or 

```bash
$ export DEBUG_SATORI_SDK=true
$ php <your_program.php>
```

Debug level outputs all underlying communication with RTM, in addition to SDK execution info.  
Example:
```bash
$ DEBUG_SATORI_SDK=true php <your_program.php>
[info] 2017/07/28 15:35:33.536100 Client: Connecting to endpoint: <YOUR_ENDPOINT>
[info] 2017/07/28 15:35:33.823600 Auth: Starting authentication
[debg] 2017/07/28 15:35:33.824300 SEND> {"action":"auth/handshake","body":{"method":"role_secret","data":{"role":"<YOUR_ROLE>"}},"id":1}
[debg] 2017/07/28 15:35:33.951200 RECV< {"action":"auth/handshake/ok","id":1,"body":{"data":{"nonce":"<nonce>"}}}
[info] 2017/07/28 15:35:33.951600 Auth: Got nonce
[debg] 2017/07/28 15:35:33.951600 SEND> {"action":"auth/authenticate","body":{"method":"role_secret","credentials":{"hash":"<hash>"}},"id":2}
[debg] 2017/07/28 15:35:34.082500 RECV< {"action":"auth/authenticate/ok","id":2,"body":{}}
[info] 2017/07/28 15:35:34.082700 Auth: Successfully authenticated
[debg] 2017/07/28 15:35:34.083100 SEND> {"action":"rtm/publish","body":{"channel":"animals","message":{"who":"zebra","where":[34.134358,-118.321506]}},"id":3}
[debg] 2017/07/28 15:35:34.211700 RECV< {"action":"rtm/publish/ok","body":{"position":"1501256125:2"},"id":3}
```

## Testing

PHP SDK uses PHPUnit for testing. PHPUnit requires PHP 5.6+.
In spite of PHP SDK itself requires PHP 5.4+, you have to upgrade your PHP version to 5.6+.

Tests require a valid RTM endpoint; RTM credentials should be populated in `credentials.json`.

The `credentials.json` file must include the following key-value pairs:
```json
{
  "endpoint": "wss://<SATORI_HOST>/",
  "appkey": "<APP_KEY>",
  "auth_role_name": "<ROLE_NAME>",
  "auth_role_secret_key": "<ROLE_SECRET_KEY>",
  "auth_restricted_channel": "<CHANNEL_NAME>"
}
```

- `endpoint` is your customer-specific DNS name for RTM access.
- `appkey` is your application key.
- `auth_role_name` is a role name that permits to publish / subscribe to `auth_restricted_channel`. Must be not `default`.
- `auth_role_secret_key` is a secret key for `auth_role_name`.
- `auth_restricted_channel` is a channel with subscribe and publish access for `auth_role_name` role only.

You must use [DevPortal](https://developer.satori.com/) to create role and set channel permissions.

After setting up `credentials.json`, run SDK tests with the following commands:
```bash
git clone git@github.com:satori-com/satori-rtm-sdk-php.git
cd satori-rtm-sdk-php
composer install
CREDENTIALS=/full/path/to/credentials.json composer test
```

To enable testing verbose mode use:
```bash
CREDENTIALS=/full/path/to/credentials.json composer test-verbose
```

# Binary protocol

RTM supports CBOR protocol to work with a binary data.  
In order to use CBOR protocol you must to install PHP SDK via Composer: [How to install SDK via Composer](#via-composer)

See `examples/cbor.php` to get more information about establishing a connection using CBOR.

# Persistent connections

Persistent connections are good solution to reduce execution time by using an already established connection.
PHP does not spend additional time establishing a new connection and authorization.

But this solution has a number of limitations:
- You can not subscribe to any channel. This limitation was added in order to avoid a possible situation 
  with an overflow of the incoming buffer after the script is finished;
- There is a possible situation with the PDU collision. The script can do publish with ack, but do not 
  wait for an reply from Satori RTM and finish the work.  Because the connection is not closed, the following
  script, which will use it, will receive ack, although it has not sent anything yet or it will publish with ack,
  but when reading reply it gets ack from the previous script;
- PHP SDK guarantee that you will get the same RTM Client instance if you establish a persistent connection
  to the same host:port from a different places within one script launch;

Usage:
```
$client = RtmClient::persistentConnection('wss://endpoint.satori.com', 'appkey1234', array(
    'connection_id' => 'connection1', // optional
));
$client->connect();
```

**IMPORTANT NOTICE**

Persistent connections work only in the environments that support persistent connections,
like PHP-FPM mode, FastCGI mode, Apache mod_php, etc.

# Troubleshooting

## Unable to Connect to a Secure Endpoint

**Symptom**: 

    $ php your_app/index.php
    [info] 2017/08/03 15:28:22.512700 Client: Connecting to endpoint: wss://<endpoint>.api.satori.com/v2
    [erro] 2017/08/03 15:28:22.535000 Failed establish connection to <endpoint>.api.satori.com:443:  (0)

**Solution**:

  - Check if you are able to connect to this endpoint:  
    ```
    $ telnet <endpoint>.api.satori.com 443
    Trying 123.123.123.123...
    Connected to <endpoint>.api.satori.com.
    Escape character is '^]'.
    ```
    If you fail to connect, check your firewall settings.

  - Check PHP SSL settings:
    ```
    $ php -r "print_r(openssl_get_cert_locations());";
    Array
    (
        [default_cert_file] => /usr/local/etc/openssl/cert.pem
        [default_cert_file_env] => SSL_CERT_FILE
        [default_cert_dir] => /usr/local/etc/openssl/certs
        [default_cert_dir_env] => SSL_CERT_DIR
        [default_private_dir] => /usr/local/etc/openssl/private
        [default_default_cert_area] => /usr/local/etc/openssl
        [ini_cafile] =>
        [ini_capath] =>
    )
    ```
    Make sure that the **default_cert_file** points to an existing cert file and **default_default_cert_area** points to the existing directory.

    If your certs are in another location, set environment variables that point to the certs by entering the following in a console window:
    ```
    $ export SSL_CA_FILE=/new/cert/dir/cert.pem
    $ export SSL_CA_PATH=/new/cert/dir/
    $ php your_app/index.php
    ```
