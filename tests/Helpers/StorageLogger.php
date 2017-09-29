<?php

namespace Tests\Helpers;

class StorageLogger
{
    public $storage = array();

    public function error($message, array $context = array())
    {
        $this->storage[] = array(
            'type' => 'error',
            'message' => $message,
        );
        echo '[erro] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
    }

    public function info($message, array $context = array())
    {
        $this->storage[] = array(
            'type' => 'info',
            'message' => $message,
        );
        echo '[info] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
    }

    public function warning($message, array $context = array())
    {
        $this->storage[] = array(
            'type' => 'warning',
            'message' => $message,
        );
        echo '[warn] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
    }

    protected function interpolate($message, array $context = array())
    {
        // build a replacement array with braces around the context keys
        $replace = array();
        foreach ($context as $key => $val) {
            // check that the value can be casted to string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }

    protected function getTimeHeader($timestamp = null)
    {
        if (is_null($timestamp)) {
            $timestamp = microtime(true);
        }
        $datetime = \DateTime::createFromFormat('U.u', $timestamp);
        if ($datetime === false) {
            // Milliceconds are to small, PHP rounds such number to int
            $datetime = \DateTime::createFromFormat('U', $timestamp);
        }
        return $datetime->format('Y/m/d H:i:s.u');
    }
}
