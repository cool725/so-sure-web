<?php

namespace AppBundle\Document;

use AppBundle\Exception\Queue\QueueException;
use AppBundle\Exception\Queue\UnknownMessageException;
use Psr\Log\LoggerInterface;

/**
 * Abstracts generic message queue functionality a bit.
 */
trait QueueTrait
{
    /** @var string */
    protected $queueKey;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * Tells you if the message is ready to be processed based on the optional processTime value.
     * @param array $data is the message we are checking on.
     * @return boolean which tells you whether or not they are ready.
     */
    public function messageReady(array $data)
    {
        $now = new \DateTime();
        return (!(isset($data["processTime"]) && ($data["processTime"] > $now->format("U"))));
    }

    /**
     * Processes queued messages.
     * @param int $max is the maximum number of messages to process.
     * @return array containing "processed", "requeued", and "dropped" counts.
     */
    public function process($max)
    {
        if (!isset($this->queueKey)) {
            throw new QueueException("Trying to use queue without defining \$queueKey.");
        }
        $requeued = 0;
        $processed = 0;
        $dropped = 0;
        for ($i = 0; $i < $max; $i++) {
            $data = null;
            try {
                $message = $this->redis->lpop($this->queueKey);
                if (!$message) {
                    break;
                }
                $data = unserialize($message);
                if (!$data || !isset($data["action"])) {
                    $message = sprintf("Actionless message in {$this->queueKey} queue %s", json_encode($data));
                    throw new UnknownMessageException($message);
                }
                if (!$this->messageReady($data)) {
                    $requeued++;
                    $this->queue($data);
                    continue;
                }
                $this->action($data);
                $processed++;
            } catch (QueueException $e) {
                $this->logger->error($e->getMessage(), ["msg" => json_encode($data), "exception" => $e->getMessage()]);
                $dropped++;
            } catch (\Exception $e) {
                $this->logger->error($e->getMessage(), ["msg" => json_encode($data), "exception" => $e->getMessage()]);
                $retrial = $this->queue($data, true);
                if ($retrial) {
                    $requeued++;
                }
            }
        }
        return ["processed" => $processed, "requeued" => $requeued, "dropped" => $dropped];
    }

    /**
     * Tells the length of the queue.
     * @return int the length of the queue.
     */
    public function countQueue()
    {
        if (!isset($this->queueKey)) {
            throw new QueueException("Trying to use queue without defining \$queueKey.");
        }
        return $this->redis->llen($this->queueKey);
    }

    /**
     * Deletes everything in the queue.
     */
    public function clearQueue()
    {
        if (!isset($this->queueKey)) {
            throw new QueueException("Trying to use queue without defining \$queueKey.");
        }
        $this->redis->del([$this->queueKey]);
    }

    /**
     * Returns all messages in the queue up to a maximum length.
     * @param int $max is the maximum number of messages to return.
     * @return array containing the messages.
     */
    public function getQueueData(int $max): array
    {
        if (!isset($this->queueKey)) {
            throw new QueueException("Trying to use queue without defining \$queueKey.");
        }
        return $this->redis->lrange($this->queueKey, 0, $max);
    }

    /**
     * Add a message to the given queue.
     * @param array   $data  is the message to add.
     * @param boolean $retry is true if retries of the message should be counted.
     */
    protected function queue($data, $retry = false)
    {
        if (!isset($this->queueKey)) {
            throw new QueueException("Trying to use queue without defining \$queueKey.");
        }
        if (isset($data["attempts"])) {
            if ($retry) {
                $data["attempts"]++;
            }
            if ($data["attempts"] >= 3) {
                $this->logger->error(
                    "Message in queue {$queueKey} failed repeatedly and has been dropped.",
                    ["data" => $data]
                );
                return false;
            }
        } else {
            $data["attempts"] = 0;
        }
        $this->redis->rpush($this->queueKey, serialize($data));
        return true;
    }
}
