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

    /**
     * Adds the policy terms to the database based on the static policy terms data.
     * @param ObjectManager $manager is the object manager with which to persist the terms to the database.
     */
    private function newPolicyTerms($manager)
    {
        // Find the largest version number.
        $max = 0;
        foreach (PolicyTerms::$allVersions as $versionName => $version) {
            $n = (int)$version;
            if ($n > $max) {
                $max = $n;
            }
        }
        // Now add the terms.
        foreach (PolicyTerms::$allVersions as $versionName => $version) {
            $policyTerms = new PolicyTerms();
            $policyTerms->setLatest((int)$version == $max);
            $policyTerms->setVersion($versionName);
            if (strpos($version, '_R') !== false) {
                $policyTerms->setAggregator(true);
            }
            $manager->persist($policyTerms);
        }
    }
}
