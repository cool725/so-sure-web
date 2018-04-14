<?php
// src/AppBundle/Document/User.php

namespace AppBundle\Document;

use AppBundle\Classes\SoSure;
use FOS\UserBundle\Document\User as BaseUser;
use Doctrine\ODM\MongoDB\Mapping\Annotations as MongoDB;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;
use AppBundle\Validator\Constraints as AppAssert;
use VasilDakov\Postcode\Postcode;

/**
 * @MongoDB\EmbeddedDocument
 * @Gedmo\Loggable
 */
class BankAccount
{
    use BacsTrait;
    use DateTrait;

    const MANDATE_PENDING_INIT = 'pending-init';
    const MANDATE_PENDING_APPROVAL = 'pending-approval';
    const MANDATE_SUCCESS = 'success';
    const MANDATE_FAILURE = 'failure';
    const MANDATE_CANCELLED = 'cancelled';

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @AppAssert\BankAccountName()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $accountName;

    /**
     * @AppAssert\AlphanumericSpaceDot()
     * @Assert\Length(min="1", max="100")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $bankName;

    /**
     * @AppAssert\SortCode()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="6", max="6")
     * @Gedmo\Versioned
     * @var string
     */
    protected $sortCode;

    /**
     * @AppAssert\Token()
     * @AppAssert\BankAccountNumber()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="8", max="8")
     * @Gedmo\Versioned
     * @var string
     */
    protected $accountNumber;

    /**
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="6", max="18")
     * @Gedmo\Versioned
     * @var string
     */
    protected $reference;

    /**
     * @Assert\Choice({"pending-init", "pending-approval", "success", "failure", "cancelled"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $mandateStatus;

    /**
     * @AppAssert\Token()
     * @Assert\Length(min="1", max="10")
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $mandateSerialNumber;

    /**
     * @MongoDB\EmbedOne(targetDocument="Address")
     * @Gedmo\Versioned
     * @var Address
     */
    protected $bankAddress;

    /**
     * @AppAssert\Token()
     * @MongoDB\Field(type="string")
     * @Assert\Length(min="8", max="256")
     * @Gedmo\Versioned
     * @var string
     */
    protected $hashedAccount;

    /**
     * @MongoDB\EmbedOne(targetDocument="IdentityLog")
     * @Gedmo\Versioned
     * @var IdentityLog
     */
    protected $identityLog;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $initialNotificationDate;

    /**
     * First date when payment can be submitted (after mandate approved)
     *
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $initialPaymentSubmissionDate;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $standardNotificationDate;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     * @var boolean
     */
    protected $firstPayment;

    public function __construct()
    {
        $this->setMandateStatus(self::MANDATE_PENDING_INIT);
    }

    public function setAccountName($accountName)
    {
        $this->accountName = $accountName;
    }

    public function getAccountName()
    {
        return $this->accountName;
    }

    public function setBankName($bankName)
    {
        $this->bankName = $bankName;
    }

    public function getBankName()
    {
        return $this->bankName;
    }

    public function setSortCode($sortCode)
    {
        $this->sortCode = $this->normalizeSortCode($sortCode);
        $this->generateHashedAccount();
    }

    public function getSortCode()
    {
        return $this->sortCode;
    }

    public function setAccountNumber($accountNumber)
    {
        $this->accountNumber = $this->normalizeAccountNumber($accountNumber);
        $this->generateHashedAccount();
    }

    public function getAccountNumber()
    {
        return $this->accountNumber;
    }

    public function setBankAddress(Address $address = null)
    {
        $this->bankAddress = $address;
    }

    public function getBankAddress()
    {
        return $this->bankAddress;
    }

    public function getHashedAccount()
    {
        return $this->hashedAccount;
    }

    public function setHashedAccount($hashedAccount)
    {
        $this->hashedAccount = $hashedAccount;
    }

    public function generateHashedAccount()
    {
        $this->setHashedAccount(
            sha1(sprintf("%s%s", $this->getSortCode(), $this->getAccountNumber()))
        );
    }

