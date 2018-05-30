<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20180530;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Additions20180530 extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20180530.csv', new \DateTime('2018-05-30'));
    }
}
