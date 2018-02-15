<?php
namespace AppBundle\Exception;

/**
 * Unexpected Recipero Response, but will be manually verified, so user can continue with purchase
 */
class ReciperoManualProcessException extends \Exception
{
    const CODE_UNKNOWN = 0;

    // validateSamePhone exceptions
    const NO_MODELS = 13;
    const NO_MEMORY = 14;
    const NO_MAKES = 15;
    const MULTIPLE_MAKES = 16;
    const EMPTY_MAKES = 17;
    const MEMORY_MISMATCH = 18;
    const NO_MODEL_REFERENCE = 19;
    const MODEL_MISMATCH = 20;
    const DEVICE_NOT_FOUND = 21;
    const MAKE_MODEL_MEMORY_MISMATCH = 22;
}
