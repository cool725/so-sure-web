<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\LostPhone;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * @group functional-nonet
 */
class ClaimsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $policyService;
    protected static $dm;
    protected static $policyRepo;
    protected static $lostPhoneRepo;
    protected static $userManager;
    protected static $claimsService;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$policyRepo = self::$dm->getRepository(Policy::class);
         self::$lostPhoneRepo = self::$dm->getRepository(LostPhone::class);
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$policyService = self::$container->get('app.policy');
         self::$claimsService = self::$container->get('app.claims');
    }

    public function tearDown()
    {
    }

    public function testRecordLostPhone()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('record', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_THEFT);
        $lostPhone = static::$claimsService->recordLostPhone($policy, $claim);
        $this->assertNotNull($lostPhone);

        $lostPhone = static::$lostPhoneRepo->findOneBy(['imei' => (string) $policy->getImei()]);
        $this->assertNotNull($lostPhone);
        $this->assertEquals($policy->getId(), $lostPhone->getPolicy()->getId());
        $this->assertEquals($policy->getPhone()->getId(), $lostPhone->getPhone()->getId());
    }

    public function testDuplicateClaim()
    {
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('dup-a', $this),
            'bar'
        );
        $phoneA = static::getRandomPhone(static::$dm);
        $policyA = static::initPolicy($userA, static::$dm, $phoneA, null, true, true);
        
        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('dup-b', $this),
            'bar'
        );
        $phoneB = static::getRandomPhone(static::$dm);
        $policyB = static::initPolicy($userB, static::$dm, $phoneB, null, true, true);

        $claimNumber = rand(1, 999999);

        $claimA = new Claim();
        $claimA->setStatus(Claim::STATUS_APPROVED);
        $claimA->setType(Claim::TYPE_THEFT);
        $claimA->setNumber($claimNumber);
        $this->assertTrue(static::$claimsService->addClaim($policyA, $claimA));

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_INREVIEW);
        $claimB->setType(Claim::TYPE_THEFT);
        $claimB->setNumber($claimNumber);
        // same policy, same number, different status allowed
        $this->assertTrue(static::$claimsService->addClaim($policyA, $claimB));
        // not allowed for diff policy
        $this->assertFalse(static::$claimsService->addClaim($policyB, $claimB));
    }
}
