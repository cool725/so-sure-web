<?php

namespace AppBundle\Exception\Queue;

/**
 * Error caused by message being queued that does not have correct data for it's action.
 */
class MalformedMessageException extends QueueException
{

}
