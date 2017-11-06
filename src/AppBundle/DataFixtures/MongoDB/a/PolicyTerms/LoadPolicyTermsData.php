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
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Classes\Salva;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Faker;

// @codingStandardsIgnoreFile
class LoadPolicyTermsData implements FixtureInterface, ContainerAwareInterface
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
        $this->newPolicyTerms($manager);
        $manager->flush();
    }

    private function newPolicyTerms($manager)
    {
        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(false);
        $policyTerms->setVersion('Version 1 May 2016');
        $manager->persist($policyTerms);

        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(false);
        $policyTerms->setVersion('Version 1 June 2016');
        $manager->persist($policyTerms);

        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(false);
        $policyTerms->setVersion('Version 2 Aug 2017');
        $manager->persist($policyTerms);

        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(false);
        $policyTerms->setVersion('Version 3 Aug 2017');
        $manager->persist($policyTerms);

        $policyTerms = new PolicyTerms();
        $policyTerms->setLatest(true);
        $policyTerms->setVersion('Version 4 Nov 2017');
        $manager->persist($policyTerms);
    }
}
