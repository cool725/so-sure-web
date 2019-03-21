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
 * @Gedmo\Loggable(logEntryClass="AppBundle\Document\LogEntry")
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

    const CANCELLER_AUDDIS = 'auddis';
    const CANCELLER_ADDACS = 'addacs';
    const CANCELLER_DDICS = 'ddics';
    const CANCELLER_SOSURE = 'sosure';


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
     * Denotes where the cause for the mandate cancellation originated from.
     * @Assert\Choice({"addacs", "auddis", "sosure"}, strict=true)
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $mandateCanceller;

    /**
     * @MongoDB\Field(type="string")
     * @Gedmo\Versioned
     * @var string
     */
    protected $mandateCancelledReason;

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
     * @var Address|null
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
    protected $created;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime|null
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
     * @var \DateTime|null
     */
    protected $standardNotificationDate;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     * @var boolean
     */
    protected $firstPayment;

    /**
     * @Assert\DateTime()
     * @MongoDB\Field(type="date")
     * @Gedmo\Versioned
     * @var \DateTime
     */
    protected $lastSuccessfulPaymentDate;

    /**
     * @Assert\Type("bool")
     * @MongoDB\Field(type="boolean")
     * @Gedmo\Versioned
     * @var boolean
     */
    protected $annual;

    public function __construct()
    {
        $this->setMandateStatus(self::MANDATE_PENDING_INIT);
        $this->created = \DateTime::createFromFormat('U', time());
        $this->annual = false;
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
        if (mb_strpos($reference, "DDIC") === 0) {
            throw new \Exception(sprintf('Mandate reference is unable to start with DDIC (%s)', $reference));
        }

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
        // Blank cancel reasons whenever we update status.
        $this->setMandateCanceller(null);
        $this->setMandateCancelledReason(null);
    }

    public function getMandateCanceller()
    {
        return $this->mandateCanceller;
    }

    public function setMandateCanceller($mandateCanceller)
    {
        $this->mandateCanceller = $mandateCanceller;
    }

    public function getMandateCancelledReason()
    {
        return $this->mandateCancelledReason;
    }

    public function setMandateCancelledReason($mandateCancelledReason)
    {
        $this->mandateCancelledReason = $mandateCancelledReason;
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

    public function getStandardNotificationDay()
    {
        return $this->getStandardNotificationDate()->format('j');
    }

    public function setStandardNotificationDate(\DateTime $standardNotificationDate = null)
    {
        $this->standardNotificationDate = $standardNotificationDate;
    }

    public function getLastSuccessfulPaymentDate()
    {
        return $this->lastSuccessfulPaymentDate;
    }

    public function setLastSuccessfulPaymentDate($lastSuccessfulPaymentDate)
    {
        $this->lastSuccessfulPaymentDate = $lastSuccessfulPaymentDate;
    }

    public function isFirstPayment()
    {
        return $this->firstPayment;
    }

    public function setFirstPayment($firstPayment)
    {
        $this->firstPayment = $firstPayment;
    }

    public function isAnnual()
    {
        return $this->annual;
    }

    public function setAnnual($annual)
    {
        $this->annual = $annual;
    }

    public function isMandateSuccess()
    {
        return $this->getMandateStatus() == self::MANDATE_SUCCESS;
    }

    public function isMandateInProgress()
    {
        return !in_array($this->getMandateStatus(), [
            self::MANDATE_SUCCESS,
            self::MANDATE_FAILURE,
            self::MANDATE_CANCELLED
        ]);
    }

    public function isBeforeInitialNotificationDate(\DateTime $date = null)
    {
        if (!$this->getInitialNotificationDate()) {
            return null;
        }

        return !$this->isAfterInitialNotificationDate($date);
    }

    /**
     * There's a timing issue where a mandate result comes back as success in the morning,
     * but we won't have a payment in the system until that afternoon
     */
    public function isAfterInitialNotificationDate(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        if (!$this->getInitialNotificationDate()) {
            return null;
        }

        // bacs timing is such that:
        // if initial notification date is tomorrow, there should be a payment in the system today
        // take into account when we run the bacs process
        $expectedNotificationDate = clone $this->getInitialNotificationDate();
        if ($date->format('H') >= 15) {
            $expectedNotificationDate = $this->subBusinessDays($expectedNotificationDate, 1);
        }

        return $expectedNotificationDate < $date;
    }

    /**
     * Sets the bank account's mandate to cancelled, saves the name of the origin of the cancellation, and the reason
     * code given.
     * @param string $canceller is the name of the cancelling event. This is either arudd, auddis, or sosure.
     * @param string $reason    is the reason code given by the bacs system, or information given by sosure.
     */
    public function cancelMandate($canceller, $reason)
    {
        $this->setMandateStatus(self::MANDATE_CANCELLED);
        $this->setMandateCanceller($canceller);
        $this->setMandateCancelledReason($reason);
    }

    public function isMandateInvalid()
    {
        return in_array($this->getMandateStatus(), [
            self::MANDATE_FAILURE,
            self::MANDATE_CANCELLED
        ]);
    }

    public function allowedSubmission(\DateTime $now = null)
    {
        if (!$now) {
            $now = \DateTime::createFromFormat('U', time());
        }
        $now = $this->startOfDay($now);

        if (!$this->getInitialPaymentSubmissionDate()) {
            throw new \Exception(sprintf(
                'Missing initial payment submission date for ref %s',
                $this->getReference()
            ));
        }

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
        // make sure we don't continue processing where we shouldn't
        if ($this->shouldCancelMandate($processingDate)) {
            return false;
        }

        return $this->allowedProcessingDate($processingDate);
    }

    public function getMaxAllowedProcessingDay(\DateTime $processingDate = null)
    {
        if (!$processingDate) {
            $processingDate = \DateTime::createFromFormat('U', time());
            $processingDate = $this->addBusinessDays($processingDate, 1);
        }

        $maxAllowedDate = $this->setDayOfMonth($processingDate, $this->getNotificationDay());

        // if we're processing for the previous month, we should be using the previous month's date (e.g feb)
        if ($processingDate->format('j') < $this->getNotificationDay()) {
            $maxAllowedDate = $maxAllowedDate->sub(new \DateInterval('P1M'));
        }

        $maxAllowedDate = $this->addBusinessDays($maxAllowedDate, 3);
        $maxAllowedDate = $this->startOfDay($maxAllowedDate);
        $maxAllowedDay = $maxAllowedDate->format('j');

        return $maxAllowedDay;
    }

    /**
     * Gives you a text string explaining the mandate cancellation reason code.
     * @return string|null containing the explanation. If the mandate is not cancelled then it will be null.
     */
    public function getSimplifiedMandateCancelledReason()
    {
        if (!$this->mandateCanceller) {
            return null;
        } elseif ($this->mandateCanceller == self::CANCELLER_SOSURE) {
            return $this->mandateCancelledReason;
        } else {
            $description = "";
            switch ($this->mandateCancelledReason) {
                case 0:
                    $description = 'cancelled by bank, refer to user';
                    break;
                case 1:
                    $description = 'instruction cancelled by user';
                    break;
                case 2:
                    $description = 'user deceased';
                    break;
                case 3:
                    $description = 'account transferred to new bank';
                    break;
                case 5:
                    $description = 'no account';
                    break;
                case 6:
                    $description = 'no mandate';
                    break;
                case 7:
                    $description = 'ddi amount not zero';
                    break;
                case 'B':
                    $description = 'account closed';
                    break;
                case 'C':
                    $description = 'account transferred to new branch of bank';
                    break;
                case 'D':
                    $description = 'advance notice of mandate disputed';
                    break;
                case 'E':
                    $description = 'mandate amended';
                    break;
                case 'F':
                    $description = 'invalid account type';
                    break;
                case 'G':
                    $description = 'bank will not accept direct debits on this account';
                    break;
                case 'H':
                    $description = 'mandate has expired';
                    break;
                case 'I':
                    $description = 'payer reference is not unique';
                    break;
                case 'K':
                    $description = 'mandate cancelled by paying bank';
                    break;
                case 'L':
                    $description = 'incorrect user\'s account details';
                    break;
                case 'M':
                    $description = 'transaction Code / user status incompatible';
                    break;
                case 'N':
                    $description = 'Transaction Disallowed at user\'s branch';
                    break;
                case 'O':
                    $description = 'invalid reference';
                    break;
                case 'P':
                    $description = 'user\'s name not present';
                    break;
                case 'Q':
                    $description = 'services user\'s name is blank';
                    break;
                case 'R':
                    $description = 'mandate reinstated';
                    break;
            }
            return "{$this->madateCancelledReason}: {$description}.";
        }
    }

    public function getNotificationDay()
    {
        return $this->getNotificationDate()->format('j');
    }

    public function getNotificationDate()
    {
        return $this->isFirstPayment() ?
            $this->getInitialNotificationDate() :
            $this->getStandardNotificationDate();
    }

    public function allowedProcessingDate(\DateTime $processingDate = null)
    {
        if (!$processingDate) {
            $processingDate = \DateTime::createFromFormat('U', time());
            $processingDate = $this->addBusinessDays($processingDate, 1);
        }
        $processingDate = $this->startOfDay($processingDate);
        $processingDay = $processingDate->format('j');

        // Rule: Service users must collect the Direct Debit on or within 3 working days after the specified due date
        // as advised to the payer on the advance notice.
        $minAllowedDay = $this->getNotificationDay();

        /*
        $maxAllowedDate = clone $this->getStandardNotificationDate();
        $maxAllowedDate = $this->addBusinessDays($maxAllowedDate, 3);
        $maxAllowedDate = $this->startOfDay($maxAllowedDate);
        $maxAllowedDay = $maxAllowedDate->format('j');
        */

        $maxAllowedDay = $this->getMaxAllowedProcessingDay($processingDate);

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

    public function shouldCancelMandate(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now', SoSure::getSoSureTimezone());
        }

        // Mandate was never setup or already cancelled
        if (in_array($this->getMandateStatus(), [self::MANDATE_CANCELLED, self::MANDATE_FAILURE])) {
            return null;
        }

        $lastSuccessfulPaymentDate = $this->getLastSuccessfulPaymentDate();
        if (!$lastSuccessfulPaymentDate) {
            if ($this->getInitialPaymentSubmissionDate()) {
                $lastSuccessfulPaymentDate = $this->getInitialPaymentSubmissionDate();
            } else {
                $lastSuccessfulPaymentDate = $this->created;
            }
        }

        $diff = $lastSuccessfulPaymentDate->diff($date);
        // 13 months is absolute max. we can take a conserviate view on this number (min possible) as do not
        // expect to reach this at all
        $thirteenMonthsInDays = 365 + 28;
        if ($diff->days >= $thirteenMonthsInDays && !$diff->invert) {
            return true;
        } else {
            return false;
        }
    }

    public function getPaymentDate(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime('now', SoSure::getSoSureTimezone());
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

    public function getFirstPaymentDateForPolicy(Policy $policy, \DateTime $date = null)
    {
        if (!$date) {
            $date = $this->now(SoSure::getSoSureTimezone());
        }
        $useClosestPaymentDate = false;
        $nextPolicyPaymentDate = null;
        if (!$policy->isPolicyPaidToDate($date, false, true)) {
            $useClosestPaymentDate = true;
        } else {
            $nextPolicyPaymentDate = $policy->getNextBillingDate($date);
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

    public function toApiArray()
    {
        $data = [
            'bank_name' => $this->getBankName(),
            'account_name' => $this->getAccountName(),
            'displayable_sort_code' => $this->getDisplayableSortCode(),
            'displayable_account_number' => $this->getDisplayableAccountNumber(),
            'bank_address' => $this->getBankAddress() ? $this->getBankAddress()->toApiArray() : null,
            'mandate' => $this->getReference(),
            'mandate_status' => $this->getMandateStatus(),
            'initial_notification_date' => $this->getInitialNotificationDate() ?
                $this->getInitialNotificationDate()->format(\DateTime::ATOM) :
                null,
            'standard_notification_day' => $this->getStandardNotificationDate() ?
                $this->getStandardNotificationDate()->format("d") :
                null
        ];

        return $data;
    }

    public function toDetailsArray()
    {
        $data = [
            'bank_name' => $this->getBankName(),
            'account_name' => $this->getAccountName(),
            'displayable_sort_code' => $this->getDisplayableSortCode(),
            'displayable_account_number' => $this->getDisplayableAccountNumber(),
            'bank_address' => $this->getBankAddress() ? $this->getBankAddress()->toApiArray() : null,
            'mandate' => $this->getReference(),
            'mandate_status' => $this->getMandateStatus(),
            'mandate_serial_number' => $this->getMandateSerialNumber(),
            'initial_date' => $this->getInitialPaymentSubmissionDate() ?
                $this->getInitialPaymentSubmissionDate()->format(\DateTime::ATOM) :
                null,
            'monthly_day' => $this->getStandardNotificationDate() ?
                $this->getStandardNotificationDate()->format('jS') :
                null,
        ];

        return $data;
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
