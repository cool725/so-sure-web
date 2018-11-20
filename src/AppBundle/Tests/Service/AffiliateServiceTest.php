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
        sleep(60 * 60 * 24 * 21);
        $this->assertEquals(2, count(self::$affiliateService->generate([$data["affiliate"]])));
        sleep(60 * 60 * 24 * 365);
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]])));
        // switch to ongoing and create new policies in the future.
        $data["affiliate"]->setChargeModel(AffiliateCompany::MODEL_ONGOING);
        $this->assertEquals(5, count(self::$affiliateService->generate([$data["affiliate"]])));
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]])));
        // test on multiple affiliates at the same time.
        $affiliateB = $this->createState()["affiliate"];
        $affiliateC = $this->createState()["affiliate"];
        $this->assertEquals(6, count(self::$affiliateService->generate([$affiliateB, $affiliateC])));
    }

    /**
     * Tests that the affiliate service generates ongoing charges when they should.
     * @group time-sensitive
     */
    public function testOngoingCharges()
    {
        $data = $this->createState();
        $affiliate = $data["affiliate"];
        $users = [$data["bango"], $data["tango"], $data["borb"], $data["tonyAbbot"], $data["hat"]];
        // test for solitary policy no charges and show charges reference array works.
        $charges = [];
        self::$affiliateService->ongoingCharges($affiliate, $charges);
        $this->assertEquals(3, count($charges));
        sleep(60 * 60 * 24 * 30);
        $this->assertEquals(2, count(self::$affiliateService->ongoingCharges($affiliate)));
        // test for solitary policy charges a year ago.
        sleep(60 * 60 * 24 * 334);
        $this->assertEquals(3, count(self::$affiliateService->ongoingCharges($affiliate)));
        sleep(60 * 60 * 24 * 30);
        $this->assertEquals(2, count(self::$affiliateService->ongoingCharges($affiliate)));
        // test for renewal policy with previous having charges.
        foreach ($users as $user) {
            self::renewal($user);
            self::createTestPolicy($user);
        }
        $this->assertEmpty(self::$affiliateService->ongoingCharges($affiliate));
        sleep(60 * 60 * 24 * 395);
        $this->assertEquals(5, count(self::$affiliateService->ongoingCharges($affiliate)));
        $this->assertEmpty(self::$affiliateService->ongoingCharges($affiliate));
        // test for renewal policy with previous having no charges but user having charges.
        foreach ($users as $user) {
            self::renewal($user);
            self::renewal($user);
        }
        $this->assertNull(self::$affiliateService->ongoingCharges($affiliate));
        // test for multiple policies and no charges warning.
        foreach ($users as $user) {
            $charges = self::$chargeRepository->findBy(["user" => $user]);
            foreach ($charges as $charge) {
                self::$dm->remove($charge);
            }
            self::$dm->flush();
        }
        $this->assertNull(self::$affiliateService->ongoingCharges($affiliate));
    }

    /**
     * Tests generating one off charges.
     * @group time-sensitive
     */
    public function testOneOffCharges()
    {
        $data = $this->createState();
        $charges = [];
        self::$affiliateService->oneOffCharges($data["affiliate"], $charges);
        $this->assertEquals(3, count($charges));
        $this->assertEquals(3.6, self::sumCost($charges));
        $charges = self::$affiliateService->oneOffCharges($data["affiliate"]);
        $this->assertEquals(0, count($charges));
        $this->assertEquals(0, self::sumCost($charges));
        sleep(60 * 60 * 24 * 365);
        $charges = [];
        self::$affiliateService->oneOffCharges($data["affiliate"], $charges);
        $this->assertEquals(2, count($charges));
        $this->assertEquals(2.4, self::sumCost($charges));
    }

    /**
     * Tests getting matching users from all status groups when all status groups are populated.
     * @dataProvider getMatchingUsersProvider
     */
    public function testGetMatchingUsers($count, $states)
    {
        $data = $this->createState();
        $this->assertEquals(
            $count,
            count(self::$affiliateService->getMatchingUsers($data["affiliate"], $states))
        );
    }

    /**
     * Provides data for test get matching users.
     */
    public function getMatchingUsersProvider()
    {
        return [
            [3, [User::AQUISITION_NEW, User::AQUISITION_LOST]],
            [4, [User::AQUISITION_PENDING, User::AQUISITION_POTENTIAL]],
            [2, [User::AQUISITION_LOST, User::AQUISITION_POTENTIAL]],
            [5, [User::AQUISITION_NEW, User::AQUISITION_PENDING]],
            [1, [User::AQUISITION_LOST]],
            [1, [User::AQUISITION_POTENTIAL]],
            [2, [User::AQUISITION_NEW]],
            [3, [User::AQUISITION_PENDING]]
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
            1.2,
            30,
            "line 1",
            "london",
            "{$prefix}campaign",
            "{$prefix}lead"
        );
        $cancel =  self::createTestUser("P40D", "{$prefix}cancel", "{$prefix}campaign");
        $cancel->getLatestPolicy()->setStatus(Policy::STATUS_CANCELLED);
        return [
            "affiliate" => $affiliate,
            "bango" => self::createTestUser("P10D", "{$prefix}bango", "{$prefix}campaign"),
            "tango" => self::createTestUser("P20D", "{$prefix}tango", "", "{$prefix}lead"),
            "hat" => self::createTestUser("P30D", "{$prefix}hat", "{$prefix}campaign"),
            "borb" => self::createTestUser("P40D", "{$prefix}borb", "", "{$prefix}lead"),
            "tonyAbbot" => self::createTestUser("P50D", "{$prefix}tonyAbbot", "{$prefix}campaign"),
            "camel" => self::createLonelyTestUser("{$prefix}camel", "", "{$prefix}lead"),
            "cancel" => $cancel,
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
        $affiliate->setRenewalDays($days);
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
        $time = \DateTime::createFromFormat("U", time());
        $time->sub(new \DateInterval($policyAge));
        $policy = self::createUserPolicy(true, $time);
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
     * @param string $name   is the first and last names and email address of the user.
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

    /**
     * Creates a new policy for a given user and gives it a given start date.
     * @param User $user is the user to add the new policy to.
     * @return Policy the new policy created.
     */
    private static function createTestPolicy($user)
    {
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setUser($user);
        $policy->setStart(\DateTime::createFromFormat('U', time()));
        $user->addPolicy($policy);
        self::$dm->persist($policy);
        self::$dm->flush();
        return $policy;
    }

    /**
     * Adds a new policy to the given user which is a renewal of their last policy.
     * @param User $user is the user who must have an existing policy for this to work.
     * @return Policy the policy that was just created.
     */
    private static function renewal($user)
    {
        $policy = $user->getLatestPolicy();
        $policy->setStatus(Policy::STATUS_RENEWAL);
        $renewal = new PhonePolicy();
        $renewal->setUser($user);
        $renewal->setStart(\DateTime::createFromFormat("U", time()));
        $renewal->setStatus(Policy::STATUS_ACTIVE);
        $policy->link($renewal);
        $user->addPolicy($renewal);
        self::$dm->persist($renewal);
        self::$dm->flush();
        return $renewal;
    }
}
