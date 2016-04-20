<?php

namespace AppBundle\DataFixtures\ORM;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
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

        $phoneRepo = $manager->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['model' => 'iPhone 5', 'memory' => 64]);
        if (!$phone->getCurrentPolicyPremium()) {
            throw new \Exception('Failed to load phone policy');
        }
        \Doctrine\Common\Util\Debug::dump($phone);
        \Doctrine\Common\Util\Debug::dump($phone->getPolicyPremiums());
        \Doctrine\Common\Util\Debug::dump($phone->getCurrentPolicyPremium());
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setPhone($phone);
        $policy->create(-5000);

        $dm = $this->container->get('doctrine_mongodb.odm.default_document_manager');
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);
        $policy->setPolicyTerms($latestTerms);

        $connection = new Connection();
        $connection->setUser($users[0]);
        $connection->setValue(10);
        $policy->addConnection($connection);
        $policy->updatePotValue();
        $manager->persist($connection);
        //\Doctrine\Common\Util\Debug::dump($policy);
        $manager->persist($policy);
    }
}
