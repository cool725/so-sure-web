<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\BankAccount;
use AppBundle\Document\ReferralBonus;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\Premium;
use AppBundle\Document\Phone;
use AppBundle\Document\SCode;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\LogEntry;
use AppBundle\Document\PhonePolicyIteration;
use AppBundle\Document\Subvariant;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Excess\PhoneExcess;
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
    public static function refresh(DocumentManager $dm, ...$items)
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
    public static function save(DocumentManager $dm, ...$items)
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
     * @param Phone|null       $phone        is the phone to set on the policy if one is given.
     * @param Subvariant|null  $subvariant   the policy's subvariant if any.
     * @return PhonePolicy the newly created policy.
     */
    public static function policy($user, $start, $status, $installments, $phone = null, $subvariant = null)
    {
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy = null;
        if ($startDate < Salva::getSalvaBinderEndDate()) {
            $policy = self::salvaPhonePolicy($user, $startDate, $status, $installments, $phone, $subvariant);
        } else {
            $policy = self::helvetiaPhonePolicy($user, $startDate, $status, $installments, $phone, $subvariant);
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
     * @param Phone|null       $phone        is the phone to set on the policy if one is given.
     * @param Subvariant|null  $subvariant   is the subvariant of the policy if any.
     * @return SalvaPhonePolicy the new phone policy.
     */
    public static function salvaPhonePolicy($user, $start, $status, $installments, $phone = null, $subvariant = null)
    {
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy = new SalvaPhonePolicy();
        $user->addPolicy($policy);
        $policy->setStart($startDate);
        $policy->setEnd((clone $startDate)->add(new \DateInterval("P1Y")));
        $policy->setStaticEnd($policy->getEnd());
        if ($phone) {
            $policy->setPhone($phone);
            $price = $phone->getCurrentPhonePrice(
                PhonePrice::installmentsStream($installments),
                $start,
                $subvariant ? $subvariant->getName() : null
            );
            $policy->setPremium($price->createPremium());
        } else {
            $premium = new PhonePremium();
            $premium->setGwp(rand(20, 100) / 8);
            $premium->setExcess(Create::phoneExcess());
            $premium->setPicSureExcess(Create::phoneExcess());
            $policy->setPremium($premium);
        }
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
     * @param Phone|null       $phone        is the phone to set on the policy if one is given.
     * @return HelvetiaPhonePolicy the new phone policy.
     */
    public static function helvetiaPhonePolicy(
        $user,
        $start,
        $status,
        $installments,
        $phone = null,
        $subvariant = null
    ) {
        $startDate = is_string($start) ? new \DateTime($start) : $start;
        $policy = new HelvetiaPhonePolicy();
        $user->addPolicy($policy);
        $policy->setStart($startDate);
        $policy->setEnd((clone $startDate)->add(new \DateInterval("P1Y")));
        $policy->setStaticEnd($policy->getEnd());
        if ($phone) {
            $policy->setPhone($phone);
            $price = $phone->getCurrentPhonePrice(
                PhonePrice::installmentsStream($installments),
                $start,
                $subvariant ? $subvariant->getName() : null
            );
            $policy->setPremium($price->createPremium());
        } else {
            $premium = new PhonePremium();
            $premium->setGwp(rand(20, 100) / 8);
            $premium->setExcess(Create::phoneExcess());
            $premium->setPicSureExcess(Create::phoneExcess());
            $policy->setPremium($premium);
        }
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
     * @param \DateTime|string $validFrom  is the date that the price is valid from as a string or a date.
     * @param string           $stream     is the stream that the price is in.
     * @param string|null      $subvariant is the subvariant to apply the price to if any.
     * @param number|null      $gwp        is the gwp to give to it if you want one, otherwise it's random.
     */
    public static function phonePrice($validFrom, $stream, $subvariant = null, $gwp = null)
    {
        $date = is_string($validFrom) ? new \DateTime($validFrom) : $validFrom;
        $gwp = ($gwp === null) ? (rand(100, 600) / 90) : $gwp;
        $price = new PhonePrice();
        $price->setGwp($gwp);
        $price->setValidFrom($date);
        $price->setStream($stream);
        if ($subvariant) {
            $price->setSubvariant($subvariant);
        }
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
     * Creates a referral bonus.
     * @param Policy    $a    is the inviter.
     * @param Policy    $b    is the invitee.
     * @param \DateTime $date is the date for the referral bonus creation date.
     * @return ReferralBonus that was just created.
     */
    public static function referralBonus($a, $b, $date)
    {
        $referralBonus = new ReferralBonus();
        $a->addInviterReferralBonus($referralBonus);
        $b->addInviteeReferralBonus($referralBonus);
        $referralBonus->setStatus(ReferralBonus::STATUS_PENDING);
        $referralBonus->setCreated($date);
        return $referralBonus;
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

    /**
     * Creates some policy terms
     * @param string  $version    is the version of the policy terms to represent.
     * @param boolean $latest     is the value to give to the latest flag.
     * @param boolean $aggregator is the value to give to the aggregator flag.
     * @return PolicyTerms the new terms.
     *https://youtu.be/xuoy96nMcUk/
    public static function terms($version, $latest = true, $aggregator = false)
    {
        $terms = new PolicyTerms();
        $terms->setVersion($version);
        $terms->setLatest($latest);
        $terms->setAggregator($aggregator);
        return $terms;
    }

    /**
     * Creates a claim.
     * @param Policy    $policy is the policy that the claim is on.
     * @param string    $type   is the type of claim.
     * @param \DateTime $date   is the date the claim was created.
     * @param string    $status is the statue of the claim.
     * @return Claim the claim created.
     */
    public static function claim($policy, $type, $date, $status)
    {
        $claim = new Claim();
        $claim->setType($type);
        $claim->setCreatedDate($date);
        $claim->setStatus($status);
        $policy->addClaim($claim);
        return $claim;
    }

    /**
     * Creates a phone excess by providing all the fields that it needs.
     * @param number $damage           is the damage excess.
     * @param number $warranty         is the warranty excess.
     * @param number $extendedWarranty is the extended warranty excess.
     * @param number $loss             is the loss excess.
     * @param number $theft            is the theft excess.
     * @return the created excess.
     */
    public static function phoneExcess(
        $damage = -1,
        $warranty = -1,
        $extendedWarranty = -1,
        $loss = -1,
        $theft = -1
    ) {
        $excess = new PhoneExcess();
        $excess->setDamage($damage >= 0 ? $damage : rand(1, 200));
        $excess->setWarranty($warranty >= 0 ? $warranty : rand(1, 200));
        $excess->setExtendedWarranty($extendedWarranty >= 0 ? $extendedWarranty : rand(1, 200));
        $excess->setLoss($loss >= 0 ? $loss : rand(1, 200));
        $excess->setTheft($theft >= 0 ? $theft : rand(1, 200));
        return $excess;
    }
}
