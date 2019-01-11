<?php

namespace AppBundle\Exception\Queue;

/**
 * Error caused by something being queued that the queue processor does not know how to process.
 */
class UnknownMessageException extends QueueException
{

}
