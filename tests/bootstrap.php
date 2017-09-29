<?php

$base_dir = __DIR__;
error_reporting(E_ALL);

$classes = array(
    'BaseTestCase.php',
    'Helpers/ConnectionExt.php',
    'Helpers/RtmClientExt.php',
    'Helpers/RoleAuthExt.php',
    'Helpers/StorageLogger.php',
    'Helpers/WsClientExt.php',
);

foreach ($classes as $class) {
    require_once($base_dir . DIRECTORY_SEPARATOR . $class);
}
