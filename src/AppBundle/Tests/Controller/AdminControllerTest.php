<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\Charge;
use AppBundle\Document\CurrencyTrait;
use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData;
use AppBundle\Document\Policy;

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
        $route = $crawler->filter('button[data-target="#claimsModal"]')->first()->attr('data-route');

        if (empty($route)) {
            throw new \Exception('Claim route not found');
        }

        $crawler = self::$client->request('GET', $route);
        self::verifyResponse(200);

        $form = $crawler->filter('form[name="claims_form"]')->form();

        $form->setValues([
            'claims_form[approvedDate]' => '2022-01-01',
        ]);

        self::$client->submit($form);
        self::verifyResponse(302);
        $crawler = self::$client->followRedirect();
        self::expectFlashSuccess($crawler, sprintf('Claim %s updated', $claim->getNumber()));

        $dm = $this->getDocumentManager(true);
        $repoClaim = $dm->getRepository(Claim::class);
        /** @var Claim $newClaim */
        $newClaim = $repoClaim->find($claim->getId());
        $this->assertEquals('2022-01-01', $newClaim->getApprovedDate()->format('Y-m-d'));
    }

    public function testAdminLinkClaimForm()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testAdminLinkClaimForm', $this),
            'bar'
        );

        $oldPolicy = self::initPolicy(
            $user,
            self::$dm,
            static::getRandomPhone(self::$dm),
            null,
            true,
            true
        );

        $newPolicy = self::initPolicy(
            $user,
            self::$dm,
            static::getRandomPhone(self::$dm),
            null,
            true,
            true
        );

        $claim = new Claim();
        $claim->setPolicy($oldPolicy);
        $claim->setNumber('TEST/3213');
        $claim->setType(Claim::TYPE_THEFT);
        self::$dm->persist($claim);
        self::$dm->flush();

        $this->login('mariusz@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin');

        $crawler = self::$client->request('GET', '/admin/policy/' . $newPolicy->getId());
        self::verifyResponse(200);

        $form = $crawler->selectButton('link_claim_form_submit')->form();

        $form['link_claim_form[id]'] = $claim->getId();
        $form['link_claim_form[number]'] = $claim->getNumber();
        $form['link_claim_form[note]'] = 'A test justification';

        self::$client->followRedirects();
        $crawler = self::$client->submit($form);
        self::verifyResponse(200);

        $dm = $this->getDocumentManager(true);
        $repoPolicy = $dm->getRepository(Policy::class);
        /** @var Policy $updatedPolicy */
        $updatedPolicy = $repoPolicy->find($newPolicy->getId());

        $repoClaim = $dm->getRepository(Claim::class);
        /** @var Claim $updatedClaim */
        $updatedClaim = $repoClaim->find($claim->getId());

        $linkedClaims = $updatedPolicy->getLinkedClaims()->toArray();
        $linkedPolicy = $updatedClaim->getLinkedPolicy();

        /** @var Claim $linkedClaim */
        foreach ($linkedClaims as $linkedClaim) {
            if ($linkedClaim->getId() === $updatedClaim->getId()) {
                $link = $linkedClaim;
            }
        }

        $this->assertTrue(isset($link));
        $this->assertEquals($updatedPolicy->getId(), $linkedPolicy->getId());
    }

    public function testImeiFormAction()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testImeiFormAction', $this),
            'bar'
        );

        $policy = self::initPolicy(
            $user,
            self::$dm,
            static::getRandomPhone(self::$dm),
            null,
            true,
            true
        );

        $this->login('mariusz@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin');

        $crawler = self::$client->request('GET', '/admin/policy/' . $policy->getId());
        self::verifyResponse(200);

        $form = $crawler->selectButton('imei_form_update')->form();

        $imei = self::generateRandomImei();
        $form['imei_form[imei]'] = $imei;

        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repoPolicy = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repoPolicy->find($policy->getId());

        self::assertEquals($imei, $updatedPolicy->getImei());
    }

    public function testImeiFormActionPhone()
    {
        $user = static::createUser(
            static::$userManager,
            static::generateEmail('testImeiFormAction', $this),
            'bar'
        );

        $policy = self::initPolicy(
            $user,
            self::$dm,
            static::getRandomPhone(self::$dm),
            null,
            true,
            true
        );

        $this->login('mariusz@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin');

        $crawler = self::$client->request('GET', '/admin/policy/' . $policy->getId());
        self::verifyResponse(200);

        $form = $crawler->selectButton('imei_form_update')->form();

        $imei = self::generateRandomImei();
        $phone = self::getRandomPhone(self::$dm);
        $form['imei_form[imei]'] = $imei;
        $form['imei_form[phone]'] = $phone->getId();

        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $repoPolicy = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $updatedPolicy */
        $updatedPolicy = $repoPolicy->find($policy->getId());

        self::assertEquals($imei, $updatedPolicy->getImei());
        self::assertEquals($phone->getId(), $updatedPolicy->getPhone()->getId());
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
        $crawler = self::$client->request('GET', sprintf('/admin/claims-form/%s/policy', $claimId));
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
