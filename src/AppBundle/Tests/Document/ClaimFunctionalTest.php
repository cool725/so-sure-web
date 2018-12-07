<?php

namespace AppBundle\Tests\Document;

use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Tests\UserClassTrait;
use DateTime;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Document\\PhonePolicyTest
 */
class ClaimFunctionalTest extends WebTestCase
{
    use UserClassTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 6s', 'memory' => 64]);
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$policyService = self::$container->get('app.policy');
    }

    public function testSetPolicyExcess()
    {
        $policy = static::createUserPolicy(true);
        $claim = new Claim();
        $claim->setType(Claim::TYPE_WARRANTY);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setPolicy($policy);

        $this->assertEquals(150, $policy->getCurrentExcess()->getTheft());
        $this->assertEquals(150, $claim->getExpectedExcess()->getTheft());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $claim->setExpectedExcess($policy->getCurrentExcess());
        $this->assertEquals(70, $policy->getCurrentExcess()->getTheft());
        $this->assertEquals(70, $claim->getExpectedExcess()->getTheft());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
        $claim->setExpectedExcess($policy->getCurrentExcess());
        $this->assertEquals(150, $policy->getCurrentExcess()->getTheft());
        $this->assertEquals(150, $claim->getExpectedExcess()->getTheft());
    }
}
