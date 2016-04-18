<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\User;
use AppBundle\Document\Connection;

// @codingStandardsIgnoreFile
class LoadSamplePolicyData implements FixtureInterface
{
    public function load(ObjectManager $manager)
    {
        $users = $this->newUsers($manager);
        $manager->flush();

        $this->newPolicy($manager, $users);
        $manager->flush();
    }

    private function newUsers($manager)
    {
        $users = [];
        for ($i = 1; $i <= 8; $i++) {
            $user = new User();
            $user->setEmail(sprintf('user%d@policy.so-sure.net', $i));
            $user->setFirstName(sprintf('first-%d', $i));
            $user->setLastName(sprintf('last-%d', $i));            
            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }

    private function newPolicy($manager, $users)
    {
        $user = new User();
        $user->setEmail(sprintf('policy@policy.so-sure.net'));
        $user->setFirstName(sprintf('first'));
        $user->setLastName(sprintf('last'));            
        $manager->persist($user);

        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->create(1);

        $connection = new Connection();
        $connection->setUser($users[0]);
        $connection->setValue(10);
        $policy->addConnection($connection);
        $policy->setPotValue($policy->calculatePotValue());
        $manager->persist($connection);
        //\Doctrine\Common\Util\Debug::dump($policy);
        $manager->persist($policy);
    }
}
