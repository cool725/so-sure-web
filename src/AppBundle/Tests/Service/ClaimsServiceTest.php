<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\Reward;
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
    protected static $policyRepo;
    protected static $lostPhoneRepo;
    protected static $claimsService;
    protected static $invitationService;

    public static function setUpBeforeClass()
    {
         //start the symfony kernel
         $kernel = static::createKernel();
         $kernel->boot();

         //get the DI container
         self::$container = $kernel->getContainer();

         //now we can instantiate our service (if you want a fresh one for
         //each test method, do this in setUp() instead
        /** @var DocumentManager dm */
         self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$policyRepo = self::$dm->getRepository(Policy::class);
         self::$lostPhoneRepo = self::$dm->getRepository(LostPhone::class);
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$policyService = self::$container->get('app.policy');
         self::$claimsService = self::$container->get('app.claims');
         self::$invitationService = self::$container->get('app.invitation');
    }

    public function tearDown()
    {
    }

    private function expect($mailer, $at, $needle)
    {
        $mailer->expects($this->at($at))
            ->method('send')
            ->with($this->callback(
                function ($mail) use ($needle) {
                    return mb_stripos($mail->getBody(), $needle) !== false;
                }
            ));
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

    public function testProcessClaimProcessed()
    {
        $claim = new Claim();
        $claim->setProcessed(true);
        $this->assertFalse(static::$claimsService->processClaim($claim));
    }

    public function testProcessClaimNonMonetary()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setType(Claim::TYPE_THEFT);
        $this->assertFalse(static::$claimsService->processClaim($claim));
    }

    public function testProcessClaimRewardConnection()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimRewardConnection', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $this->assertEquals(0, $policy->getPotValue());

        $reward = $this->createReward(static::generateEmail('testProcessClaimRewardConnection-R', $this));
        $connection = static::$invitationService->addReward($policy, $reward, 10);
        $this->assertEquals(10, $connection->getPromoValue());
        $this->assertEquals(10, $policy->getPotValue());

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNumber(rand(1, 999999));
        $policy->addClaim($claim);
        $this->assertTrue(static::$claimsService->processClaim($claim));
        $this->assertEquals(0, $connection->getPromoValue());
        $this->assertEquals(0, $policy->getPotValue());
        $this->assertTrue($claim->getProcessed());
    }

    public function testProcessClaimRewards()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimRewards', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);

        $reward1 = $this->createReward(static::generateEmail('testProcessClaimRewards1', $this));
        $connection = static::$invitationService->addReward($policy, $reward1, 10);
        $this->assertEquals(10, $connection->getPromoValue());

        $reward2 = $this->createReward(static::generateEmail('testProcessClaimRewards2', $this));
        $connection = static::$invitationService->addReward($policy, $reward2, 10);
        $this->assertEquals(10, $connection->getPromoValue());
    }

    public function testPicSureNotificationWithin1Day()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPicSureNotificationWithin1Day', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-10 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-1 day'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $this->expect($mailer, 0, 'recent claim');

        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->sendPicSureNotification($claim);
    }

    public function testPicSureNotificationOutside1Day()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPicSureNotificationOutside1Day', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-10 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-3 day'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->sendPicSureNotification($claim);
    }

    public function testPicSureNotificationWithin1DayPicsureApprovedOlder()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPicSureNotificationWithin1Day2', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-35 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-1 day'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->sendPicSureNotification($claim);
    }

    public function testPicSureNotificationOutside1DayPicsureApprovedOlder()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testPicSureNotificationOutside1Day2', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-35 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-2 day'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->sendPicSureNotification($claim);
    }



    public function testProcessClaimApprovedWithin1dayPicSureApprovedWithin30days()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimWithin1Day', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-10 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-1 days'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->setMethods(['send'])
            ->getMock();
        $mailer->expects($this->exactly(1))->method('send');
        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->processClaim($claim);

    }

    public function testProcessClaimApprovedOutside1dayPicSureApprovedWithin30days()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimOutside1dayPicSureApprovedWithin30days', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-10 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-2 days'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->setMethods(['send'])
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->processClaim($claim);

    }

    public function testProcessClaimApprovedWithin1dayPicSureApprovedOutside30days()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimWithin1dayPicSureApprovedWithin30days', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-35 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-1 days'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->setMethods(['send'])
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->processClaim($claim);

    }

    public function testProcessApprovedClaimOutside1dayPicSureApprovedOutside30days()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimOutside1dayPicSureApprovedOutside30days', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $policy->setPicSureApprovedDate(new \DateTime('-35 days'));
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-2 days'));
        $claim->setType(Claim::TYPE_THEFT);
        $mailer = $this->getMockBuilder('Swift_Mailer')
            ->disableOriginalConstructor()
            ->setMethods(['send'])
            ->getMock();
        $mailer->expects($this->never())->method('send');
        self::$claimsService->setMailerMailer($mailer);
        self::$claimsService->processClaim($claim);

    }
}
