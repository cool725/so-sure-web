<?php

namespace AppBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\DateTrait;

class PolicyRepository extends DocumentRepository
{
    use DateTrait;

    public function isPromoLaunch()
    {
        return $this->countAllPolicies() < 1000;
    }

    public function getWeeklyEmail($environment)
    {
        $lastWeek = new \DateTime();
        $lastWeek->sub(new \DateInterval('P1W'));
        $sixtyDays = new \DateTime();
        $sixtyDays->sub(new \DateInterval('P60D'));
        $policy = new PhonePolicy();

        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('status')->in([
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID
        ]));
        $qb->addAnd($qb->expr()->field('start')->gt($sixtyDays));
        $qb->addAnd(
            $qb->expr()->addOr($qb->expr()->field('lastEmailed')->lte($lastWeek))
                ->addOr($qb->expr()->field('lastEmailed')->exists(false))
        );

        if ($environment == "prod") {
            $prodPolicyRegEx = new \MongoRegex(sprintf('/^%s\//', $policy->getPolicyNumberPrefix()));
            $qb->addAnd($qb->expr()->field('policyNumber')->equals($prodPolicyRegEx));
        } else {
            $qb->addAnd($qb->expr()->field('policyNumber')->notEqual(null));
        }

        return $qb->getQuery()
            ->execute();
    }
}
