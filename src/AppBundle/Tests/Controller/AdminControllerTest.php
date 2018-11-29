<?php

namespace AppBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Lead;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\Charge;
use AppBundle\Document\CurrencyTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData;

/**
 * @group functional-net
 * AppBundle\\Tests\\Controller\\AdminControllerTest
 */
class AdminControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    public function tearDown()
    {
        self::$client->getCookieJar()->clear();
    }

    public function setUp()
    {
        parent::setUp();
        $this->clearRateLimit();
    }

    public function testAdminLoginOk()
    {
        $this->login('patrick@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin');
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

        $this->login('patrick@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin');
        $crawler = self::$client->request('GET', sprintf('/admin/policy/%s', $policy->getId()));
        self::verifyResponse(200);
    }

    public function testAdminClaimUpdateForm()
    {
        // make one claim just in case no claim was created and page is empty
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdminClaimUpdateForm'.rand(), $this),
            'bar'
        );
        $phone = static::getRandomPhone(self::$dm);
        $policy = static::initPolicy($user, self::$dm, $phone, null, true, true);
        $claim = new Claim();
        $claim->setNumber('TEST/789');
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-2 days'));
        $claim->setType(Claim::TYPE_THEFT);
        $policy->addClaim($claim);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->login('patrick@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin');
        $crawler = self::$client->request('GET', '/admin/claims');
        self::verifyResponse(200);

        // print_r($crawler->html());
        // get one phone from the page
        $button = $crawler->filter('button[data-target="#claimsModal"]')->first()->attr('data-claim');
        $this->assertTrue(isset($button));

        $claimData = json_decode($button, true);

        $form = $crawler->filter('form[id="phone-alternative-form"]')->form();
        $form['id'] = $claimData['id'];
        $form['approved-date'] = '2022-01-01';
        $form['change-approved-date'] = 'on';
        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repoClaim = $dm->getRepository(Claim::class);
        /** @var Claim $newClaim */
        $newClaim = $repoClaim->find($claimData['id']);
        $this->assertEquals('2022-01-01', $newClaim->getApprovedDate()->format('Y-m-d'));
    }

    public function testAdminClaimDelete()
    {
        // make one claim just in case no claim was created and page is empty
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdminClaimDelete'.rand(), $this),
            'bar'
        );
        $phone = static::getRandomPhone(self::$dm);
        $policy = static::initPolicy($user, self::$dm, $phone, null, true, true);
        $charge = new Charge();
        $charge->setAmount(0.02);
        $claim = new Claim();
        $claim->setNumber('TEST/456');
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-2 days'));
        $claim->setType(Claim::TYPE_THEFT);
        $claim->addCharge($charge);
        $policy->addClaim($claim);
        self::$dm->persist($claim);
        self::$dm->flush();
        $claimId = $claim->getId();
        $this->assertNotNull($charge->getClaim());

        $this->login('patrick@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin');
        $crawler = self::$client->request('GET', '/admin/claims');
        self::verifyResponse(200);
        $form = $crawler->filter('form[id="delete-claim-form"]')->form();
        $form['id'] = $claimId;
        self::$client->submit($form);
        self::verifyResponse(302);
        $this->assertEquals(self::$router->generate('admin_claims'), $this->getClientResponseTargetUrl());

        $dm = $this->getDocumentManager(true);
        $claimRepo = $dm->getRepository(Claim::class);
        $this->assertNull($claimRepo->find($claimId));
        $this->assertNull($claimRepo->find($claim->getId()));

        $chargeRepo = $dm->getRepository(Charge::class);
        /** @var Charge $updatedCharge */
        $updatedCharge = $chargeRepo->find($charge->getId());
        $this->assertNull($updatedCharge->getClaim());
    }

    public function testClaimsClaimDelete()
    {
        $repoClaim = self::$dm->getRepository(Claim::class);
        // make one claim just in case no claim was created and page is empty
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testClaimsClaimDelete'.rand(), $this),
            'bar'
        );
        $phone = static::getRandomPhone(self::$dm);
        $policy = static::initPolicy($user, self::$dm, $phone, null, true, true);
        $claim = new Claim();
        $claim->setNumber('TEST/123');
        $claim->setProcessed(false);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setApprovedDate(new \DateTime('-2 days'));
        $claim->setType(Claim::TYPE_THEFT);
        $policy->addClaim($claim);
        self::$dm->persist($claim);
        self::$dm->flush();
        $claimId = $claim->getId();
        $this->login('claims@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'claims/policies');

        // forbidden page
        self::$client->request('GET', self::$router->generate('admin_claims'));
        self::verifyResponse(403);
        // forbidden page as well
        $crawler = self::$client->request('POST', self::$router->generate('admin_claims_delete_claim'));
        self::verifyResponse(403);

        $crawler = self::$client->request('GET', self::$router->generate('claims_policy', ['id' => $policy->getId()]));
        self::verifyResponse(200);
        $this->assertContains($policy->getId(), $crawler->html());

        // there are two definitions of the form on the page
        $form = $crawler->filter('form[id="phone-alternative-form"]');
        $this->assertEquals(1, count($form));

        $form = $crawler->filter('form[id="somefakeid"]');
        $this->assertEquals(0, count($form));
        //quoteModal does not include delete form
        $form = $crawler->filter('form[id="delete-claim-form"]');
        $this->assertEquals(0, count($form));
    }
}
