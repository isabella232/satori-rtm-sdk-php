<?php

namespace RtmClient\Pdu;

use RtmClient\Exceptions\ApplicationException;

/**
 * Helper functions to work with PDU.
 */
class Helper
{
    /**
     * Converts array to \RtmClient\Pdu\Pdu.
     *
     * @param array $struct
     * @return \RtmClient\Pdu\Pdu
     * @throws ApplicationException if missing "action" or "body" field in received PDU
     */
    public static function convertToPdu($struct)
    {
        if (!isset($struct['action']) || !isset($struct['body'])) {
            throw new ApplicationException(
                'Missing "action" or "body" field in received PDU' . PHP_EOL
                . json_encode($struct)
            );
        }

        $id = isset($struct['id']) ? $struct['id'] : null;
        return new Pdu($struct['action'], $struct['body'], $id);
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
