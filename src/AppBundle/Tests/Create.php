<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Premium;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\LogEntry;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * Provides helper functions for creating test data similar to UserClassTrait, but does not persist anything and so can
 * be used for unit tests, and it only has static functions so it does not need to be inherited as a trait or
 * configured.
 */
class Create
{
    /**
     * Refreshes the passed things from the document manager.
     * @param DocumentManager $dm       is the document manager used to refresh the item.
     * @param mixed           ...$items is the set of the passed items.
     */
    public static function refresh($dm, ...$items)
    {
        foreach ($items as $item) {
            $dm->persist($item);
        }
        $dm->flush();
    }

    /**
     * Persists and flushes a collection of items to the database in a single operation.
     * @param DocumentManager $dm       is the document manager to use to persist to the database.
     * @param mixed           ...$items is the set of all items to persist.
     */
    public static function save($dm, ...$items)
    {
        foreach ($items as $item) {
            $dm->persist($item);
        }
        $dm->flush();
    }

    /**
     * Creates a new user document that can be safely persisted.
     * @return User the created user.
     */
    public static function user()
    {
        $user = new User();
        $user->setFirstName("John");
        $user->setLastName("Fogle");
        $user->setEmail(uniqid()."@hotmail.com");
        return $user;
    }

    /**
     * Creates a basic policy record with a user, a start and end date, a premium and a certain status.
     * @param User             $user   is the user who owns the policy.
     * @param \DateTime|string $start  is either a date or a string defining a date that the policy starts. The policy
     *                                 end will be a year after this date.
     * @param float            $gwp    is the gwp value that the policy's premium will have.
     * @param string           $status is the status that the policy will have.
     * @return Policy the newly created policy.
     */
    public static function policy($user, $start, $gwp, $status)
    {
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy->setStart($startDate);
        $policy->setEnd((clone $startDate)->add(new \DateInterval("P1Y")));
        $premium = new PhonePremium();
        $premium->setGwp($gwp);
        $policy->setPremium($premium);
        $policy->setStatus($status);
        $policy->setPolicyNumber(sprintf("TEST/%s/%d", rand(1000, 9999), rand()));
        return $policy;
    }

    /**
     * Creates a log entry with it's date being a given number of days ago.
     * @param Policy $policy  is the policy the entry should be for.
     * @param string $status  is the status the policy is having set in the entry.
     * @param int    $daysAgo is the number of days ago this log entry should have occurred.
     * @return LogEntry the created log entry.
     */
    public static function logEntry($policy, $status, $daysAgo)
    {
        $date = (new \DateTime())->sub(new \DateInterval("P{$daysAgo}D"));
        $logEntry = new LogEntry();
        $logEntry->setObjectId($policy->getId());
        $logEntry->setData(["status" => $status]);
        $logEntry->setLoggedAtSpecifically($date);
        return $logEntry;
    }
}