    public function isValid($transformed = true)
    {
        return $this->validateSortCode($this->getSortCode())
            && $this->validateAccountNumber($this->getAccountNumber(), $transformed);
    }

    public function getDisplayableSortCode()
    {
        return $this->displayableSortCode($this->getSortCode());
    }

    public function getDisplayableAccountNumber()
    {
        return $this->displayableAccountNumber($this->getAccountNumber());
    }

    public function setReference($reference)
    {
        $this->reference = $reference;
    }

    public function getReference()
    {
        return $this->reference;
    }

    public function generateReference(User $user, $sequence)
    {
        $reference = sprintf('%s5%010d', mb_strtoupper(mb_substr($user->getLastName(), 0, 1)), $sequence);
        $this->setReference($reference);
        $this->setFirstPayment(true);

        return $reference;
    }

    public function getMandateStatus()
    {
        return $this->mandateStatus;
    }

    public function setMandateStatus($mandateStatus)
    {
        $this->mandateStatus = $mandateStatus;
    }

    public function getMandateSerialNumber()
    {
        return $this->mandateSerialNumber;
    }

    public function setMandateSerialNumber($serialNumber)
    {
        $this->mandateSerialNumber = $serialNumber;
    }

    public function getIdentityLog()
    {
        return $this->identityLog;
    }

    public function setIdentityLog($identityLog)
    {
        $this->identityLog = $identityLog;
    }

    public function getInitialNotificationDate()
    {
        return $this->initialNotificationDate;
    }

    public function setInitialNotificationDate(\DateTime $initialNotificationDate = null)
    {
        $this->initialNotificationDate = $initialNotificationDate;
    }

    public function getInitialPaymentSubmissionDate()
    {
        return $this->initialPaymentSubmissionDate;
    }

    public function setInitialPaymentSubmissionDate($initialPaymentSubmissionDate)
    {
        $this->initialPaymentSubmissionDate = $initialPaymentSubmissionDate;
    }

    public function getStandardNotificationDate()
    {
        return $this->standardNotificationDate;
    }

    public function setStandardNotificationDate(\DateTime $standardNotificationDate = null)
    {
        $this->standardNotificationDate = $standardNotificationDate;
    }

    public function isFirstPayment()
    {
        return $this->firstPayment;
    }

    public function setFirstPayment($firstPayment)
    {
        $this->firstPayment = $firstPayment;
    }

    public function isMandateInProgress()
    {
        return !in_array($this->getMandateStatus(), [
            self::MANDATE_SUCCESS,
            self::MANDATE_FAILURE,
            self::MANDATE_CANCELLED
        ]);
    }

    public function allowedSubmission(\DateTime $now = null)
    {
        if (!$now) {
            $now = new \DateTime();
        }
        $now = $this->startOfDay($now);
        $initial = clone $this->getInitialPaymentSubmissionDate();
        $initial = $this->startOfDay($initial);

        $diff = $initial->diff($now);
        if (($diff->days > 0 && !$diff->invert) || $diff->days == 0) {
            return true;
        } else {
            return false;
        }
    }

    public function allowedProcessing(\DateTime $processingDate = null)
    {
        if ($this->isFirstPayment()) {
            return $this->allowedInitialProcessing($processingDate);
        } else {
            return $this->allowedStandardProcessing($processingDate);
        }
    }

    public function allowedInitialProcessing(\DateTime $processingDate = null)
    {
        if (!$processingDate) {
            $processingDate = new \DateTime();
            $processingDate = $this->addBusinessDays($processingDate, 1);
        }
        $processingDate = $this->startOfDay($processingDate);

        // Rule: Service users must collect the Direct Debit on or within 3 working days after the specified due date
        // as advised to the payer on the advance notice.
        $minAllowedDate = clone $this->getInitialNotificationDate();
        $maxAllowedDate = clone $this->getInitialNotificationDate();
        $maxAllowedDate = $this->addBusinessDays($maxAllowedDate, 3);
        $maxAllowedDate = $this->startOfDay($maxAllowedDate);

        $minDiff = $processingDate->diff($minAllowedDate);
        $maxDiff = $processingDate->diff($maxAllowedDate);

        return ($minDiff->days == 0 || $minDiff->invert == 1) && ($maxDiff->days == 0 || $maxDiff->invert == 0);
    }

