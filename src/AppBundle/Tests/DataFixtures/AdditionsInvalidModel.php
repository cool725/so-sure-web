<?php

namespace AppBundle\Tests\DataFixtures;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class AdditionsInvalidModel extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->loadCsv(
            $manager,
            'InvalidModel.csv',
            new \DateTime('2018-10-16'),
            'src/AppBundle/Tests/DataFixtures'
        );
    }
}
