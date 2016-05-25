<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyKeyFacts;
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
    use \AppBundle\Tests\UserClassTrait;

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

        $this->newPolicyKeyFacts($manager);
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

        // Sample user for apple
        $user = $this->newUser('julien+apple@so-sure.com');
        $user->setPlainPassword('test');
        $user->setEnabled(true);
        $manager->persist($user);
        $this->newPolicy($manager, $user, $count);

        $manager->flush();
    }

    private function newPolicyKeyFacts($manager)
    {
        $policyKeyFacts = new PolicyKeyFacts();
        $policyKeyFacts->setLatest(true);
        $policyKeyFacts->setVersion('Version 1 May 2016');
        $manager->persist($policyKeyFacts);
    }

    private function newPolicyTerms($manager)
    {
        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(true);
        $policyTerms->setVersion('Version 1 May 2016');
        $manager->persist($policyTerms);
    }

    private function newUsers($manager)
    {
        $userRepo = $manager->getRepository(User::class);
        $users = [];
        for ($i = 1; $i <= 200; $i++) {
            $email = $this->faker->email;
            while ($userRepo->findOneBy(['email' => $email])) {
                $email = $this->faker->email;
            }
            $user = $this->newUser($email);
            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }
    
    private function newUser($email)
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($this->faker->firstName);
        $user->setLastName($this->faker->lastName);
        $user->setMobileNumber($this->faker->mobileNumber);

        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1($this->faker->streetAddress);
        $address->setCity($this->faker->city);
        $address->setPostcode($this->faker->address);

        $user->setBillingAddress($address);

        return $user;
    }

    private function newPolicy($manager, $user, $count)
    {
        $phoneRepo = $manager->getRepository(Phone::class);
        $phones = $phoneRepo->findAll();
        $phone = null;
        while ($phone == null) {
            $phone = $phones[rand(0, count($phones) - 1)];
            if (!$phone->getCurrentPhonePrice() || $phone->getMake() == "ALL") {
                $phone = null;
            }
        }
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $policyKeyFactsRepo = $dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        $startDate = new \DateTime();
        $startDate->sub(new \DateInterval(sprintf("P%dD", rand(0, 120))));
        $policy = new PhonePolicy();
        $policy->setPhone($phone);
        $policy->setImei($this->generateRandomImei());
        $policy->init($user, $latestTerms, $latestKeyFacts);
        $policy->create(-5000 + $count, null, $startDate);

        $manager->persist($policy);
    }

    private function addConnections($manager, $userA, $users)
    {
        $policyA = $userA->getPolicies()[0];
        //$connections = rand(0, $policyA->getMaxConnections());
        $connections = rand(0, 4);
        for ($i = 0; $i < $connections; $i++) {
            $userB = $users[rand(0, count($users) - 1)];
            $policyB = $userB->getPolicies()[0];
            if ($policyA->getId() == $policyB->getId()) {
                continue;
            }

            // only 1 connection for user
            foreach ($policyA->getConnections() as $connection) {
                if ($connection->getLinkedPolicy()->getId() == $policyB->getId()) {
                    continue;
                }
            }

            $connectionA = new Connection();
            $connectionA->setLinkedUser($userA);
            $connectionA->setLinkedPolicy($policyA);
            $connectionA->setValue($policyB->getAllowedConnectionValue());

            $connectionB = new Connection();
            $connectionB->setLinkedUser($userB);
            $connectionB->setLinkedPolicy($policyB);
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

        $userA->setBillingAddress($address);
        $manager->persist($userA);

        $userB->setBillingAddress($address);
        $manager->persist($userB);

        $phoneRepo = $manager->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['model' => 'iPhone 5', 'memory' => 64]);
        if (!$phone->getCurrentPhonePrice()) {
            throw new \Exception('Failed to load phone price');
        }
        //\Doctrine\Common\Util\Debug::dump($phone);
        //\Doctrine\Common\Util\Debug::dump($phone->getPhonePrices());
        //\Doctrine\Common\Util\Debug::dump($phone->getCurrentPhonePrice());
        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        $policyKeyFactsRepo = $dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        $policyA = new PhonePolicy();
        $policyA->setPhone($phone);
        $policyA->init($userA, $latestTerms, $latestKeyFacts);
        $policyA->create(-5000);

        $policyB = new PhonePolicy();
        $policyB->setPhone($phone);
        $policyB->init($userB, $latestTerms, $latestKeyFacts);
        $policyB->create(-4999);

        $connectionA = new Connection();
        $connectionA->setLinkedUser($userB);
        $connectionA->setLinkedPolicy($policyB);
        $connectionA->setValue(10);

        $connectionB = new Connection();
        $connectionB->setLinkedUser($userA);
        $connectionB->setLinkedPolicy($policyA);
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
