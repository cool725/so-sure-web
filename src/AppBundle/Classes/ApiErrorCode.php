<?php
namespace AppBundle\Classes;

class ApiErrorCode
{
    const SUCCESS = 0;
    const ERROR_UNKNOWN = 1;
    const ERROR_MISSING_PARAM = 2;
    const ERROR_ACCESS_DENIED = 3;
    const ERROR_NOT_FOUND = 4;
    const ERROR_UPGRADE_APP = 5;
    const ERROR_TOO_MANY_REQUESTS = 6;
    const ERROR_NOT_YET_REGULATED = 7;
    const ERROR_INVALD_DATA_FORMAT = 8;
    const ERROR_USER_EXISTS = 100;
    const ERROR_USER_ABSENT = 101;
    const ERROR_USER_INVALID_ADDRESS = 102;
    const ERROR_USER_RESET_PASSWORD = 103;
    const ERROR_USER_SUSPENDED = 104;
    const ERROR_USER_POLICY_LIMIT = 105;
    const ERROR_USER_TOO_YOUNG = 106;
    const ERROR_USER_SEND_SMS = 107;
    const ERROR_USER_VERIFY_CODE = 108;
    const ERROR_POLICY_IMEI_BLACKLISTED = 200;
    const ERROR_POLICY_IMEI_PHONE_MISMATCH = 201;
    const ERROR_POLICY_IMEI_LOSTSTOLEN = 202;
    const ERROR_POLICY_IMEI_INVALID = 203;
    const ERROR_POLICY_INVALID_USER_DETAILS = 205;
    const ERROR_POLICY_INVALID_VALIDATION = 206;
    const ERROR_POLICY_GEO_RESTRICTED = 207;
    const ERROR_POLICY_DUPLICATE_IMEI = 208;
    const ERROR_POLICY_PAYMENT_REQUIRED = 209;
    const ERROR_POLICY_PAYMENT_DECLINED = 210;
    const ERROR_POLICY_PAYMENT_INVALID_AMOUNT = 211;
    const ERROR_POLICY_TERMS_NOT_AVAILABLE = 212;
    const ERROR_POLICY_UNABLE_TO_UDPATE = 213;
    const ERROR_POLICY_PICSURE_FILE_NOT_FOUND = 251;
    const ERROR_POLICY_PICSURE_FILE_INVALID = 252;
    const ERROR_POLICY_PICSURE_DISALLOWED = 253;
    const ERROR_POLICY_IMEI_FILE_NOT_FOUND = 261;
    const ERROR_POLICY_IMEI_FILE_INVALID = 262;
    const ERROR_BANK_DIRECT_DEBIT_UNAVAILABLE = 270;
    const ERROR_BANK_NAME_MISMATCH = 271;
    const ERROR_BANK_INVALID_SORTCODE = 272;
    const ERROR_BANK_INVALID_NUMBER = 273;
    const ERROR_BANK_NOT_ENOUGH_TIME = 274;
    const ERROR_BANK_INVALID_MANDATE = 275;
    const ERROR_INVITATION_DUPLICATE = 300;
    const ERROR_INVITATION_OPTOUT = 301;
    const ERROR_INVITATION_PREVIOUSLY_PROCESSED = 302;
    const ERROR_INVITATION_LIMIT = 303;
    const ERROR_INVITATION_SELF_INVITATION = 304;
    const ERROR_INVITATION_POLICY_HAS_CLAIM = 305;
    const ERROR_INVITATION_MAXPOT = 306;
    const ERROR_INVITATION_CONNECTED = 307;
    const ERROR_QUOTE_UNABLE_TO_INSURE = 400;
    const ERROR_QUOTE_PHONE_UNKNOWN = 401;
    const ERROR_QUOTE_EXPIRED = 402;
    const ERROR_QUOTE_COMING_SOON = 403;
    const ERROR_DETECTED_IMEI_MANUAL_PROCESSING = 500;
    // logging error codes.
    const EX_UNKNOWN = 600;
    const EX_PAYMENT_DECLINED = 601;
    const EX_ACCESS_DENIED = 602;
    const EX_COMMISSION = 603;

    /**
     * Writes an error message of the format location:<errorcode>, and then the message body on a new line.
     * @param string $location is the context in which the error has been caught.
     * @param int    $code     is the error code.
     * @param string $text     is the main error message.
     * @return string of the new message.
     */
    public static function errorMessage($location, $code, $text)
    {
        return "{$location}:<{$code}>\n{$text}";
    }
}
