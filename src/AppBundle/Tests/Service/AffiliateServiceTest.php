<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\Attribution;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Repository\ChargeRepository;
use AppBundle\Service\AffiliateService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Address;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Charge;
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
    protected static $affiliateService;
    protected static $dm;
    protected static $userRepository;
    protected static $chargeRepository;
    protected static $policyRepository;
    protected static $affiliateRepository;

    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();

        self::$container = $kernel->getContainer();
        self::$userManager = self::$container->get('fos_user.user_manager');
        self::$affiliateService = self::$container->get('app.affiliate');
        self::$dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$userRepository = self::$dm->getRepository(User::class);
        self::$chargeRepository = self::$dm->getRepository(Charge::class);
        self::$policyRepository = self::$dm->getRepository(Policy::class);
        self::$affiliateRepository = self::$dm->getRepository(AffiliateCompany::class);
    }

    public function setUp()
    {
        $this->purge(User::class);
        $this->purge(AffiliateCompany::class);
        $this->purge(Charge::class);
        $this->purge(Policy::class);
    }


    /**
     * Tests that the generate works as intended when there are no affiliates.
     */
    public function testGenerateEmpty()
    {
        $this->assertEmpty(self::$affiliateService->generate());
        $this->assertEquals(0, count(self::$chargeRepository->findBy([])));
    }

    public function testGenerateCampaignSource()
    {

    }

    public function testGenerateAffiliateLead()
    {

    }

    public function testGenerateOngoingCharges()
    {

    }

    public function testGenerateOneOffCharges()
    {

    }

    /**
     * Tests getting matching users from all status groups when status groups are empty.
     */
    public function testGetMatchingUsersEmpty()
    {
        $this->assertEmpty(
            self::$affiliateService->getMatchingUsers(
                self::$affiliateService->findBy(["name" => "sourceAffiliateA"])
            )
        );

    }

    /**
     * Tests getting matching users from all status groups when all status groups are populated.
     */
    public function testGetMatchingUsersNormal()
    {

    }



    /**
     * Drops every document of type given from the database.
     * @param class $class is the php class corresponding to the mongodb document collection to get dropped.
     */
    private function purge($class)
    {
        self::$dm->createQueryBuilder($class)->remove()->getQuery()->execute();
    }

    /**
     * Creates a good state from which to test.
     */
    private function createState()
    {
        $sourceA = $this->createTestAffiliate("sourceAffiliateA", 15.3, 30, "line 1", "london", "sourceA");
        $sourceB = $this->createTestAffiliate("sourceAffiliateB", 0.2, 60, "line 1", "london", "sourceB");
        $leadA = $this->createTestAffiliate("leadAffiliateA", 10.9, 90, "line 1", "london", "", "leadA");
        $leadB = $this->createTestAffiliate("leadAffiliateB", 3.4, 30, "line 1", "london", "", "leadB");

        $this->createTestUser(10, "tony@qergre.com", "sourceA");
        $this->createTestUser(20, "bango@qergre.com", "sourceA");
        $this->createTestUser(30, "tango@qergre.com", "sourceA");
        $this->createTestUser(40, "snakeHandler@qergre.com", "sourceA");
        $this->createTestUser(50, "wearingAHat@qergre.com", "sourceA");
        $this->createLonelyTestUser("sproingo", "sourceA");
        $this->createLonelyTestUser("borakoef", "sourceA");

        $this->createTestUser(33, "clove@qergre.com", "", "leadA");
        $this->createTestUser(51, "bone@qergre.com", "", "leadA");
        $this->createTestUser(95, "bberb@qergre.com", "", "leadA");
        $this->createTestUser(101, "tonyAbbot@qergre.com", "", "leadA");
        $this->createTestUser(123, "ujoireg@qergre.com", "", "leadA");
        $this->createLonelyTestUser("jellyfish", "", "leadA");
        $this->createLonelyTestUser("eel", "", "leadA");

        self::$dm->flush();
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
        return $affiliate;
    }

    private function createTestUser($policyAge, $email, $source = "", $lead = "")
    {
        $policy = self::createUserPolicy(true, new \DateTime($policyAge));
        $policy->getUser()->setEmail(static::generateEmail($email, $this));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setImei(self::generateRandomImei());
        $user = $policy->getUser();
        $attribution = new Attribution();
        $attribution->setCampaignSource($source);
        $user->setAttribution($attribution);
        $user->setLeadSource('scode');
        $user->setLeadSourceDetails($lead);
        $user->setFirstName($email);
        $user->setLastName($email);
        self::$dm->persist($policy);
        self::$dm->persist($user);
        return $user;
    }

    private function createLonelyTestUser($name, $source = "", $lead = "")
    {
        $user = new User();
        $user->setEmail(static::generateEmail($name, $this));
        $user->setFirstName($name);
        $user->setLastName($name);
        $attribution = new Attribution();
        $attribution->setCampaignSource($source);
        $attribution->setLeadSource($lead);
        $user->setAttribution($attribution);
        self::$dm->persist($user);
        return $user;
    }
}
