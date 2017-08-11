<?php
define('BASE_DIR', __DIR__);

spl_autoload_register(function ($class_path) {
    $parts = explode('\\', $class_path);
    $class_name = array_pop($parts) . '.php';

    $path = implode(DIRECTORY_SEPARATOR, $parts);
    $path .= DIRECTORY_SEPARATOR . $class_name;

    if (file_exists(BASE_DIR . DIRECTORY_SEPARATOR . $path)) {
        require_once BASE_DIR . DIRECTORY_SEPARATOR . $path;
    }
});
