<?php

namespace AppBundle\Repository;

use AppBundle\Document\BankAccount;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use \AppBundle\Classes\NoOp;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

/**
 * Repository for queries that are specifically about helvetia phone policies.
 */
class HelvetiaPhonePolicyRepository extends PhonePolicyRepository
{
    /**
     * Gives you all helvetia policies for export.
     * @return Cursor to the set of found policies for export.
     */
    public function getAllPoliciesForExport()
    {
        $policy = new HelvetiaPhonePolicy();
        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_PICSURE_REQUIRED,
                Policy::STATUS_CANCELLED,
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
                Policy::STATUS_UNPAID
            ])
            ->field('premiumInstallments')->gt(0)
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
        return $qb->getQuery()->execute();
    }

    /**
     * Gives you all the expired helvetia policies for export.
     * @return Cursor to the set of found policies for export.
     */
    public function getAllExpiredPoliciesForExport()
    {
        $policy = new HelvetiaPhonePolicy();
        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
            ])
            ->field('policyNumber')->equals(new \MongoRegex(self::VALID_REGEX));
        return $qb->getQuery()->execute();
    }
}
