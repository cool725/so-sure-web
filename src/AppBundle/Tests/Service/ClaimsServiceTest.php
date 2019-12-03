<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Repository\PolicyRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Exception\ValidationException;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
use AppBundle\Document\Policy;
use AppBundle\Document\Reward;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\LostPhone;
use AppBundle\Document\Form\ClaimFnolTheftLoss;
use AppBundle\Document\Form\ClaimFnolDamage;
use Doctrine\ODM\MongoDB\DocumentManager;

/**
 * @group functional-nonet
 * @group fixed
 */
class ClaimsServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var PolicyRepository */
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
         /** @var DocumentManager */
         $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
         self::$dm = $dm;
         /** @var PolicyRepository policyRepo */
         $policyRepo = self::$dm->getRepository(Policy::class);
         self::$policyRepo = $policyRepo;
         self::$lostPhoneRepo = self::$dm->getRepository(LostPhone::class);
         self::$userManager = self::$container->get('fos_user.user_manager');
         self::$policyService = self::$container->get('app.policy');
         self::$claimsService = self::$container->get('app.claims');
         self::$invitationService = self::$container->get('app.invitation');
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

    public function testDuplicateAddClaim()
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

        $claimA = new Claim();
        $claimA->setStatus(Claim::STATUS_APPROVED);
        $claimA->setType(Claim::TYPE_THEFT);
        $claimA->setNumber('100');
        $this->assertTrue(static::$claimsService->addClaim($policyA, $claimA));

        $claimB = new Claim();
        $claimB->setStatus(Claim::STATUS_INREVIEW);
        $claimB->setType(Claim::TYPE_THEFT);
        $claimB->setNumber('100');
        // same policy, same number, diff not allowed
        $this->assertFalse(static::$claimsService->addClaim($policyA, $claimB));
        // not allowed for diff policy
        $this->assertFalse(static::$claimsService->addClaim($policyB, $claimB));
    }

    public function testDuplicateUpdateClaim()
    {
        $userA = static::createUser(
            static::$userManager,
            static::generateEmail('testDuplicateUpdateClaim-a', $this),
            'bar'
        );
        $phoneA = static::getRandomPhone(static::$dm);
        $policyA = static::initPolicy($userA, static::$dm, $phoneA, null, true, true);

        $userB = static::createUser(
            static::$userManager,
            static::generateEmail('testDuplicateUpdateClaim-b', $this),
            'bar'
        );
        $phoneB = static::getRandomPhone(static::$dm);
        $policyB = static::initPolicy($userB, static::$dm, $phoneB, null, true, true);

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNumber('101');
        $this->assertTrue(static::$claimsService->addClaim($policyA, $claim));

        $claim->setStatus(Claim::STATUS_INREVIEW);
        $this->assertTrue(static::$claimsService->updateClaim($policyA, $claim));

        $claim->setStatus(Claim::STATUS_APPROVED);
        $this->assertFalse(static::$claimsService->updateClaim($policyB, $claim));
    }

    public function testProcessClaimProcessed()
    {
        $claim = new Claim();
        $policy = new SalvaPhonePolicy();
        $claim->setPolicy($policy);
        $claim->setProcessed(true);
        $this->assertFalse(static::$claimsService->processClaim($claim));
        $this->assertNull($policy->getPicSureClaimApprovedClaim());
    }

    public function testProcessClaimNonMonetary()
    {
        $claim = new Claim();
        $policy = new SalvaPhonePolicy();
        $claim->setPolicy($policy);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        $claim->setType(Claim::TYPE_THEFT);
        $this->assertFalse(static::$claimsService->processClaim($claim));
        $this->assertNull($policy->getPicSureClaimApprovedClaim());
    }

    public function testProcessClaimPicSureClaimApproved()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimPicSureClaimApproved', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        static::$dm->persist($claim);
        $this->assertNull($claim->getProcessed());
        $this->assertFalse(static::$claimsService->processClaim($claim));
        $this->assertNull($policy->getPicSureClaimApprovedClaim());

        $claim->setStatus(Claim::STATUS_SETTLED);
        $this->assertTrue(static::$claimsService->processClaim($claim));
        $this->assertNotNull($policy->getPicSureClaimApprovedClaim());
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED, $policy->getPicSureStatus());
    }

    public function testProcessClaimPicSureClaimApprovedApproved()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimPicSureClaimApprovedApproved', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        static::$dm->persist($claim);
        $this->assertNull($claim->getProcessed());
        $this->assertFalse(static::$claimsService->processClaim($claim));
        $this->assertNull($policy->getPicSureClaimApprovedClaim());

        $claim->setStatus(Claim::STATUS_SETTLED);
        $this->assertTrue(static::$claimsService->processClaim($claim));
        $this->assertNull($policy->getPicSureClaimApprovedClaim());
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_APPROVED, $policy->getPicSureStatus());
    }

    public function testProcessClaimPicSureClaimNotOverwritten()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimPicSureClaimNotOverwritten', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);

        $claim = new Claim();
        $claim->setPolicy($policy);
        $claim->setStatus(Claim::STATUS_INREVIEW);
        static::$dm->persist($claim);
        $this->assertNull($claim->getProcessed());
        $this->assertFalse(static::$claimsService->processClaim($claim));
        $this->assertNull($policy->getPicSureClaimApprovedClaim());

        $claim->setStatus(Claim::STATUS_SETTLED);
        $this->assertTrue(static::$claimsService->processClaim($claim));
        $this->assertNotNull($policy->getPicSureClaimApprovedClaim());
        $this->assertEquals($claim, $policy->getPicSureClaimApprovedClaim());
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED, $policy->getPicSureStatus());

        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_MANUAL);
        $claimB = new Claim();
        $claimB->setPolicy($policy);
        $claimB->setStatus(Claim::STATUS_SETTLED);
        static::$dm->persist($claimB);
        $this->assertTrue(static::$claimsService->processClaim($claimB));
        $this->assertNotNull($policy->getPicSureClaimApprovedClaim());
        $this->assertEquals($claim, $policy->getPicSureClaimApprovedClaim());
        $this->assertNotEquals($claimB, $policy->getPicSureClaimApprovedClaim());
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED, $policy->getPicSureStatus());
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
        $claim->setNumber('102');
        $policy->addClaim($claim);
        $this->assertTrue(static::$claimsService->processClaim($claim));
        $this->assertEquals(0, $connection->getPromoValue());
        $this->assertEquals(0, $policy->getPotValue());
        $this->assertTrue($claim->getProcessed());
    }

    public function testProcessClaimInvalidPicSure()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimInvalidPicSure', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID);
        $this->assertEquals(0, $policy->getPotValue());

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNumber('103');
        $policy->addClaim($claim);
        static::$dm->flush();

        $this->assertTrue(static::$claimsService->processClaim($claim));
        $this->assertEquals(0, $policy->getPotValue());
        $this->assertTrue($claim->getProcessed());

        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED, $updatedPolicy->getPicSureStatus());
    }

    public function testProcessClaimApprovedPicSure()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testProcessClaimApprovedPicSure', $this),
            'bar'
        );
        $phone = static::getRandomPhone(static::$dm);
        $policy = static::initPolicy($user, static::$dm, $phone, null, true, true);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
        $this->assertEquals(0, $policy->getPotValue());

        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_SETTLED);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setNumber('104');
        $policy->addClaim($claim);
        static::$dm->flush();

        $this->assertTrue(static::$claimsService->processClaim($claim));
        $this->assertEquals(0, $policy->getPotValue());
        $this->assertTrue($claim->getProcessed());

        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $this->assertPolicyExists(self::$container, $policy);
        $this->assertEquals(PhonePolicy::PICSURE_STATUS_APPROVED, $updatedPolicy->getPicSureStatus());
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

    public function testSendUniqueLoginLink()
    {
        $email = self::generateEmail('testSendUniqueLoginLink', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $redis = self::$container->get('snc_redis.default');
        $token = md5(sprintf('%s%s', time(), $email));
        $redis->setex($token, 900, $user->getId());

        self::$claimsService->sendUniqueLoginLink($user);
        $userId = self::$claimsService->getUserIdFromLoginLinkToken($token);
        $this->assertEquals($userId, $user->getId());
    }

    public function testSendUniqueLoginLinkUpdate()
    {
        $email = self::generateEmail('testSendUniqueLoginLinkUpdate', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $redis = self::$container->get('snc_redis.default');
        $token = md5(sprintf('%s%s', time(), $email));
        $redis->setex($token, 900, $user->getId());

        self::$claimsService->sendUniqueLoginLink($user, true);
        $userId = self::$claimsService->getUserIdFromLoginLinkToken($token);
        $this->assertEquals($userId, $user->getId());
    }

    /**
     * Make sure the claim service can update theft/loss claim right and deal with missing and faulty data from user.
     */
    public function testUpdateTheftLossDocuments()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_THEFT);
        $update = new ClaimFnolTheftLoss();
        $update->setHasContacted(true);
        $update->setBlockedDate(new \DateTime());
        $update->setReportedDate(new \DateTime());
        $update->setReportType("online");

        $update->setContactedPlace("hi");
        self::$claimsService->updateTheftLossDocuments($claim, $update);
        $this->assertEquals(null, $claim->getContactedPlace());

        $update->setContactedPlace("hi||||||");
        self::$claimsService->updateTheftLossDocuments($claim, $update);
        $this->assertEquals(null, $claim->getContactedPlace());

        $update->setContactedPlace("hi||||||| how are you?");
        self::$claimsService->updateTheftLossDocuments($claim, $update);
        $this->assertEquals("hi how are you?", $claim->getContactedPlace());
        self::$dm->persist($claim);
        self::$dm->flush();
    }

    /**
     * Make sure the claim service can update damage claim right and deal with missing and faulty data from user.
     */
    public function testUpdateDamageDocuments()
    {
        $claim = new Claim();
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setType(Claim::TYPE_DAMAGE);
        $update = new ClaimFnolDamage();
        $update->setTypeDetails("water-damage");
        $update->setMonthOfPurchase(".");
        $update->setYearOfPurchase("2014");

        $update->setTypeDetailsOther("|");
        self::$claimsService->updateDamageDocuments($claim, $update);
        $this->assertNull($claim->getTypeDetailsOther());
        $this->assertNull($claim->getMonthOfPurchase());

        self::$dm->persist($claim);
        self::$dm->flush();

        $update->setTypeDetailsOther("hi||||||");
        self::$claimsService->updateDamageDocuments($claim, $update);
        $this->assertEquals("hi", $claim->getTypeDetailsOther());

        $update->setTypeDetailsOther("hi||||||| how are you?");
        $update->setMonthOfPurchase("JUNE");
        self::$claimsService->updateDamageDocuments($claim, $update);
        $this->assertEquals("hi how are you?", $claim->getTypeDetailsOther());
        self::$dm->persist($claim);
        self::$dm->flush();

    }
}
