<?php

namespace AppBundle\Exception;

/**
 * Represents the occurence of an incorrect price.
 */
class IncorrectPriceException extends \Exception
{
    /**
     * Builds the exception.
     * @param string $message is the message to display with the exception.
     */
    public function __construct($message)
    {
        parent::__construct($message);
    }
}
