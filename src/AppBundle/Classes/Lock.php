<?php
namespace AppBundle\Classes;

use Predis\Client;

/**
 * Helper class for locking resources across servers in such a way that the resources should automatically be freed if
 * there is an error.
 */
class Lock
{
    /** @var Client */
    private $redis;

    /** @var string */
    private $name;

    /** @var number|null */
    private $expiry;

    /**
     * @param Client      $redis  gives the lock access to redis where it stores the info.
     * @param string      $name   the name of the lock in redis.
     * @param number|null $expiry the number of seconds the lock will take to automatically expire if something bad
     *                            happens.
     */
    public function __construct($redis, $name, $expiry=21600)
    {
        $this->redis = $redis;
        $this->name = $name;
        $this->expiry = $expiry;
    }

    /**
     * Tells you whether the lock is currently being used or not. Don't rely on this to do anything that should lock
     * the lock though remember.
     * @return boolean true iff the lock is in use.
     */
    public function check()
    {
        return $this->redis->get($this->name) ? true : false;
    }

    /**
     * Checks if the lock is available for use.
     * @param callable $callback is a callback to call with the lock.
     * @return null|string null if all worked ok, and a string with an error message if either an exception was thrown
     *                     from the callback or the lock could not be acquired.
     */
    public function with($callback)
    {
        $result = $this->redis->setnx($this->name, (new \DateTime())->format('Y-m-d H:i'));
        if ($result === 0) {
            return 'Could not acquire lock';
        }
        if ($this->expiry !== null) {
            $this->redis->expire($this->name, $this->expiry);
        }
        try {
            $callback();
        } catch (\Exception $e) {
            return $e->getMessage();
        }
        $this->destroy();
        return null;
    }

    /**
     * Sets the lock as unlocked. Don't do this lightly, it should only really be used if some kind of bug has messed
     * up the state.
     */
    public function destroy()
    {
        $this->redis->del([$this->name]);
    }
}
