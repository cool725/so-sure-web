<?php

namespace AppBundle\Repository;

use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use \AppBundle\Classes\NoOp;
use Doctrine\ODM\MongoDB\DocumentRepository;
use Doctrine\ODM\MongoDB\Cursor;

/**
 * Repository for queries that are specifically about salva phone policies.
 */
class SalvaPhonePolicyRepository extends PhonePolicyRepository
{
    /**
     * Gives you all salva policies for export.
     * @param \DateTime $date        is not used but is present for some reason.
     * @param string    $environment is the environment that the query is being run in which determines whether to use
     *                               the real policy number prefix or the test one.
     * @return Cursor to the set of found policies for export.
     */
    public function getAllPoliciesForExport(\DateTime $date, $environment)
    {
        NoOp::ignore([$date]);
        $policy = new SalvaPhonePolicy();
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
            ->field('premiumInstallments')->gt(0);
        if ($environment == 'prod') {
            $qb->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));
        } else {
            $qb->field('policyNumber')->notEqual(null);
        }
        return $qb->getQuery()->execute()->toArray();
    }

    /**
     * Gives you all the expired policies for export.
     * @param \DateTime $date        is not used but must be present for whatever reason.
     * @param string    $environment is the environment that the query is being run in which determines whether to use
     *                               the real policy number prefix or the test one.
     * @return Cursor to the set of found policies for export.
     */
    public function getAllExpiredPoliciesForExport(\DateTime $date, $environment)
    {
        NoOp::ignore([$date]);
        $policy = new SalvaPhonePolicy();
        $qb = $this->createQueryBuilder()
            ->field('status')->in([
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
            ]);
        if ($environment == 'prod') {
            $qb->field('policyNumber')->equals(new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix())));
        } else {
            $qb->field('policyNumber')->notEqual(null);
        }
        return $qb->getQuery()->execute();
    }
}
