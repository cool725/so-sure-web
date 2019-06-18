<?php

namespace AppBundle\Exception;

/**
 * This exception represents an error that has occurred in which an attempt has been made to perform some process on a
 * payment whose state is not appropriate for this process.
 */
class InvalidPaymentException extends \Exception
{
    // Does nothing.
}
