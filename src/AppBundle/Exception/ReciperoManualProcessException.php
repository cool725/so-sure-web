<?php
namespace AppBundle\Exception;

/**
 * Unexpected Recipero Response, but will be manually verified, so user can continue with purchase
 */
class ReciperoManualProcessException extends \Exception
{
    const CODE_UNKNOWN = 0;
    CONST CODE_SKIP_LOGGING = 1;
}
