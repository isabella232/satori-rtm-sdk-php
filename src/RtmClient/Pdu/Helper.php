<?php

namespace RtmClient\Pdu;

use RtmClient\Exceptions\ApplicationException;

/**
 * Helper functions to work with PDU.
 */
class Helper
{
    /**
     * Converts json-string to \RtmClient\Pdu\Pdu.
     *
     * @param string $json_string
     * @return \RtmClient\Pdu\Pdu
     * @throws ApplicationException if failed to parse json string
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     */
    public static function convertToPdu($json_string)
    {
        if (null === $json = json_decode($json_string, true)) {
            throw new ApplicationException(
                'Bad PDU received' . PHP_EOL
                . $json_string
            );
        }

        if (!isset($json['action']) || !isset($json['body'])) {
            throw new ApplicationException(
                'Missing "action" or "body" field in received PDU' . PHP_EOL
                . $json_string
            );
        }

        $id = isset($json['id']) ? $json['id'] : null;
        return new Pdu($json['action'], $json['body'], $id);
    }

    /**
     * Returns PDU code by \RtmClient\Pdu\Pdu::$action.
     *
     * @param Pdu $pdu
     * @return int One of \RtmClient\Pdu\ReturnCode::(CODE_OK_REQUEST|CODE_ERROR_REQUEST|CODE_DATA_REQUEST|CODE_BAD_REQUEST)
     */
    public static function pduResponseCode(Pdu $pdu)
    {
        $status = strrchr($pdu->action, '/');
        if ($status === false) {
            return ReturnCode::CODE_BAD_REQUEST;
        }

        switch ($status) {
        case "/ok":
            return ReturnCode::CODE_OK_REQUEST;
        case "/error":
            return ReturnCode::CODE_ERROR_REQUEST;
        case "/data":
            return ReturnCode::CODE_DATA_REQUEST;
        default:
            return ReturnCode::CODE_UNKNOWN_REQUEST;
        }
    }
}
