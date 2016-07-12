<?php

namespace AppBundle\Tests\Service;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;

/**
 * @group functional-nonet
 */
class InvitationServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    protected static $gocardless;
    protected static $dm;
    protected static $userRepo;
    protected static $userManager;
    protected static $invitationService;
    protected static $phone;
    protected static $phone2;
    protected static $policyService;

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
        self::$userRepo = self::$dm->getRepository(User::class);
        self::$userManager = self::$container->get('fos_user.user_manager');
        $transport = new \Swift_Transport_NullTransport(new \Swift_Events_SimpleEventDispatcher);
        $mailer = \Swift_Mailer::newInstance($transport);
        self::$invitationService = new InvitationService(
            self::$dm,
            self::$container->get('logger'),
            $mailer,
            self::$container->get('templating'),
            self::$container->get('api.router'),
            self::$container->get('app.shortlink'),
            self::$container->get('app.sms'),
            self::$container->get('app.ratelimit')
        );
        self::$policyService = self::$container->get('app.policy');

        self::$invitationService->setDebug(true);
        $phoneRepo = self::$dm->getRepository(Phone::class);
        self::$phone = $phoneRepo->findOneBy(['devices' => 'iPhone 5', 'memory' => 64]);
        self::$phone2 = $phoneRepo->findOneBy(['devices' => 'iPhone8,1', 'memory' => 64]);
    }

    public function tearDown()
    {
    }

    /**
     * @expectedException AppBundle\Exception\DuplicateInvitationException
     */
    public function testDuplicateEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user1', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite1', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->inviteByEmail($policy, static::generateEmail('invite1', $this));
    }

    /**
     * @expectedException AppBundle\Exception\ConnectedInvitationException
     */
    public function testConnectedEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('connected-user', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-connected', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-connected', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-05-01'));
        $invitation = self::$invitationService->inviteByEmail(
            $policyInvitee,
            static::generateEmail('connected-user', $this)
        );
    }

    /**
     * @expectedException AppBundle\Exception\OptOutException
     */
    public function testOptOutCatIntivationsEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user2', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('invite2', $this));
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite2', $this));
    }

    /**
     * @expectedException AppBundle\Exception\OptOutException
     */
    public function testOptOutCatAllEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user3', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $optOut = new EmailOptOut();
        $optOut->setEmail(static::generateEmail('invite3', $this));
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_ALL);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite3', $this));
    }

    public function testNoOptOutEmailInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user4', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite4', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
    }

    /**
     * @expectedException AppBundle\Exception\DuplicateInvitationException
     */
    public function testDuplicateSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser1', $this),
            'bar'
        );
        $mobile = static::generateRandomMobile();
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $invitation = self::$invitationService->inviteBySms($policy, $mobile);
        $this->assertTrue($invitation instanceof SmsInvitation);

        self::$invitationService->inviteBySms($policy, self::transformMobile($mobile));
    }
    
    /**
     * @expectedException AppBundle\Exception\ConnectedInvitationException
     */
    public function testConnectedMobileInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('connected-mobile-user', $this),
            'bar'
        );
        $user->setMobileNumber(static::generateRandomMobile());
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-mobile-connected', $this),
            'bar'
        );
        $userInvitee->setMobileNumber(static::generateRandomMobile());
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySms($policy, $userInvitee->getMobileNumber());
        $this->assertTrue($invitation instanceof SmsInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-05-01'));
        self::$invitationService->inviteBySms($policyInvitee, $user->getMobileNumber());
    }

    public function testOptOutCatIntivationsSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser2', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $optOut = new SmsOptOut();
        $optOut->setMobile('11234');
        $optOut->setCategory(SmsOptOut::OPTOUT_CAT_INVITATIONS);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteBySms($policy, '11234');
        $this->assertNull($invitation);
    }

    public function testOptOutCatAllSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser3', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $optOut = new SmsOptOut();
        $optOut->setMobile('112345');
        $optOut->setCategory(EmailOptOut::OPTOUT_CAT_ALL);
        static::$dm->persist($optOut);
        static::$dm->flush();

        $invitation = self::$invitationService->inviteBySms($policy, '112345');
        $this->assertNull($invitation);
    }

    public function testNoOptOutSmsInvitation()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('smsuser4', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySms($policy, '1123456');
        $this->assertTrue($invitation instanceof SmsInvitation);
    }

    public function testEmailInvitationReinvite()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user5', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite5', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite5', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
        
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = new \DateTime();
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));

        // allow reinvitation
        $invitation->setNextReinvited('2016-01-01');
        self::$invitationService->reinvite($invitation);

        $this->assertEquals(1, $invitation->getReinvitedCount());
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));
    }

    private function removeAllEmailInvitations()
    {
        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        foreach ($emailInvitationRepo->findAll() as $invite) {
            static::$dm->remove($invite);
        }
        static::$dm->flush();
    }

    /**
     * @expectedException AppBundle\Exception\ProcessedException
     */
    public function testEmailReinviteProcessed()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-processed', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-processed', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-processed', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = new \DateTime();
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));
        
        $invitation->setAccepted(new \DateTime());
        static::$dm->flush();
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));

        self::$invitationService->reinvite($invitation);
    }

    /**
     * @expectedException AppBundle\Exception\RateLimitException
     */
    public function testEmailReinviteRateLimited()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-ratelimit', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-ratelimit', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-ratelimit', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->reinvite($invitation);
    }

    public function testEmailInvitationCancel()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user6', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite6', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = new \DateTime();
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));

        self::$invitationService->cancel($invitation, Policy::CANCELLED_FRAUD);

        $this->assertTrue($invitation->isCancelled());
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));
    }

    public function testEmailInvitationReject()
    {
        $this->removeAllEmailInvitations();
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user7', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite7', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);

        $emailInvitationRepo = static::$dm->getRepository(EmailInvitation::class);
        $count = count($emailInvitationRepo->findSystemReinvitations());
        $future = new \DateTime();
        $future->add(new \DateInterval('P3D'));
        $this->assertEquals($count + 1, count($emailInvitationRepo->findSystemReinvitations($future)));

        self::$invitationService->reject($invitation);

        $this->assertTrue($invitation->isRejected());
        $this->assertEquals($count, count($emailInvitationRepo->findSystemReinvitations($future)));
    }

    public function testEmailInvitationAccept()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user8', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite8', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite8', $this));
        $this->assertTrue($invitation instanceof EmailInvitation);
        self::$invitationService->accept($invitation, $policyInvitee);

        $this->assertTrue($invitation->isAccepted());
        $this->assertEquals(10, $policy->getPotValue());
        $this->assertEquals(10, $policyInvitee->getPotValue());
    }

    /**
     * @expectedException AppBundle\Exception\SelfInviteException
     */
    public function testEmailInvitationSelf()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user9', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('user9', $this));
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testEmailInvitationPotFilled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-maxpot', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());
        $policy->setPotValue($policy->getMaxPot());

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite-maxpot', $this));
    }

    /**
     * @expectedException AppBundle\Exception\ClaimException
     */
    public function testEmailInvitationClaims()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-claims', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        $invitation = self::$invitationService->inviteByEmail($policy, static::generateEmail('invite-claims', $this));
    }

    /**
     * @expectedException AppBundle\Exception\SelfInviteException
     */
    public function testMobileInvitationSelf()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user10', $this),
            'bar'
        );
        $user->setMobileNumber('+447700900001');
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteBySms($policy, '07700900001');
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testAcceptPotFilled()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept-fullpot', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-maxpot', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-maxpot', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        $policy->setPotValue($policy->getMaxPot());

        self::$invitationService->accept($invitation, $policyInvitee);
    }

    /**
     * @expectedException AppBundle\Exception\ClaimException
     */
    public function testAcceptClaims()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept-claims', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-claims', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy($userInvitee, static::$dm, static::$phone, null, false, true);

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-claims', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        self::$invitationService->accept($invitation, $policyInvitee);
    }

    public function testAccept()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, new \DateTime('2016-01-01'), false, true);
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy(
            $userInvitee,
            static::$dm,
            static::$phone,
            new \DateTime('2016-04-01'),
            false,
            true
        );

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-05-01'));

        $repo = static::$dm->getRepository(Policy::class);
        $inviterPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($inviterPolicy->getConnections() as $connection) {
            if ($connection->getLinkedPolicy()->getId() == $policyInvitee->getId()) {
                $connectionFound = true;
                $this->assertEquals(2, $connection->getTotalValue());
            }
        }
        $this->assertTrue($connectionFound);

        $inviteePolicy = $repo->find($policyInvitee->getId());
        $connectionFound = false;
        foreach ($inviteePolicy->getConnections() as $connection) {
            if ($connection->getLinkedPolicy()->getId() == $inviterPolicy->getId()) {
                $connectionFound = true;
                $this->assertEquals(10, $connection->getTotalValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    public function testAcceptWithCancelled30days()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-accept-cancel', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, new \DateTime('2016-01-01'), false, true);
        $this->assertTrue($policy->isPolicy());

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-cancel-before', $this),
            'bar'
        );
        $policyInvitee = static::initPolicy(
            $userInvitee,
            static::$dm,
            static::$phone,
            new \DateTime('2016-02-01'),
            false,
            true
        );

        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-cancel-before', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        self::$invitationService->accept($invitation, $policyInvitee, new \DateTime('2016-02-01'));

        // Now Cancel policy
        self::$policyService->cancel($policyInvitee, Policy::CANCELLED_FRAUD, new \DateTime('2016-04-03'));

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-accept-cancel-after', $this),
            'bar'
        );
        $policyInviteeAfter = static::initPolicy(
            $userInvitee,
            static::$dm,
            static::$phone,
            new \DateTime('2016-04-10'),
            false,
            true
        );

        $invitationAfter = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-accept-cancel-after', $this)
        );
        $this->assertTrue($invitationAfter instanceof EmailInvitation);

        self::$invitationService->accept($invitationAfter, $policyInviteeAfter, new \DateTime('2016-04-10'));

        $repo = static::$dm->getRepository(Policy::class);
        $inviterPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($inviterPolicy->getConnections() as $connection) {
            if ($connection->getLinkedPolicy()->getId() == $policyInviteeAfter->getId()) {
                $connectionFound = true;
                $this->assertEquals(10, $connection->getTotalValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    /**
     * @expectedException AppBundle\Exception\FullPotException
     */
    public function testEmailInvitationReinviteMaxPot()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-reinvite-maxpot', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-reinvite-maxpot', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-reinvite-maxpot', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        // allow reinvitation
        $invitation->setNextReinvited('2016-01-01');
        // but set pot value to maxpot
        $policy->setPotValue($policy->getMaxPot());

        self::$invitationService->reinvite($invitation);
    }

    /**
     * @expectedException AppBundle\Exception\ClaimException
     */
    public function testEmailInvitationReinviteClaim()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('user-reinvite-claim', $this),
            'bar'
        );
        $policy = static::initPolicy($user, static::$dm, static::$phone, null, false, true);

        $userInvitee = static::createUser(
            static::$userManager,
            static::generateEmail('invite-reinvite-claim', $this),
            'bar'
        );
        $invitation = self::$invitationService->inviteByEmail(
            $policy,
            static::generateEmail('invite-reinvite-claim', $this)
        );
        $this->assertTrue($invitation instanceof EmailInvitation);

        // allow reinvitation
        $invitation->setNextReinvited('2016-01-01');
        $claim = new Claim();
        $claim->setType(Claim::TYPE_LOSS);
        $claim->setStatus(Claim::STATUS_SETTLED);
        $policy->addClaim($claim);

        self::$invitationService->reinvite($invitation);
    }

    public function testAcceptConnectionReductionStandard()
    {
        $policy = $this->createAndLink(static::generateEmail('user-reduction', $this), new \DateTime('2016-01-01'));

        for ($i = 1; $i <= 6; $i++) {
            $this->createAndLink(
                static::generateEmail(sprintf('invite-accept-%d', $i), $this),
                new \DateTime('2016-02-01'),
                static::generateEmail('user-reduction', $this),
                $policy
            );
        }

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            $connectionFound = true;
            $this->assertEquals(10, $connection->getTotalValue());
        }
        $this->assertTrue($connectionFound);

        $this->createAndLink(
            static::generateEmail('invite-accept-0', $this),
            new \DateTime('2016-02-01'),
            static::generateEmail('user-reduction', $this),
            $policy
        );

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            if ($connection->getLinkedUser()->getEmail() == static::generateEmail('invite-accept-0', $this)) {
                $connectionFound = true;
                $this->assertEquals(7.10, $connection->getTotalValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    public function testAcceptConnectionReductionPromoConnectionOnly()
    {
        $policy = $this->createAndLink(static::generateEmail('user-promo', $this), new \DateTime('2016-01-01'));
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);
        static::$dm->flush();

        $this->assertEquals(83.88, $policy->getMaxPot());
        // 83.88 / 15 = 5.6
        for ($i = 1; $i <= 5; $i++) {
            $this->createAndLink(
                static::generateEmail(sprintf('invite-promo-%d', $i), $this),
                new \DateTime('2016-02-01'),
                static::generateEmail('user-promo', $this),
                $policy
            );
        }

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            $connectionFound = true;
            $this->assertEquals(15, $connection->getTotalValue());
        }
        $this->assertTrue($connectionFound);

        $this->createAndLink(
            static::generateEmail('invite-promo-0', $this),
            new \DateTime('2016-02-01'),
            static::generateEmail('user-promo', $this),
            $policy
        );

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        // 83.88 - 15 * 5 = 8.88
        foreach ($checkPolicy->getConnections() as $connection) {
            if ($connection->getLinkedUser()->getEmail() == static::generateEmail('invite-promo-0', $this)) {
                $connectionFound = true;
                $this->assertEquals(8.88, $connection->getTotalValue());
                $this->assertEquals(8.88, $connection->getValue());
                $this->assertEquals(0, $connection->getPromoValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    public function testAcceptConnectionReductionPromoConnectionRollover()
    {
        $policy = $this->createAndLink(
            static::generateEmail('user-promor', $this),
            new \DateTime('2016-01-01'),
            null,
            null,
            static::$phone2
        );
        $policy->setPromoCode(PhonePolicy::PROMO_LAUNCH);
        static::$dm->flush();

        $this->assertEquals(103.68, $policy->getMaxPot());
        // 103.68 / 15 = 6.9
        for ($i = 1; $i <= 6; $i++) {
            $this->createAndLink(
                static::generateEmail(sprintf('invite-promor-%d', $i), $this),
                new \DateTime('2016-02-01'),
                static::generateEmail('user-promor', $this),
                $policy
            );
        }

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        foreach ($checkPolicy->getConnections() as $connection) {
            $connectionFound = true;
            $this->assertEquals(15, $connection->getTotalValue());
        }
        $this->assertTrue($connectionFound);

        $this->createAndLink(
            static::generateEmail('invite-promor-0', $this),
            new \DateTime('2016-02-01'),
            static::generateEmail('user-promor', $this),
            $policy
        );

        $repo = static::$dm->getRepository(Policy::class);
        $checkPolicy = $repo->find($policy->getId());
        $connectionFound = false;
        // 103.68 - 15 * 6 = 13.68
        foreach ($checkPolicy->getConnections() as $connection) {
            if ($connection->getLinkedUser()->getEmail() == static::generateEmail('invite-promor-0', $this)) {
                $connectionFound = true;
                $this->assertEquals(13.68, $connection->getTotalValue());
                $this->assertEquals(10, $connection->getValue());
                $this->assertEquals(3.68, $connection->getPromoValue());
            }
        }
        $this->assertTrue($connectionFound);
    }

    private function createAndLink($email, $date, $inviteeEmail = null, $policy = null, $phone = null)
    {
        if ($phone == null) {
            $phone = static::$phone;
        }
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );
        $newPolicy = static::initPolicy($user, static::$dm, $phone, $date, false, true);

        if ($inviteeEmail) {
            $invitation = self::$invitationService->inviteByEmail(
                $newPolicy,
                $inviteeEmail
            );
            $this->assertTrue($invitation instanceof EmailInvitation);
            self::$invitationService->accept($invitation, $policy, $date);
        }

        return $newPolicy;
    }
}
