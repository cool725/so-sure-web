<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;

/**
 * @group functional-net
 */
class SCodeControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    public function tearDown()
    {
    }

    public function testSCodeNonUser()
    {
        $repo = self::$dm->getRepository(SCode::class);
        $scode = $repo->findOneBy(['active' => true]);
        $this->assertNotNull($scode);
        $url = sprintf('/scode/%s', $scode->getCode());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }

    public function testSCodeUser()
    {
        $email = self::generateEmail('testSCodeUser', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/');

        $repo = self::$dm->getRepository(SCode::class);
        $scode = $repo->findOneBy(['active' => true]);
        $this->assertNotNull($scode);
        $url = sprintf('/scode/%s', $scode->getCode());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(302);
        $this->assertTrue(self::$client->getResponse()->isRedirect('/user/'));
    }
}
