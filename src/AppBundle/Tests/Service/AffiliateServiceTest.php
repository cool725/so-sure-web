<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\Attribution;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Repository\ChargeRepository;
use AppBundle\Service\AffiliateService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Bridge\PhpUnit\ClockMock;
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

    /**
     * Tests that the AffiliateService::generate works as intended on a populated database and on no data.
     * This test has gotta run first, because it runs before the database has been messed with.
     * @group time-sensitive
     */
    public function testGenerate()
    {
        // test on nothing.
        $this->assertEmpty(self::$affiliateService->generate([]));

        // test on normal data.
        $data = $this->createState();
        $this->assertEquals(3, count(self::$affiliateService->generate([$data["affiliate"]])));

        //test on pre loved data.
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]])));
        sleep(60 * 60 * 24 * 30);
        $this->assertEquals(2, count(self::$affiliateService->generate([$data["affiliate"]])));
        sleep(60 * 60 * 24 * 365);
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]])));

        // switch to ongoing and create new policies in the future.
        $data["affiliate"]->setChargeModel(AffiliateCompany::MODEL_ONGOING);
        $this->assertEquals(5, count(self::$affiliateService->generate([$data["affiliate"]])));
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]])));
    }

    /**
     * Tests getting matching users from all status groups when all status groups are populated.
     * @param string $affiliate is the name of the affiliate to test on.
     * @param array  $status    is an array of the statuses we are searching for.
     * @param array  $expected  is an array of emails of the users who should be returned.
     * @dataProvider getMatchingUsersProvider .
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
                [User::AQUISITION_CONFIRMED],
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
     * Takes a list of charges and tells you the total cost of them
     * @param array $charges is the list of charges.
     * @return float total cost.
     */
    private static function sumCost($charges)
    {
        $sum = 0;
        foreach ($charges as $charge) {
            $sum += $charge->getAmount();
        }
        return $sum;
    }

    /**
     * Every time you call this function it generates a bunch of unique but also predictable data and puts it into the
     * database.
     * @return array an associative array with all the things held by the non unique part of their name.
     */
    private static function createState()
    {
        $prefix = uniqid();
        $affiliate = self::createTestAffiliate(
            "{$prefix}campaign",
            1,
            30,
            "line 1",
            "london",
            "{$prefix}campaign",
            "{$prefix}lead"
        );
        return [
            "affiliate" => $affiliate,
            "bango" => self::createTestUser("10 days ago", "{$prefix}bango", "{$prefix}campaign"),
            "tango" => self::createTestUser("20 days ago", "{$prefix}tango", "{$prefix}campaign"),
            "hat" => self::createTestUser("30 days ago", "{$prefix}hat", "{$prefix}campaign"),
            "borb" => self::createTestUser("40 days ago", "{$prefix}borb", "", "{$prefix}campaign"),
            "tonyAbbot" => self::createTestUser("50 days ago", "{$prefix}tonyAbbot", "", "{$prefix}campaign"),
            "prefix" => $prefix
        ];
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
        $affiliate->setChargeModel(AffiliateCompany::MODEL_ONE_OFF);
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

    private static function createTestPolicy($user, $date)
    {
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setUser($user);
        $policy->setStart($date);
        self::$dm->persist($policy);
        self::$dm->flush();
        return $policy;
    }
}
