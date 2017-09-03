<?php

namespace AppBundle\Event;

use Symfony\Component\EventDispatcher\Event;
use AppBundle\Document\Connection\Connection;

class ConnectionEvent extends Event
{
    const EVENT_CONNECTED = 'event.connection.connected';
    const EVENT_REDUCED = 'event.connection.reduced';

    /** @var Connection */
    protected $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function getConnection()
    {
        return $this->connection;
    }
}
