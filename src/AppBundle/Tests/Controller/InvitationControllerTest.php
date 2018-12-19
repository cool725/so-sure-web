<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\Policy;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\Invitation\EmailInvitation;

/**
 * @group functional-net
 */
class InvitationControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testInvitation()
    {
        $invitationRepo = self::$dm->getRepository(EmailInvitation::class);
        /** @var EmailInvitation $invitation */
        $invitation = $invitationRepo->findOneBy([]);
        $this->assertNotNull($invitation);
        $url = sprintf('/invitation/%s', $invitation->getId());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }

    public function testPhoneSearchInvitation()
    {
        //generate user, policy and invitation
        $email = self::generateEmail('testPhoneSearchInvitation', $this);
        $emailInvitee = self::generateEmail('testPhoneSearchInvitationInvitee', $this);
        $user = self::createUser(
            self::$userManager,
            $email,
            'foo',
            null,
            self::$dm
        );
        $phone = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $invitation = new EmailInvitation();
        $policy->addInvitation($invitation);
        $invitation->setStatus(EmailInvitation::STATUS_SENT);
        $invitation->setEmail($emailInvitee);
        self::$dm->persist($invitation);

        $url = sprintf('/invitation/%s', $invitation->getId());
        $crawler = self::$client->request('GET', $url);
        $data = self::$client->getResponse();
        $this->assertEquals(200, $this->getClientResponseStatusCode());
        $this->assertHasFormAction($crawler, '/select-phone-dropdown');
    }
}
