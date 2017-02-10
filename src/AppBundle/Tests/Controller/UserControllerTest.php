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
    }

    private function login($username, $password, $location = null)
    {
        $crawler = self::$client->request('GET', '/login');
        self::verifyResponse(200);
        $form = $crawler->selectButton('_submit')->form();
        $form['_username'] = $username;
        $form['_password'] = $password;
        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);
        self::$client->followRedirects(false);
        if ($location) {
            $this->assertEquals(
                self::$client->getHistory()->current()->getUri(),
                sprintf('http://localhost/%s', $location)
            );
        }
    }
}