    public function allowedStandardProcessing(\DateTime $processingDate = null)
    {
        if (!$processingDate) {
            $processingDate = new \DateTime();
            $processingDate = $this->addBusinessDays($processingDate, 1);
        }
        $processingDate = $this->startOfDay($processingDate);
        $processingDay = $processingDate->format('j');

        // Rule: Service users must collect the Direct Debit on or within 3 working days after the specified due date
        // as advised to the payer on the advance notice.
        $minAllowedDate = clone $this->getStandardNotificationDate();
        $maxAllowedDate = clone $this->getStandardNotificationDate();
        $maxAllowedDate = $this->addBusinessDays($maxAllowedDate, 3);
        $maxAllowedDate = $this->startOfDay($maxAllowedDate);
        $minAllowedDay = $minAllowedDate->format('j');
        $maxAllowedDay = $maxAllowedDate->format('j');

        // standard month
        if ($maxAllowedDay > $minAllowedDay) {
            return $processingDay >= $minAllowedDay && $processingDay <= $maxAllowedDay;
        } else {
            // example case: 28 -> 3; 30 > 28
            if ($processingDay >= $minAllowedDay) {
                return true;
            } elseif ($processingDay <= $maxAllowedDay) {
                // example case: 28 -> 3; 1 < 3
                return true;
            } else {
                return false;
            }
        }
    }

    public function getPaymentDate(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now', new \DateTimeZone(SoSure::TIMEZONE));
        }

        $days = 4;
        if ($this->getMandateStatus() == self::MANDATE_SUCCESS) {
            $days = 3;
        }
        // 2pm cutoff (UTC for cronjob) or will take place the following day
        if ($this->isWeekDay($date) && $date->format('H') >= 14) {
            $days++;
        }

        return $this->addBusinessDays($date, $days);
    }

    public function getFirstPaymentDate(User $user, \DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now', new \DateTimeZone(SoSure::TIMEZONE));
        }
        $useClosestPaymentDate = false;
        $nextPolicyPaymentDate = null;
        foreach ($user->getValidPolicies(true) as $policy) {
            /** @var Policy $policy */
            if (!$policy->isPolicyPaidToDate($date)) {
                $useClosestPaymentDate = true;
            } else {
                $nextPolicyPaymentDate = $policy->getNextBillingDate($date);
            }
        }

        $bacsPaymentDate = $this->getPaymentDate($date);
        /*
        print sprintf(
            'c: %s bacs: %s next: %s',
            $useClosestPaymentDate ? 'y' : 'n',
            $bacsPaymentDate->format('y-m-d'),
            $nextPolicyPaymentDate->format('y-m-d')
        );
        */
        if (!$nextPolicyPaymentDate) {
            return $bacsPaymentDate;
        } elseif ($useClosestPaymentDate || $nextPolicyPaymentDate < $bacsPaymentDate) {
            return $bacsPaymentDate;
        } else {
            return $nextPolicyPaymentDate;
        }
    }

    public function __toString()
    {
        return sprintf(
            "%s %s %s",
            $this->getAccountName(),
            $this->getDisplayableSortCode(),
            $this->getDisplayableAccountNumber()
        );
    }

    public static function create($accountName, $sortCode, $accountNumber, $reference)
    {
        $bankAccount = new BankAccount();
        $bankAccount->setAccountNumber($accountNumber);
        $bankAccount->setSortCode($sortCode);
        $bankAccount->setAccountName($accountName);
        $bankAccount->setReference($reference);

        return $bankAccount;
    }
}
