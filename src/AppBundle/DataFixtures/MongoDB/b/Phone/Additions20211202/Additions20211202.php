<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20211202;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Additions20211202 extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20211202.csv', new \DateTime('2021-12-02'));
    }
}
