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

    public function tearDown()
    {
        self::$dm->clear();
    }

    public function testGenerateAffiliateCampaign()
    {
        $affiliate = new AffiliateCompany();
        $address = new Address();

        $address->setLine1('8 Foo Bar');
        $address->setCity('FooBarTown');
        $address->setPostcode('bx11lt');

        $affiliate->setName('Foo');
        $affiliate->setAddress($address);
        $affiliate->setCPA(4.5);
        $affiliate->setDays(30);
        $affiliate->setCampaignSource('foobar');

        $thirtyOneDaysAgo = new \DateTime();
        $thirtyOneDaysAgo = $thirtyOneDaysAgo->sub(new \DateInterval('P31D'));
        $policy = self::createUserPolicy(true, $thirtyOneDaysAgo);
        $policy->getUser()->setEmail(static::generateEmail('testGenerateAffiliateCampaign', $this));
        $policy->setStatus(Policy::STATUS_ACTIVE);

        $user = $policy->getUser();
        $attribution = new Attribution();
        $attribution->setCampaignSource('foobar');
        $user->setAttribution($attribution);

        self::$dm->persist($affiliate);
        self::$dm->persist($policy);
        self::$dm->persist($user);
        self::$dm->flush();

        $charges = self::$affiliateService->generate();

        $this->assertGreaterThan(0, $charges);
    }

    public function testGenerateAffiliateLead()
    {

    }
}
