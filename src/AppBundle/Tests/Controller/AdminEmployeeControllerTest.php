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
use Symfony\Bundle\SwiftmailerBundle\DataCollector\MessageDataCollector;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface;

/**
 * @group functional-net
 */
class AdminEmployeeControllerTest extends BaseControllerTest
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
        $dm = $this->getDocumentManager(true);
        self::$dm = $dm;
    }

    public function testDebtCollectorEmails()
    {
        // start policy 45 days ago
        $date = \DateTime::createFromFormat('U', time());
        $date->sub(new \DateInterval('P91D'));
        $dateClaim = \DateTime::createFromFormat('U', time());
        $dateClaim->sub(new \DateInterval('P90D'));
        $email = $this->generateEmail('testDebtCollectorEmails', $this);

        $userRepo = self::$dm->getRepository(User::class);
        $phoneRepo = self::$dm->getRepository(Phone::class);
        $phone = $phoneRepo->findOneBy(['make' => 'Apple', 'model' => 'iPhone 7']);

        $user = $userRepo->findOneBy(['email' => $email]);
        if (!$user) {
            $user = self::createUser(self::$userManager, $email, 'foo');
        }

        $policy = static::initPolicy(
            $user,
            self::$dm,
            $phone,
            $date,
            false,
            true,
            true
        );

        $policy->setPremiumInstallments(12);
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);


        $claim = new Claim();
        $claim->setCreatedDate($dateClaim);
        $claim->setType(Claim::TYPE_THEFT);
        $claim->setApprovedDate($dateClaim);
        $claim->setStatus(Claim::STATUS_APPROVED);
        $claim->setReplacementImei(self::generateRandomImei());
        $claim->setReplacementPhone($phone);
        $claim->setPolicy($policy);

        $policy->addClaim($claim);
        $policy->setStatus(Policy::STATUS_CANCELLED);
        $policy->setCancelledReason(Policy::CANCELLED_UNPAID);
        self::$dm->flush();


        $url = sprintf('/admin/policy/%s', $policy->getId());
        $this->login(LoadUserData::DEFAULT_ADMIN, LoadUserData::DEFAULT_PASSWORD, 'admin');
        $crawler = self::$client->request('GET', $url);
        $form = $crawler->selectButton('Debt')->form();
        self::$client->enableProfiler();
        self::$client->submit($form);
        self::$client->getResponse();
        if (!self::$client->getProfile()) {
            throw new \Exception('Profiler must be enabled');
        }
        /** @var MessageDataCollector $mailCollector */
        $mailCollector = self::$client->getProfile()->getCollector('swiftmailer');
        $collectedMessages = $mailCollector->getMessages();
        $this->assertContains('Please start the debt collection', $collectedMessages[0]->getBody());
        $this->assertContains('authorised to chase your debt to so-sure', $collectedMessages[1]->getBody());
    }
}
