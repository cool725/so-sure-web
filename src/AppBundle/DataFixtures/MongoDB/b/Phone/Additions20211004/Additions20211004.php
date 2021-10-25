<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20211004;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Additions20211004 extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20211004.csv', new \DateTime('2021-10-04'));
    }
}
