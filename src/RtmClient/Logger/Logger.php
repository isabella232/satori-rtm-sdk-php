<?php

namespace RtmClient\Logger;

/**
 * PSR-3 Logger.
 * https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-3-logger-interface.md
 */
class Logger
{
    /**
     * Logger verbose mode
     *
     * @var boolean
     */
    public $verbose = false;
    /**
     * System is unusable.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function emergency($message, array $context = array())
    {
        echo '[emrg] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
        exit(1);
    }

    /**
     * Action must be taken immediately.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function alert($message, array $context = array())
    {
        echo '[alrt] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
        exit(2);
    }

    /**
     * Critical conditions.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function critical($message, array $context = array())
    {
        echo '[crit] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
        exit(3);
    }

    /**
     * Runtime errors.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function error($message, array $context = array())
    {
        echo '[erro] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function warning($message, array $context = array())
    {
        echo '[warn] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
    }

    /**
     * Normal but significant events.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function notice($message, array $context = array())
    {
        echo '[notc] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
    }

    /**
     * Interesting events.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function info($message, array $context = array())
    {
        echo '[info] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
    }

    /**
     * Detailed debug information.
     *
     * @param string $message
     * @param array $context
     * @return null
     */
    public function debug($message, array $context = array())
    {
        $verbose = $this->verbose || getenv('DEBUG_SATORI_SDK') && getenv('DEBUG_SATORI_SDK') == 'true';

        if ($verbose) {
            echo '[debg] ' . $this->getTimeHeader() . ' ' . $this->interpolate($message, $context) . PHP_EOL;
        }
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = array())
    {
        switch ($level) {
            case LogLevel::EMERGENCY: $this->emergency($message, $context); break;
            case LogLevel::ALERT: $this->alert($message, $context); break;
            case LogLevel::CRITICAL: $this->critical($message, $context); break;
            case LogLevel::ERROR: $this->error($message, $context); break;
            case LogLevel::WARNING: $this->warning($message, $context); break;
            case LogLevel::NOTICE: $this->notice($message, $context); break;
            case LogLevel::INFO: $this->info($message, $context); break;
            case LogLevel::DEBUG: $this->debug($message, $context); break;
        }
    }

    /**
     * Interpolates context values into the message placeholders.
     *
     * @param string $message
     * @param array $context
     * @return string Interpolated message
     */
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

    /**
     * Gets timer header in 'Y/m/d H:i:s.u' format.
     *
     * @param mixed $timestamp Timestamp
     * @return string formatted time
     */
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
