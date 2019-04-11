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
        /** @var SCode $scode */
        $scode = $repo->findOneBy(['active' => true]);
        $this->assertNotNull($scode);
        $url = sprintf('/scode/%s', $scode->getCode());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
        $this->assertContains(sprintf("%s", $scode->getUser()->getName()), $crawler->html());
        $this->assertContains('<meta name="robots" content="noindex">', $crawler->html());
        // check if phone forms are pointing to the right location
        $this->assertHasFormAction($crawler, '/phone-search-dropdown');
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
        $this->login($email, $password, 'user');

        $repo = self::$dm->getRepository(SCode::class);
        /** @var SCode $scode */
        $scode = $repo->findOneBy(['active' => true]);
        $this->assertNotNull($scode);
        $url = sprintf('/scode/%s', $scode->getCode());
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(302);
        $this->assertTrue($this->isClientResponseRedirect('/user'));
    }

    public function testMultibyte()
    {
        $this->logout();

        $email = self::generateEmail('testMultibyte', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $user->setFirstName("żbieta");
        $user->setLastName("Eżbieta");

        $scode = new SCode();
        $scode->setType(SCode::TYPE_STANDARD);
        $scode->generateNamedCode($user, 5);
        $this->assertEquals("żeżb0005", $scode->getCode());

        static::$dm->persist($user);
        static::$dm->persist($scode);

        $phone = self::getRandomPhone(self::$dm);
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $scode->setPolicy($policy);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $repo = self::$dm->getRepository(SCode::class);
        /** @var SCode $updatedScode */
        $updatedScode = $repo->find($scode->getId());
        $this->assertNotNull($updatedScode);
        $url = sprintf('/scode/%s', $updatedScode->getCode());
        //print_r($url);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200, null, $crawler);
        $this->assertContains(sprintf("%s", $user->getName()), $crawler->html());

        $url = sprintf('/scode/%s', urlencode($scode->getCode()));
        //print_r($url);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200, null, $crawler);
        $this->assertContains(sprintf("%s", $user->getName()), $crawler->html());
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
