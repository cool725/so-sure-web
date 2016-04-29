<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Connection;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

// @codingStandardsIgnoreFile
class LoadSamplePolicyData implements FixtureInterface, ContainerAwareInterface
{
     /**
     * @var ContainerInterface
     */
    private $container;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->newPolicyTerms($manager);
        $manager->flush();

        $users = $this->newUsers($manager);
        $manager->flush();

        $this->newPolicy($manager, $users);
        $manager->flush();
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
        for ($i = 1; $i <= 8; $i++) {
            $user = new User();
            $user->setEmail(sprintf('user%d@policy.so-sure.net', $i));
            $user->setFirstName(sprintf('first-%d', $i));
            $user->setLastName(sprintf('last-%d', $i));
            $address = new Address();
            $address->setType(Address::TYPE_BILLING);
            $address->setLine1($i);
            $address->setCity('London');
            $address->setPostcode('BX11LT');
            $user->addAddress($address);
            $manager->persist($user);
            $users[] = $user;
        }

        return $users;
    }

    private function newPolicy($manager, $users)
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
        $policyA->setPolicyTerms($latestTerms);
        $policyB->setPolicyTerms($latestTerms);

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
