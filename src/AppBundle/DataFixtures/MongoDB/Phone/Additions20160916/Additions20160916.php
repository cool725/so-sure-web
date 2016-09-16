<?php

namespace AppBundle\DataFixtures\MongoDB\Phone\Additions20160101;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\Phone\LoadPhoneData;

class Additions20160916 extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20160916.csv');
    }
}
