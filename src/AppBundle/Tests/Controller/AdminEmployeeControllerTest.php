<?php

namespace AppBundle\Tests\Controller;

use AppBundle\Document\PolicyTerms;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\CurrencyTrait;
use AppBundle\DataFixtures\MongoDB\b\User\LoadUserData;

/**
 * @group functional-net
 */
class AdminEmployeeControllerTest extends BaseControllerTest
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use CurrencyTrait;

    protected static $dm;

    public function tearDown()
    {
    }

    public function setUp()
    {
        parent::setUp();
        $this->clearRateLimit();
        self::$dm = self::$client->getContainer()->get('doctrine_mongodb.odm.default_document_manager');
    }

    public function testDebtCollectorEmails()
    {
        // start policy 45 days ago
        $date = new \DateTime();
        $date->sub(new \DateInterval('P91D'));
        $dateClaim = new \DateTime();
        $dateClaim->sub(new \DateInterval('P90D'));
        $email = 'testDebtCollectionUser@so-sure.net';

        $userRepo = self::$dm->getRepository(User::class);
        $phoneRepo = self::$dm->getRepository(Phone::class);
        $policyTermsRepo = self::$dm->getRepository(PolicyTerms::class);
        $policyTerm = $policyTermsRepo->findOneBy([]);

        $user = $userRepo->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $email = 'testDebtCollectionUser@so-sure.net';
            $user->setEmail($email);
            self::$dm->persist($user);
        }

        // generate phone
        $policy = new SalvaPhonePolicy();

        $phone = $phoneRepo->findOneBy(['model' => 'iPhone 7']);
        $policy->setUser($user);
        $policy->setPhone($phone);
        $policy->setStart($date);
        $policy->setPolicyTerms($policyTerm);
        $policy->setPremiumInstallments(12);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);

        self::$dm->persist($policy);

        $claim = new Claim();
        $claim->setCreatedDate($dateClaim);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setApprovedDate($dateClaim);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setReplacementImei(self::generateRandomImei());
        $claim->setReplacementPhone($phone);
        $claim->setPolicy($policy);
        self::$dm->persist($claim);
        $policy->addClaim($claim);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setCancelledReason(Policy::CANCELLED_UNPAID);
        self::$dm->flush();
        $url = sprintf('/admin/policy/%s', $policy->getId());
        $this->login('patrick@so-sure.com', LoadUserData::DEFAULT_PASSWORD, 'admin/');
        $crawler = self::$client->request('GET', $url);
        $form = $crawler->selectButton('Debt')->form();
        self::$client->enableProfiler();
        self::$client->submit($form);
        self::$client->getResponse();
        $mailCollector = self::$client->getProfile()->getCollector('swiftmailer');
        $collectedMessages = $mailCollector->getMessages();
        $this->assertContains('Please start the debt collection', $collectedMessages[0]->getBody());
        $this->assertContains('Debt collection process started', $collectedMessages[1]->getBody());
    }
}
