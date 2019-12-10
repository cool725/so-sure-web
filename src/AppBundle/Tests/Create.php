<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\BankAccount;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\Premium;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\LogEntry;
use AppBundle\Classes\Salva;
use AppBundle\Classes\Helvetia;
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
     * @param User             $user         is the user who owns the policy.
     * @param \DateTime|string $start        is either a date or a string defining a date that the policy starts. The
     *                                       policy end will be a year after this date.
     * @param string           $status       is the status that the policy will have.
     * @param int              $installments is the number of premium installments.
     * @return Policy the newly created policy.
     */
    public static function policy($user, $start, $status, $installments)
    {
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy = ($startDate < Salva::getSalvaBinderEndDate()) ? new SalvaPhonePolicy() : new HelvetiaPhonePolicy();
        $policy->setUser($user);
        $policy->setStart($startDate);
        $policy->setEnd((clone $startDate)->add(new \DateInterval("P1Y")));
        $policy->setStaticEnd($policy->getEnd());
        $premium = new PhonePremium();
        $premium->setGwp(rand(20, 100) / 8);
        $policy->setPremium($premium);
        $policy->setStatus($status);
        $policy->setPolicyNumber(sprintf("TEST/%s/%d", rand(1000, 9999), rand()));
        $policy->setPremiumInstallments($installments);
        return $policy;
    }

    /**
     * Create a policy and give it a bacs payment method.
     * @param User $user is the user that the policy belongs to.
     * @param \DateTime|string $start is the start date either as a date string or as a date.
     * @param string $status is the status that the policy should be given.
     * @param int $installments is the number of premium installments the policy is meant to pay.
     * @return Policy the policy.
     */
    public static function bacsPolicy($user, $start, $status, $installments)
    {
        $policy = Create::policy($user, $start, $status, $installments);
        $bankAccount = new BankAccount();
        $paymentMethod = new BacsPaymentMethod();
        $paymentMethod->setBankAccount($bankAccount);
        $policy->setPaymentMethod($paymentMethod);
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

    /**
     * Create a payment of one month's premium and put it on the given policy.
     * @param Policy           $policy  is the policy to add the payment to.
     * @param \DateTime|string $date    is the date of the payment.
     * @param boolean          $success is the success of the payment.
     * @return Payment the created payment.
     */
    public static function standardPayment($policy, $date, $success)
    {
        $properDate = is_string($date) ? new \DateTime($date) : $date;
        $payment = new CheckoutPayment();
        $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $payment->setSuccess($success);
        $payment->setDate($properDate);
        $policy->addPayment($payment);
        if ($success) {
            $policy->setCommission($payment, false, $properDate);
        }
        return $payment;
    }

    /**
     * Create a scheduled payment of one month's premium and put it on the given policy.
     * @param Policy           $policy is the policy to add the scheduled payment to.
     * @param \DateTime|string $date   is the scheduled date of the scheduled payment.
     * @param string           $status is the status to give the scheduled payment.
     * @param string           $type   is the type to give the scheduled payment.
     * @return ScheduledPayment the scheduled payment that was created.
     */
    public static function standardScheduledPayment($policy, $date, $status, $type)
    {
        $scheduledPayment = new ScheduledPayment();
        $scheduledPayment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
        $scheduledPayment->setScheduled(is_string($date) ? new \DateTime($date) : $date);
        $scheduledPayment->setStatus($status);
        $scheduledPayment->setType($type);
        $policy->addScheduledPayment($scheduledPayment);
        return $scheduledPayment;
    }
}
