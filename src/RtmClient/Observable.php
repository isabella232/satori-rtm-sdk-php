<?php

namespace RtmClient;

/**
 * Observable class.
 *
 * Allows to extend any struct with ability to Fire events and ability
 * to listen for any events.
 */
abstract class Observable
{
    /**
     * List of callbacks splitted by Event name.
     *
     * @var array
     */
    protected $events = array();

    /**
     * Stub arguments to be passed to callback.
     * Requires to avoid "Missing numbers of arguments" if callback function requires args,
     * that were not passed to fire()
     *
     * @var array
     */
    protected $stub = array(null, null, null, null, null, null, null, null, null, null);

    /**
     * Adds listener for an event.
     *
     * @param string $event Event name
     * @param callable $callback function to be called when an event is "fire"
     */
    public function on($event, callable $callback)
    {
        if (!isset($this->events[$event])) {
            $this->events[$event] = array();
        }
        array_push($this->events[$event], $callback);
    }

    /**
     * Fires event.
     * Executes callback functions and passes data to them.
     */
    public function fire()
    {
        $args = func_get_args();
        $event = array_shift($args);
        $callbacks = isset($this->events[$event]) ? $this->events[$event] : array();

        // Missing argument: Avoid situation when callback requires some arguments,
        // but fire() was called without any (or not enough)
        $args = array_replace($this->stub, $args);

        foreach ($callbacks as $callback) {
            call_user_func_array($callback, $args);
        }
    }

    /**
     * Unsubscribes from an event.
     * Use the callback function that you used when calling "on".
     *
     * @param string $event Event name
     * @param callable $callback Callback function that was used when calling "on"
     */
    public function off($event, callable $callback)
    {
        if (isset($this->events[$event])) {
            $index = array_search($callback, $this->events[$event]);
            if ($index !== false) {
                unset($this->events[$event][$index]);
            }
        }
    }
}
