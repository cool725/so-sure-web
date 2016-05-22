<?php

namespace AppBundle\Repository;

use AppBundle\Document\Phone;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class PhoneRepository extends DocumentRepository
{
    use PhoneTrait;

    public function alternatives(Phone $phone)
    {
        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('replacementPrice')->gt(0));
        $qb->addAnd($qb->expr()->field('os')->equals($phone->getOs()));
        $qb->addAnd($qb->expr()->field('memory')->gte($phone->getMemory()));
        $qb->addAnd($qb->expr()->field('processorCores')->gte($phone->getProcessorCores()));
        //$qb->addAnd($qb->expr()->field('processorSpeed')->gte($phone->getProcessorSpeed() - 200));
        $qb->addAnd($qb->expr()->field('camera')->gte($phone->getCamera() - 3));
        if ($phone->getLte()) {
            $qb->addAnd($qb->expr()->field('lte')->equals(true));
        }
        if ($phone->getSsd()) {
            $qb->addAnd($qb->expr()->field('ssd')->equals(true));
        }

        return $qb
            ->getQuery()
            ->execute();
    }
}
