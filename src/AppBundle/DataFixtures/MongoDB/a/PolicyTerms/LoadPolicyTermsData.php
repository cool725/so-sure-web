<?php

namespace AppBundle\DataFixtures\MongoDB\a\PolicyTerms;

use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\Persistence\ObjectManager;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Phone;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Classes\Salva;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;

// @codingStandardsIgnoreFile
class LoadPolicyTermsData implements FixtureInterface, ContainerAwareInterface
{
     /**
     * @var ContainerInterface|null
     */
    private $container;

    private $faker;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function load(ObjectManager $manager)
    {
        $this->newPolicyTerms($manager);
        $manager->flush();
    }

    private function newPolicyTerms($manager)
    {
        $versions = count(PolicyTerms::$allVersions);
        $count = 1;
        foreach (PolicyTerms::$allVersions as $versionName => $version) {
            $policyTerms = new PolicyTerms();
            $policyTerms->setLatest($versions == $count);
            $policyTerms->setVersion($versionName);
            $manager->persist($policyTerms);
            $count++;
        }
    }
}
