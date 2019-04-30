<?php
namespace AppBundle\Tests\Repository\Invitation;

use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use AppBundle\Document\DateTrait;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Tests\UserClassTrait;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests that the invitation repository methods work as they are intended.
 * @group functional-nonet
 */
class InvitationRepositoryTest extends KernelTestCase
{
    use UserClassTrait;
    use DateTrait;

    /** @var InvitationRepository */
    private $invitationRepo;

    /**
     * {@inheritDoc}
     */
    protected function setUp()
    {
        $kernel = self::bootKernel();
        /** @var DocumentManager */
        $dm = $kernel->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var InvitationRepository */
        $invitationRepo = self::$dm->getRepository(Invitation::class);
        $this->invitationRepo = $invitationRepo;
    }

    /**
     * Tests to make sure that we can find what invitation led to the creation of a policy if there is one.
     */
    public function testGetOwnInvitation()
    {
        $date = new \DateTime();
        $policies = [];
        for ($i = 0; $i < 3; $i++) {
            $policy = self::createUserPolicy(
                true,
                $this->addDays($date, rand(-90, 90)),
                false,
                uniqid()."@gmail.com",
                $this->generateRandomImei()
            );
            self::$dm->persist($policy);
            self::$dm->persist($policy->getUser());
            $policies[] = $policy;
        }
        $invitation = new SmsInvitation();
        $invitation->setInviter($policies[0]->getUser());
        $invitation->setInvitee($policies[1]->getUser());
        $invitation->setPolicy($policies[0]);
        $invitation->setCreated($this->addDays($date, rand(-90, 90)));
        self::$dm->persist($invitation);
        for ($i = 0; $i < 10; $i++) {
            $invitation = new SmsInvitation();
            $invitation->setPolicy($policies[rand(0, 2)]);
            self::$dm->persist($invitation);
        }
        self::$dm->flush();
        // check that it worked as planned.
        /** @var Invitation */
        $bInvitation = $this->invitationRepo->getOwnInvitation($policies[1]);
        /** @var User */
        $bInvitee = $bInvitation->getInvitee();
        $this->assertEquals($policies[0]->getUser()->getId(), $bInvitation->getInviter()->getId());
        $this->assertEquals($policies[1]->getUser()->getId(), $bInvitee->getId());
        $this->assertNull($this->invitationRepo->getOwnInvitation($policies[0]));
        $this->assertNull($this->invitationRepo->getOwnInvitation($policies[2]));
    }
}
