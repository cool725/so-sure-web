<?php

namespace AppBundle\Repository;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

class PolicyRepository extends BaseDocumentRepository
{
    use CurrencyTrait;
    use DateTrait;

    const VALID_REGEX = '/^((?!INVALID).)*$/';

    public function isPromoLaunch()
    {
        return $this->countAllPolicies() < 1000;
    }

    /**
     * All policies that have been created (excluding so-sure test ones)
     */
    public function countAllPolicies()
    {
        return $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_PICSURE_REQUIRED,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
                Policy::STATUS_UNPAID
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
            ->getQuery()
            ->execute()
            ->count();
    }

    /**
     * Gives you all policies that are "current" in the sense that they are in either the status active or unpaid.
     * @return Cursor with the full set of results.
     */
    public function findCurrentPolicies()
    {
        return $this->createQueryBuilder()
            ->field("status")->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
            ])
            ->getQuery()
            ->execute();
    }

    public function findPoliciesForPendingCancellation($includeFuture, \DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));

        if ($includeFuture) {
            $qb = $qb->field('pendingCancellation')->notEqual(null);
        } else {
            $qb = $qb->field('pendingCancellation')->lte($date);
        }

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findScodePolicies()
    {
        return $this->createQueryBuilder()
            ->field('scodes')->exists(true)
            ->getQuery()
            ->execute();
    }

    public function findPoliciesForPendingRenewal($date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $renewalDate = clone $date;
        $renewalDate = $renewalDate->add(new \DateInterval(sprintf('P%dD', Policy::RENEWAL_DAYS)));

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
            ->field('nextPolicy.$id')->equals(null)
            ->field('end')->lte($renewalDate)
            ->field('end')->gte($date);

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findPoliciesForExpiration($date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
            ->field('end')->lte($date);

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findPoliciesForFullExpiration($date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
            $date = $date->sub(new \DateInterval('P28D'));
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
            ->field('end')->lte($date);

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findUnpaidPoliciesWithCancelledMandates()
    {
        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
            ->field('paymentMethod.bankAccount.mandateStatus')->in([
                BankAccount::MANDATE_CANCELLED,
                BankAccount::MANDATE_FAILURE
            ]);

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findRenewalPoliciesForActivation($date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_RENEWAL,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
            ->field('start')->lte($date);

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findPendingRenewalPoliciesForRenewed(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_PENDING_RENEWAL,
            ])
            ->field('renewalExpiration')->lte($date);

        return $qb
            ->getQuery()
            ->execute();
    }

    public function findDeclinedRenewalPoliciesForUnRenewed(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }

        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_DECLINED_RENEWAL,
            ])
            ->field('renewalExpiration')->lte($date);

        return $qb
            ->getQuery()
            ->execute();
    }

    public function getWeeklyEmail()
    {
        $lastWeek = \DateTime::createFromFormat('U', time());
        $lastWeek->sub(new \DateInterval('P1W'));
        $sixtyDays = \DateTime::createFromFormat('U', time());
        $sixtyDays->sub(new \DateInterval('P60D'));
        $policy = new HelvetiaPhonePolicy();

        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
        ]));
        $qb->addAnd($qb->expr()->field('start')->gt($sixtyDays));
        $qb->addAnd(
            $qb->expr()->addOr($qb->expr()->field('lastEmailed')->lte($lastWeek))
                ->addOr($qb->expr()->field('lastEmailed')->exists(false))
        );
        $qb->addAnd($qb->expr()->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX)));

        return $qb->getQuery()->execute();
    }

    public function findPendingMandates(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $date = $this->endOfDay($date);

        $qb = $this->createQueryBuilder()
            ->field('paymentMethod.bankAccount.mandateStatus')->equals(BankAccount::MANDATE_PENDING_APPROVAL)
            ->field('paymentMethod.bankAccount.initialPaymentSubmissionDate')->lt($date)
        ;

        return $qb;
    }

    public function findDuplicateMandates($mandate)
    {
        $qb = $this->createQueryBuilder()
            ->field('paymentMethod.bankAccount.reference')->equals($mandate)
        ;

        return $qb->getQuery()->execute();
    }

    public function findBankAccount(Policy $policy)
    {
        $qb = $this->createQueryBuilder();
        if ($policy->getUser()) {
            $qb->field('user.$id')->notIn([new \MongoId($policy->getUser()->getId())]);
        }

        if ($policy->getPaymentMethod() instanceof JudoPaymentMethod) {
            $accountHash = $policy->getJudoPaymentMethod() ?
                $policy->getJudoPaymentMethod()->getCardTokenHash() :
                ['NotAHash'];
            if (!$accountHash) {
                $accountHash = ['NotAHash'];
            }
            $qb->field('paymentMethod.cardTokenHash')->equals($accountHash);
        } elseif ($policy->getPaymentMethod() instanceof BacsPaymentMethod) {
            $accountHash = $policy->getBacsBankAccount() ?
                $policy->getBacsBankAccount()->getHashedAccount() : 'NotAHash';
            $qb->field('paymentMethod.bankAccount.hashedAccount')->equals($accountHash);
        } else {
            throw new \Exception('Policy is missing a payment type');
        }

        return $qb
            ->getQuery()
            ->execute();
    }

    /**
     * Finds a list of all expired policies which have been renewed and do not have either a cashback or a discout on
     * the renewal policy despite having pot value.
     * @return array containing all such policies.
     */
    public function findBlockedDiscountPolicies()
    {
        $qb = $this->createQueryBuilder()
            ->field('nextPolicy')->notEqual(null)
            ->field('potValue')->gt(0)
            ->field('cashback')->exists(false);
        return array_filter($qb->getQuery()->execute()->toArray(), function ($policy) {
            $next = $policy->getNextPolicy();
            if ($next->getPremium()->getAnnualDiscount() > 0) {
                return false;
            }
            if ($policy->hasOpenClaim() || $policy->hasOpenNetworkClaim()) {
                return false;
            }
            if (!$policy->getConnections()) {
                return false;
            }
            if ($policy->hasMonetaryClaimed() || count($policy->getNetworkClaims(true, true)) > 0) {
                return false;
            }
            return true;
        });
    }

    /**
     * Removes every hubspot id on a policy in the system. This is used with a mass delete to remove all references to
     * data that has been deleted on hubspot already.
     */
    public function removeHubspotIds()
    {
        $this->createQueryBuilder()->updateMany()->field("hubspotId")->unsetField()->getQuery()->execute();
    }

    /**
     * Gives a list of policies that have been called for being unpaid optionally within a set of dates.
     * @param \DateTime $start is the first date that the calls can be within or null for whenever.
     * @param \DateTime $end   is the date that the calls must be before.
     * @return array containing these called policies.
     */
    public function findUnpaidCalls(\DateTime $start = null, \DateTime $end = null)
    {
        $query = $this->createQueryBuilder();
        $noteQuery = $query->expr()
            ->field('type')->equals('call');
        if ($start) {
            $noteQuery->field('date')->gte($start);
        }
        if ($end) {
            $noteQuery->field('date')->lt($end);
        }
        $query->field('notesList')->elemMatch($noteQuery);
        return $query->getQuery()->execute();
    }

    /**
     * Finds all policies that have a hubspot id in groups of some size.
     * @param int $n is the max size of each group.
     * @return \Generator which lets you iterate over each group.
     */
    public function findHubspotPoliciesGrouped($n = 100)
    {
        $start = 0;
        while (true) {
            $policies = $this->createQueryBuilder()
            ->field('hubspotId')->exists(true)
            ->skip($start)->limit($n)
            ->getQuery()->execute();
            if ($policies->dead()) {
                return;
            } else {
                yield $policies;
            }
            $start += $n;
        }
    }

    /**
     * Finds all of the policies that should be subject to policy validations.
     * @return array containing all of the relevant policies.
     */
    public function findValidationPolicies()
    {
        return $this->createQueryBuilder()
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX))
            ->getQuery()->execute()->toArray();
    }

    /**
     * This gets the latest policy with the given bacs reference. First it looks for a policy in an active status, and
     * if it does not find one then it just returns the one that started most recently.
     * @param string $reference is the bacs reference to seek.
     * @return Policy|null the policy found or null if it could not find one.
     */
    public function getLatestPolicyByBacsReference($reference)
    {
        $policy = $this->createQueryBuilder()
            ->field('paymentMethod.bankAccount.reference')->equals($reference)
            ->field('status')->in([Policy::activeStatuses])
            ->getQuery()
            ->getSingleResult();
        if ($policy) {
            return $policy;
        }
        return $this->createQueryBuilder()
            ->field('paymentMethod.bankAccount.reference')->equals($reference)
            ->sort('start', 'desc')
            ->getQuery()
            ->getSingleResult();

    }
}
