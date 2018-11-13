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
     * Tests that the AffiliateService::generate works as intended on a populated database.
     * generate method works on the whole dataset at once so there is no need for dataproviders.
     */
    public function testGenerate()
    {
        $this->assertEmpty(self::$affiliateService->generate());
        $this->assertEquals(0, count(self::$chargeRepository->findBy([])));
    }

    /**
     * Tests generating the ongoing charges for an ongoing affiliate on a populated database.
     * @param string $affiliate is the name of the affiliate to test.
     * @param array  $n         is the list of user's names for which charges will be made.
     * @dataProvider generateOngoingChargesProvider
     */
    public function testGenerateOngoingCharges($affiliate, $users)
    {
        $this->createState();
        $charges = [];
        self::$affiliateService->generateOngoingCharges($this->affiliate($affiliate), $charges);
        $this->assertEquals(count($users), count($charges));
        foreach ($users as $user) {
            $policy = new Policy();
            $policy->setStatus(Policy::STATUS_ACTIVE);
            $policy->setUser($this->userByName(self::$dm, $user));
            $policy->setStartDate(new \DateTime("200 days ago"));
        }
        self::$affiliateService->generateOngoingCharges($this->affiliate($affiliate), $charges);
        $this->assertEquals(count($users) * 2, count($charges));
    }

    /**
     * Tests generating one off charges for a one off affiliate on a populated database.
     * @param string $affiliate the name of the affiliate to test.
     * @param int    $n         is the number of charges that should be made.
     * @dataProvider generateOneOffChargesProvider
     */
    public function testGenerateOneOffCharges($affiliate, $n)
    {
        $this->createState();
        $charges = [];
        self::$affiliateService->generateOneOffCharges($this->affiliate($affiliate), $charges);
        $this->assertEquals($n, count($charges));
    }

    /**
     * Tests getting matching users from all status groups when all status groups are populated.
     * @param string $affiliate is the name of the affiliate to test on.
     * @param array  $status    is an array of the statuses we are searching for.
     * @param array  $expected  is an array of emails of the users who should be returned.
     * @dataProvider getMatchingUsersProvider
     */
    public function testGetMatchingUsers($affiliate, $status, $expected)
    {
        $this->createState();
        $expectedUsers = $this->usersByName(self::$dm, $expected);
        $users = self::$affiliateService->getMatchingUsers($this->affiliate($affiliate), $status);
        $this->assertEquals(count($expectedUsers), count($users));
        foreach($users as $user) {
            $found = false;
            $id = $user->getId();
            foreach($expectedUsers as $expectedUser) {
                if ($id == $expectedUser->getId()) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found);
        }
    }

    /**
     * Generates data to run testGenerateOngoingCharges.
     * @return array containing the test data.
     */
    public static function generateOngoingChargesProvider()
    {
        return [["campaignC", 2]];
    }

    /**
     * Generates data to run testGenerateOneOffCharges.
     * @return array containing the test data.
     */
    public static function generateOneOffChargesProvider()
    {
        return [
            ["campaignA", 3],
            ["campaignB", 0],
            ["leadA", 3]
        ];
    }

    /**
     * Generates the data to run testGetMatchingUsers.
     * @return array with the test data.
     */
    public static function getMatchingUsersProvider()
    {
        return [
            [
                "campaignA",
                [User::AQUISITION_PENDING],
                ["tony", "bango", "tango", "snakeHandler", "wearingABigHat"]
            ],
            [
                "campaignA",
                [User::AQUISITION_POTENTIAL],
                ["aaa", "bbb"]
            ],
            [
                "campaignA",
                [User::AQUISITION_LOST],
                ["lostAGuy"]
            ],
            [
                "campaignA",
                [User::AQUISITION_COMPLETED],
                ["completedAGuy"]
            ],
            [
                "leadA",
                [User::AQUISITION_PENDING, User::AQUISITION_POTENTIAL],
                ["clove", "bone", "borb", "tonyAbbot", "shelf", "jellyfish", "eel"]
            ],
            [
                "campaignB",
                [User::AQUISITION_PENDING],
                []
            ]
        ];
    }

    /**
     * Find an affiliate by name.
     * @param string $name is the name of the new user.
     * @return AffiliateCompany the company found with that name or null if not found.
     */
    private static function affiliate($name)
    {

        $items = self::$affiliateRepository->findBy(["name" => $name]);
        return reset($items);
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
     * Creates a good state in the database from which to test.
     */
    private static function createState()
    {
        $affiliate = self::createTestAffiliate("campaignA", 15.3, 30, "line 1", "london", "campaignA");
        self::createTestAffiliate("campaignB", 2.3, 60, "line 1", "london", "campaignB");
        self::createTestAffiliate("campaignC", 5.2, 30, "line 1", "london", "campaignC")->setChargeModel(AffiliateCompany::MODEL_ONGOING);
        self::createTestAffiliate("leadA", 10.9, 90, "line 1", "london", "", "leadA");
        self::createTestAffiliate("leadC", 8.1, 60, "line 1", "london", "", "leadC")->setChargeModel(AffiliateCompany::MODEL_ONGOING);

        self::createTestUser("10 days ago", "tony", "campaignA");
        self::createTestUser("20 days ago", "bango", "campaignA");
        self::createTestUser("31 days ago", "tango", "campaignA");
        self::createTestUser("40 days ago", "snakeHandler", "campaignA");
        self::createTestUser("50 days ago", "wearingABigHat", "campaignA");
        $completedAGuy = self::createTestUser("60 days ago", "completedAGuy", "campaignA");
        $lostAGuy = self::createTestUser("60 days ago", "lostAGuy", "campaignA");
        self::createLonelyTestUser("aaa", "campaignA");
        self::createLonelyTestUser("bbb", "campaignA");
        $affiliate->addConfirmedUsers($completedAGuy);
        $lostAGuy->getLatestPolicy()->setStatus(Policy::STATUS_CANCELLED);

        self::createTestUser("10 days ago", "jack", "campaignC");
        self::createTestUser("20 days ago", "john", "campaignC");
        self::createTestUser("30 days ago", "barrel", "campaignC");
        self::createTestUser("40 days ago", "smith", "campaignC");
        self::createTestUser("50 days ago", "kalvin", "campaignC");
        self::createLonelyTestUser("gabriel", "campaignC");
        self::createLonelyTestUser("michael", "campaignC");

        self::createTestUser("33 days ago", "clove", "", "leadA");
        self::createTestUser("51 days ago", "bone", "", "leadA");
        self::createTestUser("95 days ago", "borb", "", "leadA");
        self::createTestUser("101 days ago", "tonyAbbot", "", "leadA");
        self::createTestUser("123 days ago", "shelf", "", "leadA");
        self::createLonelyTestUser("jellyfish", "", "leadA");
        self::createLonelyTestUser("eel", "", "leadA");
    }

    /**
     * Creates an affiliate company.
     * @param string $name   is the name of the affiliate.
     * @param float  $cpa    is the cost per aquisition on this affiliate.
     * @param int    $days   is the number of days it takes before a user from this affiliate is considered aquired.
     * @param string $line1  is the first line of their address.
     * @param string $city   is the city that the affiliate company is based in.
     * @param string $source is the name of the affiliate company's campaign source if they have one.
     * @param string $lead   is the name of the affiliate company's lead source.
     * @return AffiliateCompany the new company.
     */
    private static function createTestAffiliate($name, $cpa, $days, $line1, $city, $source = '', $lead = '')
    {
        $affiliate = new AffiliateCompany();
        $address = new Address();
        $address->setLine1($line1);
        $address->setCity($city);
        $address->setPostcode("SW1A 0PW");
        $affiliate->setName($name);
        $affiliate->setAddress($address);
        $affiliate->setCPA($cpa);
        $affiliate->setDays($days);
        $affiliate->setCampaignSource($source);
        $affiliate->setLeadSource("scode");
        $affiliate->setLeadSourceDetails($lead);
        self::$dm->persist($affiliate);
        self::$dm->flush();
        return $affiliate;
    }

    /**
     * Creates a test user with a policy.
     * @param string $policyAge is the start date of the policy.
     * @param string $name      is the first and last names and email address of the user.
     * @param string $source    is the campaign source of the user.
     * @param string $lead      is the lead source of the user.
     * @return User the user that has now been created.
     */
    private static function createTestUser($policyAge, $name, $source = "", $lead = "")
    {
        $policy = self::createUserPolicy(true, new \DateTime($policyAge));
        $policy->getUser()->setEmail(self::generateEmailClass($name, "affiliateServiceTest"));
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setImei(self::generateRandomImei());
        $user = $policy->getUser();
        $attribution = new Attribution();
        $attribution->setCampaignSource($source);
        $user->setAttribution($attribution);
        $user->setLeadSource("scode");
        $user->setLeadSourceDetails($lead);
        $user->setFirstName($name);
        $user->setLastName($name);
        self::$dm->persist($policy);
        self::$dm->persist($user);
        self::$dm->flush();
        return $user;
    }

    /**
     * Creates a test user with no policy.
     * @param string $name  is the first and last names and email address of the user.
     * @param string $source is the campaign source of the user.
     * @param string $lead   is the lead source of the user.
     * @return User the user that has now been created.
     */
    private static function createLonelyTestUser($name, $source = "", $lead = "")
    {
        $user = new User();
        $user->setEmail(self::generateEmailClass($name, "affiliateServiceTest"));
        $user->setFirstName($name);
        $user->setLastName($name);
        $attribution = new Attribution();
        $attribution->setCampaignSource($source);
        $user->setLeadSource("scode");
        $user->setLeadSourceDetails($lead);
        $user->setAttribution($attribution);
        self::$dm->persist($user);
        self::$dm->flush();
        return $user;
    }
}
