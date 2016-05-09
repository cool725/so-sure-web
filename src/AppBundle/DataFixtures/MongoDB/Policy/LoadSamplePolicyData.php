<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
//use AppBundle\Document\PolicyKeyFacts;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Connection;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;

// @codingStandardsIgnoreFile
class LoadSamplePolicyData implements FixtureInterface, ContainerAwareInterface
{
     /**
     * @var ContainerInterface
     */
    private $container;

    private $faker;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->faker = Faker\Factory::create('en_GB');

        //$this->newPolicyKeyFacts($manager);
        $this->newPolicyTerms($manager);
        $manager->flush();

        $users = $this->newUsers($manager);
        $manager->flush();

        $count = 0;
        foreach ($users as $user) {
            $this->newPolicy($manager, $user, $count);
            $count++;
        }

        foreach ($users as $user) {
            $this->addConnections($manager, $user, $users);
        }

        $manager->flush();
    }

    private function newPolicyKeyFacts($manager)
    {
        $policyKeyFacts = new PolicyKeyFacts();
        $policyKeyFacts->setLatest(true);
        $manager->persist($policyKeyFacts);
    }

    private function newPolicyTerms($manager)
    {
        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(true);
        $manager->persist($policyTerms);
    }

    private function newUsers($manager)
    {
        $users = [];
        for ($i = 1; $i <= 20; $i++) {
            $user = new User();
            $user->setEmail($this->faker->email);
            $user->setFirstName($this->faker->firstName);
            $user->setLastName($this->faker->lastName);
            $user->setMobileNumber($this->faker->mobileNumber);

            $address = new Address();
            $address->setType(Address::TYPE_BILLING);
            $address->setLine1($this->faker->streetAddress);
            $address->setCity($this->faker->city);
            $address->setPostcode($this->faker->address);

            $user->addAddress($address);
            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }
    
    private function newPolicy($manager, $user, $count)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phones = $phoneRepo->findAll();
        $phone = null;
        while ($phone == null) {
            $phone = $phones[rand(0, count($phones) - 1)];
            if (!$phone->getCurrentPhonePrice()) {
                $phone = null;
            }
        }
        $policy = new PhonePolicy();
        $policy->setPhone($phone);
        $policy->create(-5000 + $count);
        $user->addPolicy($policy);
        
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $policy->setPolicyTerms($latestTerms);
        /*
        $policy->addPolicyDocument($latestTerms);

        $policyKeyFactsRepo = $dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        $policy->addPolicyDocument($latestKeyFacts);
        */
        $manager->persist($policy);
    }

    private function addConnections($manager, $userA, $users)
    {
        $policyA = $userA->getPolicies()[0];
        $connections = rand(0, $policyA->getMaxConnections());
        $connections = rand(0, 4);
        for ($i = 0; $i < $connections; $i++) {
            $userB = $users[rand(0, count($users) - 1)];
            $policyB = $userB->getPolicies()[0];

            $connectionA = new Connection();
            $connectionA->setUser($userA);
            $connectionA->setPolicy($policyA);
            $connectionA->setValue($policyB->getAllowedConnectionValue());

            $connectionB = new Connection();
            $connectionB->setUser($userB);
            $connectionB->setPolicy($policyB);
            $connectionB->setValue($policyA->getAllowedConnectionValue());
    
            $policyA->addConnection($connectionB);
            $policyA->updatePotValue();
    
            $policyB->addConnection($connectionA);
            $policyB->updatePotValue();
    
            $manager->persist($connectionA);
            $manager->persist($connectionB);            
        }
    }

    private function createPolicies($manager, $users)
    {
        $userA = new User();
        $userA->setEmail(sprintf('policyA@policy.so-sure.net'));
        $userA->setFirstName(sprintf('firstA'));
        $userA->setLastName(sprintf('lastA'));

        $userB = new User();
        $userB->setEmail(sprintf('policyB@policy.so-sure.net'));
        $userB->setFirstName(sprintf('firstB'));
        $userB->setLastName(sprintf('lastB'));

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('123 Policy');
        $address->setCity('London');
        $address->setPostcode('BX11LT');

        $userA->addAddress($address);
        $manager->persist($userA);

        $userB->addAddress($address);
        $manager->persist($userB);

        $phoneRepo = $manager->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['model' => 'iPhone 5', 'memory' => 64]);
        if (!$phone->getCurrentPhonePrice()) {
            throw new \Exception('Failed to load phone price');
        }
        //\Doctrine\Common\Util\Debug::dump($phone);
        //\Doctrine\Common\Util\Debug::dump($phone->getPhonePrices());
        //\Doctrine\Common\Util\Debug::dump($phone->getCurrentPhonePrice());
        $policyA = new PhonePolicy();
        $policyA->setUser($userA);
        $policyA->setPhone($phone);
        $policyA->create(-5000);

        $policyB = new PhonePolicy();
        $policyB->setUser($userB);
        $policyB->setPhone($phone);
        $policyB->create(-4999);

        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $policyA->addPolicyDocument($latestTerms);
        $policyB->addPolicyDocument($latestTerms);

        $policyKeyFactsRepo = $dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        $policyA->addPolicyDocument($latestKeyFacts);
        $policyB->addPolicyDocument($latestKeyFacts);

        $connectionA = new Connection();
        $connectionA->setUser($userB);
        $connectionA->setPolicy($policyB);
        $connectionA->setValue(10);

        $connectionB = new Connection();
        $connectionB->setUser($userA);
        $connectionB->setPolicy($policyA);
        $connectionB->setValue(10);

        $policyA->addConnection($connectionA);
        $policyA->updatePotValue();

        $policyB->addConnection($connectionB);
        $policyB->updatePotValue();

        $manager->persist($connectionA);
        $manager->persist($connectionB);
        //\Doctrine\Common\Util\Debug::dump($policy);
        $manager->persist($policyA);
        $manager->persist($policyB);
    }
}
