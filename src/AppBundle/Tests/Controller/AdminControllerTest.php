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
 */
class AdminControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
    }

    public function setUp()
    {
        parent::setUp();
        $this->clearRateLimit();
    }

    public function testAdminLoginOk()
    {
        $this->login('patrick@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin/');
    }

    public function testAdminPartialPolicy()
    {
        $email = self::generateEmail('testAdminPartialPolicy', $this);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);
        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );
        $policy = self::initPolicy($user, self::$dm, $phone);

        $this->login('patrick@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin/');
        $crawler = self::$client->request('GET', sprintf('/admin/policy/%s', $policy->getId()));
        self::verifyResponse(200);
    }
}
