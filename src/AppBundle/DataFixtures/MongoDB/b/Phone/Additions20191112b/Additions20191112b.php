<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Additions20191112b;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Additions20191112b extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv($manager, '20191112b.csv', new \DateTime('2019-11-12'));
    }
}
