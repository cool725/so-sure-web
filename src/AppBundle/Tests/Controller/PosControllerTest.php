<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Lead;

/**
 * @group functional-net
 * AppBundle\\Tests\\Controller\\PosControllerTest
 */
class PosControllerTest extends BaseControllerTest
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

    public function testHellozAction()
    {
        $url = self::$router->generate('pos_standard', ['name' => 'helloz']);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }

    public function testHellozActionLoggedIn()
    {
        $email = self::generateEmail('testHellozActionLoggedIn', $this, true);
        $password = 'foo';
        $phone = self::getRandomPhone(self::$dm);

        $user = self::createUser(
            self::$userManager,
            $email,
            $password,
            $phone,
            self::$dm
        );

        $this->login($email, $password);

        $url = self::$router->generate('pos_standard', ['name' => 'helloz']);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);
    }

    public function testHellozSubmit()
    {
        $url = self::$router->generate('pos_standard', ['name' => 'helloz']);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);

        $form = $crawler->selectButton('lead_form_submit')->form();

        $email =  self::generateEmail('testHellozSubmit', $this, true);
        $form->setValues([
            'lead_form[submittedBy]' => 'customer',
            'lead_form[name]' => 'Helloz Test',
            'lead_form[email]' => $email,
            'lead_form[phone]' => self::getRandomPhone(self::$dm)->getId(),
            'lead_form[optin]' => true,
        ]);

        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $leadRepo = $dm->getRepository(Lead::class);
        $updatedLeads = $leadRepo->findBy([
            'emailCanonical' => $email
        ]);

        $this->assertEquals(1, count($updatedLeads));
    }

    public function testHellozNonOptIn()
    {
        $url = self::$router->generate('pos_standard', ['name' => 'helloz']);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);

        $form = $crawler->selectButton('lead_form_submit')->form();

        $email =  self::generateEmail('testHellozNonOptIn', $this, true);
        $form->setValues([
            'lead_form[submittedBy]' => 'customer',
            'lead_form[name]' => 'Helloz Test',
            'lead_form[email]' => $email,
            'lead_form[phone]' => self::getRandomPhone(self::$dm)->getId(),
            'lead_form[optin]' => false,
        ]);

        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $leadRepo = $dm->getRepository(Lead::class);
        $updatedLeads = $leadRepo->findBy([
            'emailCanonical' => $email
        ]);

        $this->assertEquals(0, count($updatedLeads));
    }

    public function testHellozSubmitDuplicate()
    {
        $url = self::$router->generate('pos_standard', ['name' => 'helloz']);
        $crawler = self::$client->request('GET', $url);
        self::verifyResponse(200);

        $form = $crawler->selectButton('lead_form_submit')->form();

        $email =  self::generateEmail('testHellozSubmitDuplicate', $this, true);
        $phone = self::getRandomPhone(self::$dm)->getId();
        $form->setValues([
            'lead_form[submittedBy]' => 'customer',
            'lead_form[name]' => 'Helloz Test',
            'lead_form[email]' => $email,
            'lead_form[phone]' => $phone,
            'lead_form[optin]' => true,
        ]);

        $crawler = self::$client->submit($form);
        self::verifyResponse(200);

        $form = $crawler->selectButton('lead_form_submit')->form();

        $form->setValues([
            'lead_form[submittedBy]' => 'customer',
            'lead_form[name]' => 'Helloz Test',
            'lead_form[email]' => $email,
            'lead_form[phone]' => $phone,
            'lead_form[optin]' => true,
        ]);

        $crawler = self::$client->submit($form);
        self::verifyResponse(302);

        $dm = $this->getDocumentManager(true);
        $leadRepo = $dm->getRepository(Lead::class);
        $updatedLeads = $leadRepo->findBy([
            'emailCanonical' => $email
        ]);

        /* Assert 1 as duplicate leads should not be persisted onto the DB */
        $this->assertEquals(1, count($updatedLeads));
    }
}
