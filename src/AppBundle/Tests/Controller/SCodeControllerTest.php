<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Document\Phone;

/**
 * @group functional-net
 */
class SCodeControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;

    public function tearDown()
    {
    }

    public function testSCodeNonUser()
    {
        self::$phone = null;
        $this->createSCode('testSCodeNonUser-code');

        $repo = self::$dm->getRepository(SCode::class);
        $scode = $repo->findOneBy(['active' => true]);
        $this->assertNotNull($scode);
        $url = sprintf('/scode/%s', $scode->getCode());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
        // check if phone forms are pointing to the right location
        self::verifySearchFormData($crawler->filter('form'), '/phone-insurance/', 1);

    }

    public function testSCodeUser()
    {
        self::$phone = null;
        $this->createSCode('testSCodeUser-code');

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

    private function createSCode($emailBase)
    {
        // ensure scode exists
        $policy = $this->createUserPolicy(true);
        $policy->getUser()->setEmail(self::generateEmail($emailBase, $this));
        self::$dm->persist($policy->getUser());
        self::$dm->persist($policy);
        self::$dm->flush();
    }
}
