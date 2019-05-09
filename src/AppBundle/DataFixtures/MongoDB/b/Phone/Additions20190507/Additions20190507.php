<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20190507;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Additions20190507 extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20190507.csv', new \DateTime('2019-05-07'));
    }
}
