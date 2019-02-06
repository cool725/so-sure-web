<?php
namespace AppBundle\Exception;

/**
 * Represents an error stemming from our requests to an external service being rate limited.
 */
class ExternalRateLimitException extends \Exception
{
}
