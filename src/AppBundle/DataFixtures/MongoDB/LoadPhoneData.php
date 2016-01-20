<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\Phone;

class LoadPhoneData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $this->newPhone($manager, 'Apple', 'iPhone 6', '16GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6', '64GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6', '128GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6', 'Plus 16GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6', 'Plus 64GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6', 'Plus 128GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', '16GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', '64GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', '128GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', 'Plus 16GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', 'Plus 64GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 6s', 'Plus 128GB', 6.99);
        $this->newPhone($manager, 'Apple', 'iPhone 5s', '16GB', 5.49);
        $this->newPhone($manager, 'Apple', 'iPhone 5s', '32GB', 5.49);
        $this->newPhone($manager, 'Apple', 'iPhone 5s', '64GB', 5.49);
        $this->newPhone($manager, 'Apple', 'iPhone 5c', '8GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 5c', '16GB', 5.49);
        $this->newPhone($manager, 'Apple', 'iPhone 5c', '32GB', 5.49);
        $this->newPhone($manager, 'Apple', 'iPhone 5', '16GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 5', '32GB', 5.49);
        $this->newPhone($manager, 'Apple', 'iPhone 5', '64GB', 5.49);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', '8GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', '16GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', '32GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 4s', '64GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 4', '8GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 4', '16GB', 4.99);
        $this->newPhone($manager, 'Apple', 'iPhone 4', '32GB', 4.99);
        $manager->flush();
    }

    private function newPhone($manager, $make, $model, $details, $policyPrice)
    {
        $phone = new Phone();
        $phone->init($make, $model, $details, $policyPrice);
        $manager->persist($phone);
    }
}
