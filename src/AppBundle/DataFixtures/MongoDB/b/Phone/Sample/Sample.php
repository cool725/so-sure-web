<?php

namespace AppBundle\DataFixtures\MongoDB\b\Phone\Sample;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\DataFixtures\MongoDB\b\Phone\LoadPhoneData;

class Sample extends LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $phone = new Phone();
        $phone->setMake('Apple');
        $phone->setModel('Upcoming');
        $phone->setActive(true);
        $phone->setOs(Phone::OS_IOS);
        $manager->persist($phone);

        $phone = new Phone();
        $phone->setMake('Apple');
        $phone->setModel('Upcoming Z');
        $phone->setMemory(64);
        $phone->setActive(true);
        $phone->setDevices(['upcoming-z']);
        $phone->setOs(Phone::OS_IOS);
        $price = new PhonePrice();
        $price->setGwp(5);
        $price->setValidFrom(\DateTime::createFromFormat('U', time()));
        $phone->addPhonePrice($price);
        $manager->persist($phone);

        $phone = new Phone();
        $phone->setMake('Apple');
        $phone->setModel('Upcoming Z');
        $phone->setMemory(256);
        $phone->setActive(false);
        $phone->setDevices(['upcoming-z']);
        $phone->setOs(Phone::OS_IOS);
        $manager->persist($phone);

        $manager->flush();
    }
}
