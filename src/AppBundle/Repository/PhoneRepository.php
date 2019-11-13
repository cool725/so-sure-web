<?php

namespace AppBundle\Repository;

use AppBundle\Document\Phone;
use AppBundle\Document\PhoneTrait;
use Doctrine\ODM\MongoDB\DocumentRepository;

class PhoneRepository extends DocumentRepository
{
    use PhoneTrait;

    public function findActive()
    {
        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('os')->in([
            Phone::OS_CYANOGEN,
            Phone::OS_ANDROID,
            Phone::OS_IOS,
            Phone::OS_WINDOWS,
        ]));
        $qb->addAnd($qb->expr()->field('make')->notEqual("ALL"));
        $qb->addAnd($qb->expr()->field('phonePrices')->notEqual(null));
        $qb->addAnd($qb->expr()->field('active')->equals(true));
        $qb->sort('make', 'asc')
            ->sort('model', 'asc')
            ->sort('memory', 'asc');

        return $qb;
    }

    public function findActiveMakes()
    {
        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('os')->in([Phone::OS_CYANOGEN, Phone::OS_ANDROID, Phone::OS_IOS]));
        $qb->addAnd($qb->expr()->field('make')->notEqual("ALL"));
        $qb->addAnd($qb->expr()->field('active')->equals(true));
        $qb->distinct('make');
        $qb->sort('make', 'asc');

        $makes = [];
        $items = $qb->getQuery()->execute();
        foreach ($items as $make) {
            $makes[$make] = $make;
        }
        ksort($makes);

        // move samsung & apple to top of the list
        if (isset($makes['Samsung'])) {
            $makes = ['Samsung' => $makes['Samsung']] + $makes;
        }
        if (isset($makes['Apple'])) {
            $makes = ['Apple' => $makes['Apple']] + $makes;
        }

        return $makes;
    }

    public function findActiveModels($make)
    {
        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('os')->in([Phone::OS_CYANOGEN, Phone::OS_ANDROID, Phone::OS_IOS]));
        $qb->addAnd($qb->expr()->field('make')->notEqual("ALL"));
        $qb->addAnd($qb->expr()->field('make')->equals($make));
        $qb->addAnd($qb->expr()->field('active')->equals(true));
        $qb->sort('make', 'asc')
            ->sort('model', 'asc')
            ->sort('memory', 'asc');

        return $qb->getQuery()->execute();
    }

    public function findActiveInactive()
    {
        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('os')->in([
            Phone::OS_CYANOGEN,
            Phone::OS_ANDROID,
            Phone::OS_IOS,
            Phone::OS_WINDOWS,
        ]));
        $qb->addAnd($qb->expr()->field('make')->notEqual("ALL"));
        $qb->addAnd($qb->expr()->field('phonePrices')->notEqual(null));
        $qb->sort('make', 'asc')
            ->sort('model', 'asc')
            ->sort('memory', 'asc');

        return $qb;
    }

    public function alternatives(Phone $phone)
    {
        $qb = $this->createQueryBuilder();
        $qb->addAnd($qb->expr()->field('replacementPrice')->gt(0));
        if ($phone->getOs() == Phone::OS_CYANOGEN) {
            $qb->addAnd($qb->expr()->field('os')->in([Phone::OS_CYANOGEN, Phone::OS_ANDROID]));
        } else {
            $qb->addAnd($qb->expr()->field('os')->equals($phone->getOs()));
        }
        $device = $phone->getDevices()[0];
        $qb->addOr($qb->expr()->field('memory')->gte($phone->getMemory()));
        $qb->addOr($qb->expr()->field('devices')->equals($device));
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

    public function findMatchingMakes($make)
    {
        return $qb = $this->createQueryBuilder()
            ->field('makeCanonical')
            ->equals(mb_strtolower($make))
            ->distinct('make')
            ->getQuery()
            ->execute();
    }

    public function findMatchingModels($make, $model)
    {
        return $qb = $this->createQueryBuilder()
            ->field('makeCanonical')
            ->equals(mb_strtolower($make))
            ->field('modelCanonical')
            ->equals(mb_strtolower($model))
            ->distinct('model')
            ->getQuery()
            ->execute();
    }

    public function alreadyExists($make, $model, $memory)
    {
        return $this->createQueryBuilder()
                ->field('make')->equals($make)
                ->field('model')->equals($model)
                ->field('memory')->equals(floatval($memory))
                ->getQuery()
                ->execute()
                ->count() > 0;
    }
}
