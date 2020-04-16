<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20200416b;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Additions20200416b extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20200416b.csv', new \DateTime('2020-04-16'));
    }
}
