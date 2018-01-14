<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData;

/**
 * @group functional-net
 * AppBundle\\Tests\\Controller\\ClaimsControllerTest
 */
class ClaimsControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
    }

    public function testClaimsLoginViewPartialPolicy()
    {
        $email = self::generateEmail('testClaimsLoginViewPolicyPending', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        self::$dm->flush();

        $this->login('claims@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $this->verifyResponse(200);
    }

    public function testClaimsLoginViewPolicyActive()
    {
        $email = self::generateEmail('testClaimsLoginViewPolicyActive', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        self::addAddress($user);
        self::$dm->flush();
        $policy = self::initPolicy($user, self::$dm, $phone, null, true, true);
        $policy->setStatus(Policy::STATUS_ACTIVE);
        self::$dm->flush();
        $this->assertTrue($policy->getUser()->hasActivePolicy());

        $this->login('claims@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        $crawler = self::$client->request('GET', sprintf('/claims/policy/%s', $policy->getId()));
        $this->verifyResponse(200);
    }
}
