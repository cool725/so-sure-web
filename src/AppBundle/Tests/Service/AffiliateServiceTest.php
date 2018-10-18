<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\Attribution;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\AffiliateService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Address;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Invitation\SCodeInvitation;
use AppBundle\Document\Invitation\FacebookInvitation;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;

use AppBundle\Service\InvitationService;
use AppBundle\Service\MailerService;

use AppBundle\Event\InvitationEvent;

use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;
use AppBundle\Exception\DuplicateInvitationException;

/**
 * @group functional-nonet
 *
 * AppBundle\\Tests\\Service\\SCodeServiceTest
 */
class AffiliateServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var UserRepository */
    protected static $userRepo;
    /** @var AffiliateService */
    protected static $affiliateService;

    public static function setUpBeforeClass()
    {
        //start the symfony kernel
        $kernel = static::createKernel();
        $kernel->boot();

        //get the DI container
        self::$container = $kernel->getContainer();

        //now we can instantiate our service (if you want a fresh one for
        //each test method, do this in setUp() instead
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var UserRepository $userRepo */
        $userRepo = self::$dm->getRepository(User::class);
        self::$userRepo = $userRepo;
        self::$userManager = self::$container->get('fos_user.user_manager');

        /** @var AffiliateService $affiliateService */
        $affiliateService = self::$container->get('app.affiliate');
        self::$affiliateService = $affiliateService;
    }

    public function setUp()
    {
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
    }

    private function createTestAffiliate($name, $cpa, $days, $line1, $city, $source = '', $lead = '')
    {
        $affiliate = new AffiliateCompany();
        $address = new Address();
        $address->setLine1($line1);
        $address->setCity($city);
        $address->setPostcode('SW1A 0PW');
        $affiliate->setName($name);
        $affiliate->setAddress($address);
        $affiliate->setCPA($cpa);
        $affiliate->setDays($days);
        $affiliate->setCampaignSource($source);
        $affiliate->setLeadSource('scode');
        $affiliate->setLeadSourceDetails($lead);
        self::$dm->persist($affiliate);
        self::$dm->flush();
        return $affiliate;
    }

    private function createTestUser($policyAge, $email, $source)
    {
        $policy = self::createUserPolicy(true, new \DateTime($policyAge));
        $policy->getUser()->setEmail(static::generateEmail($email, $this));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $user = $policy->getUser();
        $attribution = new Attribution();
        $attribution->setCampaignSource($source);
        $user->setAttribution($attribution);
        self::$dm->persist($policy);
        self::$dm->persist($user);
        self::$dm->flush();
    }

    public function tearDown()
    {
        self::$dm->clear();
    }

    public function testGenerateAffiliateCampaign()
    {
        $affiliate = $this->createTestAffiliate('Foo', 4.5, 30, '8 Foo Bar', 'FooBarTown', 'foobar');
        $user = $this->createTestUser('31 days ago', 'testGenerateAffiliateCampaign', 'foobar');
        $charges = self::$affiliateService->generate();
        $this->assertEquals(1, $charges);
    }

    public function testGenerateAffiliateLead()
    {
        $affiliate = $this->createTestAffiliate('Foo', 4.5, 30, '8 Foo Bar', 'FooBarTown', '', 'foobar');
        $user = $this->createTestUser('31 days ago', 'testGenerateAffiliateLead', 'foobar');
        $charges = self::$affiliateService->generate();
        $this->assertEquals(1, $charges);
        $charges = self::$affiliateService->generate();
        $this->assertEquals(0, $charges);
    }

    public function testGetMatchingUsers()
    {
        $affiliate = $this->createTestAffiliate('foob', 2.5, 30, '655 goereverf fewjf', 'london', 'foobAds');
        $affiliate2 = $this->createTestAffiliate('barf', 4.5, 60, '244 fqref erf', 'colchester', 'barfAds');
        $this->assertEquals(0, count(self::$affiliateService->getMatchingUsers($affiliate)));
        $this->createTestUser('31 days ago', 'aaa', 'foobAds');
        $this->createTestUser('61 days ago', 'bbb', 'foobAds');
        $this->createTestUser('91 days ago', 'ccc', 'foobAds');
        $this->createTestUser('1 day ago', 'ddd', 'foobAds');
        $this->createTestUser('3 days ago', 'eee', 'barfAds');
        $this->createTestUser('70 days ago', 'fff', 'barfAds');
        $this->assertEquals(4, count(self::$affiliateService->getMatchingUsers($affiliate)));
        $this->assertEquals(2, count(self::$affiliateService->getMatchingUsers($affiliate2)));
        $this->assertEquals(0, count(self::$affiliateService->getMatchingUsers($affiliate, true)));
        self::$affiliateService->generate();
        $this->assertEquals(3, count(self::$affiliateService->getMatchingUsers($affiliate, true)));
        $this->assertEquals(1, count(self::$affiliateService->getMatchingUsers($affiliate)));
        $this->assertEquals(1, count(self::$affiliateService->getMatchingUsers($affiliate2, true)));
        $this->assertEquals(1, count(self::$affiliateService->getMatchingUsers($affiliate2)));
    }
}
