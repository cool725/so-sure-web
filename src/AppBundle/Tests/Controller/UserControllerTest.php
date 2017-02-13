<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;

/**
 * @group functional-net
 */
class UserControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
    }

    public function testUserOk()
    {
        $email = self::generateEmail('testUserOk', $this);
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

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);
    }

    public function testUserUnpaidPolicy()
    {
        $email = self::generateEmail('testUserUnpaid', $this);
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
        $policy->setStatus(Policy::STATUS_UNPAID);
        self::$dm->flush();
        //print_r($policy->getClaimsWarnings());
        $this->assertFalse($policy->getUser()->hasActivePolicy());
        $this->login($email, $password, 'user/unpaid');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(500);
    }

    public function testUserInvalidPolicy()
    {
        $email = self::generateEmail('testUserInvalid', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::$dm->flush();
        $this->login($email, $password, 'user/invalid');

        $crawler = self::$client->request('GET', '/user/invalid');
        self::verifyResponse(200);
    }
}
