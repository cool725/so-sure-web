<?php

namespace AppBundle\Tests\Controller;

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
        $invitation = $invitationRepo->findOneBy([]);
        $this->assertNotNull($invitation);
        $url = sprintf('/invitation/%s', $invitation->getId());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }
}
