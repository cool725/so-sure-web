<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\BankAccount;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\Premium;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\LogEntry;
use AppBundle\Document\PhonePolicyIteration;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\DateTrait;
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
        $user->setEnabled(true);
        return $user;
    }

    /**
     * Creates a basic policy record with a user, a start and end date, a premium and a certain status.
     * @param User             $user         is the user who owns the policy.
     * @param \DateTime|string $start        is either a date or a string defining a date that the policy starts. The
     *                                       policy end will be a year after this date.
     * @param string           $status       is the status that the policy will have.
     * @param int              $installments is the number of premium installments.
     * @return PhonePolicy the newly created policy.
     */
    public static function policy($user, $start, $status, $installments)
    {
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy = null;
        if ($startDate < Salva::getSalvaBinderEndDate()) {
            $policy = self::salvaPhonePolicy($user, $startDate, $status, $installments);
        } else {
            $policy = self::helvetiaPhonePolicy($user, $startDate, $status, $installments);
        }
        $policy->setImei(ImeiTrait::generateRandomImei());
        return $policy;
    }

    /**
     * Creates a salva phone policy.
     * @param User             $user         is the user who owns the policy.
     * @param \DateTime|string $start        is the start date of the policy.
     * @param string           $status       is the status of the policy.
     * @param int              $installments is the number of premium installments the policy is to pay.
     * @return SalvaPhonePolicy the new phone policy.
     */
    public static function salvaPhonePolicy($user, $start, $status, $installments, $phone = null)
    {
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);
        $policy->setStart($startDate);
        $policy->setEnd((clone $startDate)->add(new \DateInterval("P1Y")));
        $policy->setStaticEnd($policy->getEnd());
        if ($phone) {
            $policy->setPhone($phone);
        }
        $premium = new PhonePremium();
        $premium->setGwp(rand(20, 100) / 8);
        $policy->setPremium($premium);
        $policy->setStatus($status);
        $policy->setPolicyNumber(sprintf("TEST/%s/%d", rand(1000, 9999), rand()));
        $policy->setPremiumInstallments($installments);
        $paymentMethod = new CheckoutPaymentMethod();
        $policy->setPaymentMethod($paymentMethod);
        return $policy;
    }

    /**
     * Creates a helvetia phone policy.
     * @param User             $user         is the user who owns the policy.
     * @param \DateTime|string $start        is the start date of the policy.
     * @param string           $status       is the status of the policy.
     * @param int              $installments is the number of premium installments the policy is to pay.
     * @return HelvetiaPhonePolicy the new phone policy.
     */
    public static function helvetiaPhonePolicy($user, $start, $status, $installments, $phone = null)
    {
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy = new HelvetiaPhonePolicy();
        $policy->setUser($user);
        $policy->setStart($startDate);
        $policy->setEnd((clone $startDate)->add(new \DateInterval("P1Y")));
        $policy->setStaticEnd($policy->getEnd());
        if ($phone) {
            $policy->setPhone($phone);
        }
        $premium = new PhonePremium();
        $premium->setGwp(rand(20, 100) / 8);
        $policy->setPremium($premium);
        $policy->setStatus($status);
        $policy->setPolicyNumber(sprintf("TEST/%s/%d", rand(1000, 9999), rand()));
        $policy->setPremiumInstallments($installments);
        $paymentMethod = new CheckoutPaymentMethod();
        $policy->setPaymentMethod($paymentMethod);
        return $policy;
    }

    /**
     * Create a policy and give it a bacs payment method.
     * @param User             $user         is the user that the policy belongs to.
     * @param \DateTime|string $start        is the start date either as a date string or as a date.
     * @param string           $status       is the status that the policy should be given.
     * @param int              $installments is the number of premium installments the policy is meant to pay.
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
     * Creates a phone price.
     * @param \DateTime|string $validFrom is the date that the price is valid from as a string or a date.
     * @param string           $stream    is the stream that the price is in.
     */
    public static function phonePrice($validFrom, $stream)
    {
        $date = is_string($validFrom) ? new \DateTime($validFrom) : $validFrom;
        $gwp = rand(100, 600) / 90;
        $price = new PhonePrice();
        $price->setGwp($gwp);
        $price->setValidFrom($date);
        $price->setStream($stream);
        return $price;
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
     * Creates a payment at whatever time you want with whatever amount you want.
     * @param Policy           $policy  is the policy to put the payment on.
     * @param \DateTime|string $date    is the date of the payment as either a date or a string.
     * @param float            $amount  is the amount of the payment.
     * @param null|boolean     $success is whether the payment is successful, and null means it is pending.
     * @return Payment the created payment.
     */
    public static function payment($policy, $date, $amount, $success)
    {
        $properDate = is_string($date) ? new \DateTime($date) : $date;
        $payment = null;
        if ($policy->getPaymentMethod()->getType() == PaymentMethod::TYPE_BACS) {
            $payment = new BacsPayment();
        } elseif ($policy->getPaymentMethod()->getType() == PaymentMethod::TYPE_CHECKOUT) {
            $payment = new CheckoutPayment();
        } else {
            throw new \InvalidArgumentException("can only do checkout or bacs payments");
        }
        $payment->setAmount($amount);
        if ($success !== null) {
            $payment->setSuccess($success);
        } elseif ($payment->getType() == 'bacs') {
            /** @var BacsPayment $payment */
            $payment = $payment;
            $payment->setStatus(BacsPayment::STATUS_PENDING);
        }
        $payment->setDate($properDate);
        $policy->addPayment($payment);
        if ($success) {
            $policy->setCommission($payment, false, $properDate);
        }
        return $payment;
    }

    /**
     * Create a payment of one month's premium and put it on the given policy.
     * @param Policy           $policy  is the policy to add the payment to.
     * @param \DateTime|string $date    is the date of the payment.
     * @param null|boolean     $success is the success of the payment and null means it's pending.
     * @return Payment the created payment.
     */
    public static function standardPayment($policy, $date, $success)
    {
        $amount = 0;
        if ($policy->getPremiumInstallments() == 12) {
            $amount = $policy->getPremium()->getAdjustedStandardMonthlyPremiumPrice();
        } else {
            $amount = $policy->getPremium()->getAdjustedYearlyPremiumPrice();
        }
        return Create::payment($policy, $date, $amount, $success);
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

    /**
     * Creates a very sparse phone policy iteration.
     * @param \DateTime|string $start is the start date as either a string or a datetime.
     * @param \DateTime|string $end   is the end date as either a string or a datetime.
     * @param float            $gwp   is the gwp of the premium that this iteration should have.
     * @param float            $ipt   is the ipt of the premium that this iteration should have.
     * @return PhonePolicyIteration that which was just created.
     */
    public static function phonePolicyIteration($start, $end, $gwp, $ipt)
    {
        $start = is_string($start) ? new \DateTime($start) : $start;
        $end = is_string($end) ? new \DateTime($end) : $end;
        $premium = new PhonePremium();
        $premium->setGwp($gwp);
        $premium->setIpt($ipt);
        $iteration = new PhonePolicyIteration();
        $iteration->setStart($start);
        $iteration->setEnd($end);
        $iteration->setPremium($premium);
        return $iteration;
    }

    /**
     * Creates a phone with a price that will JustWork™.
     * @return Phone the new phone.
     */
    public static function phone()
    {
        $price = new PhonePrice();
        $price->setValidFrom(new \DateTime('2016-01-01'));
        $price->setGwp(rand() % 20);
        $price->setStream(PhonePrice::STREAM_ALL);
        $phone = new Phone();
        $phone->addPhonePrice($price);
        return $phone;
    }

    /**
     * Creates a nice scode.
     * @param string $value is the value to give to the scode.
     * @return SCode the create scode.
     */
    public static function scode($value)
    {
        $scode = new SCode();
        $scode->setCode($value);
        $scode->setType(SCode::TYPE_STANDARD);
        $scode->setActive(true);
        return $scode;
    }
}
