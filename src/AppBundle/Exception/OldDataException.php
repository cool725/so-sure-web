<?php
namespace AppBundle\Exception;

/**
 * Represents an exception which has occurred due to data becoming outdated in the time between it being sent to the
 * user, and the user interacting with it.
 */
class OldDataException extends \Exception
{
}
