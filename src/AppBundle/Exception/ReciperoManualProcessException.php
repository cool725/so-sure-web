<?php
namespace AppBundle\Exception;

/**
 * Unexpected Recipero Response, but will be manually verified, so user can continue with purchase
 */
class ReciperoManualProcessException extends \Exception
{
    const CODE_UNKNOWN = 0;
    const CODE_SKIP_LOGGING = 1;

    //validateSamePhone exceptions
    const SERIAL_MISSING = 10;
    const MAKE_MISMATCH = 11;
    const NO_MODELS = 13;
    const NO_MEMORY = 14;
    const NO_MAKES_OR_MULTIPLE_MAKES = 15;
    const MEMORY_MISMATCH = 16;
    const NO_MODELREFERENCE = 17;
    const EMPTY_MAKES = 18;
    const UNKNOWN_STATUS = 19;
    const VALID_IMEI = 20;
    const SERIAL_MISMATCH = 21;
    const MODEL_MISMATCH = 22;
    const VALID_SERIAL = 23;
    const DEVICE_NOT_FOUND = 24;
    const NOT_VALID = 25;
}
