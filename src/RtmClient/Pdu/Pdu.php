<?php

namespace RtmClient\Pdu;

/**
 * Protocol Data Unit (PDU).
 */
class Pdu
{
    /**
     * Creates PDU instance.
     *
     * @param string $action Specifies the purpose of a PDU and determines the content of the body
     * @param array $body Content is specific to the PDU's action
     * @param string|int $id Instructs RTM to send a response and enables a client to match a response to a request
     */
    public function __construct($action, $body, $id = null)
    {
        $this->action = $action;
        $this->body = $body;
        $this->id = $id;
    }

    /**
     * Represents PDU struct as an associative array.
     *
     * @return array
     */
    public function struct()
    {
        $json = array(
            'action' => $this->action,
            'body' => $this->body,
        );

        if (!is_null($this->id)) {
            $json['id'] = $this->id;
        }

        return $json;
    }

    /**
     * Represents PDU as a string.
     *
     * @return string
     */
    public function stringify()
    {
        return json_encode($this->struct(), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    }

    /**
     * Represents PDU as a json string when using in printing functions.
     * @magic
     *
     * @return string
     */
    public function __toString()
    {
        return $this->stringify();
    }
}
