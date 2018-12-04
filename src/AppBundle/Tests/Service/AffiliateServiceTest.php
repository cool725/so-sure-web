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
    use \AppBundle\Document\DateTrait;

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
        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
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
        $date = new \DateTime();
        // test on nothing.
        $this->assertEmpty(self::$affiliateService->generate([], $date));
        // test on normal data.
        $data = $this->createState($date);
        $this->assertEquals(3, count(self::$affiliateService->generate([$data["affiliate"]], $date)));
        $this->assertEquals($data["affiliate"], $data["hat"]->getLatestPolicy()->getAffiliate());
        // This assertion just makes sure the affiliate saves confirmed policies right.
        $this->assertContains($data["hat"]->getLatestPolicy(), $data["affiliate"]->getConfirmedPolicies());
        //test on pre loved data.
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]], $date)));
        static::addDays($date, 21);
        $this->assertEquals(2, count(self::$affiliateService->generate([$data["affiliate"]], $date)));
        static::addDays($date, 365);
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]], $date)));
        // switch to ongoing.
        $data["affiliate"]->setChargeModel(AffiliateCompany::MODEL_ONGOING);
        $this->assertEquals(3, count(self::$affiliateService->generate([$data["affiliate"]], $date)));
        static::addDays($date, 20);
        $this->assertEquals(2, count(self::$affiliateService->generate([$data["affiliate"]], $date)));
        $this->assertEquals(0, count(self::$affiliateService->generate([$data["affiliate"]], $date)));
        // test on multiple affiliates at the same time.
        $affiliateB = $this->createState($date)["affiliate"];
        $affiliateC = $this->createState($date)["affiliate"];
        $this->assertEquals(6, count(self::$affiliateService->generate([$affiliateB, $affiliateC], $date)));
    }

    /**
     * Tests that the affiliate service generates ongoing charges when they should.
     */
    public function testOngoingCharges()
    {
        $date = new \DateTime();
        $data = $this->createState($date);
        $affiliate = $data["affiliate"];
        $users = [$data["bango"], $data["tango"], $data["borb"], $data["tonyAbbot"], $data["hat"]];
        // test for solitary policy no charges and show charges reference array works.
        $charges = [];
        self::$affiliateService->ongoingCharges($affiliate, $date, $charges);
        $this->assertEquals(3, count($charges));
        static::addDays($date, 30);
        self::$dm->flush();
        $this->assertEquals(2, count(self::$affiliateService->ongoingCharges($affiliate, $date)));

        // test for solitary policy charges a year ago.
        static::addDays($date, 365);
        self::$dm->flush();
        $this->assertEquals(3, count(self::$affiliateService->ongoingCharges($affiliate, $date)));
        static::addDays($date, 30);
        self::$dm->flush();
        $this->assertEquals(2, count(self::$affiliateService->ongoingCharges($affiliate, $date)));
        // test for renewal policy with previous having charges.
        foreach ($users as $user) {
            self::renewal($user, $date);
            self::createTestPolicy($user, $date);
        }
        self::$dm->flush();
        $this->assertEmpty(self::$affiliateService->ongoingCharges($affiliate, $date));
        static::addDays($date, 395);
        self::$dm->flush();
        $this->assertEquals(5, count(self::$affiliateService->ongoingCharges($affiliate, $date)));
        $this->assertEmpty(self::$affiliateService->ongoingCharges($affiliate, $date));
        // test for renewal policy with previous having no charges but user having charges.
        foreach ($users as $user) {
            self::renewal($user, $date);
            self::renewal($user, $date);
        }
        self::$dm->flush();
        $this->assertNull(self::$affiliateService->ongoingCharges($affiliate, $date));
        // test for multiple policies and no charges warning.
        foreach ($users as $user) {
            $charges = self::$chargeRepository->findBy(["user" => $user]);
            foreach ($charges as $charge) {
                self::$dm->remove($charge);
            }
            self::$dm->flush();
        }
        self::$dm->flush();
        $this->assertNull(self::$affiliateService->ongoingCharges($affiliate, $date));
    }

    /**
     * Tests generating one off charges.
     * @group time-sensitive
     */
    public function testOneOffCharges()
    {
        $date = new \DateTime();
        $data = $this->createState($date);
        $charges = [];
        self::$affiliateService->oneOffCharges($data["affiliate"], $date, $charges);
        $this->assertEquals(3, count($charges));
        $this->assertEquals(3.6, Charge::sumCost($charges));
        self::$dm->flush();
        $charges = self::$affiliateService->oneOffCharges($data["affiliate"], $date);
        $this->assertEquals(0, count($charges));
        $this->assertEquals(0, Charge::sumCost($charges));
        static::addDays($date, 20);
        $charges = [];
        self::$dm->flush();
        self::$affiliateService->oneOffCharges($data["affiliate"], $date, $charges);
        $this->assertEquals(2, count($charges));
        $this->assertEquals(2.4, Charge::sumCost($charges));
        static::addDays($date, 400);
        $charges = [];
        self::$dm->flush();
        self::$affiliateService->oneOffCharges($data["affiliate"], $date, $charges);
        $this->assertEquals(0, count($charges));
    }

    /**
     * Tests that getting new users with getMatchingUsers works.
     */
    public function testGetNewUsers()
    {
        $data = $this->createState(new \DateTime());
        $this->checkUsers(
            [$data["bango"], $data["tango"]],
            static::$affiliateService->getMatchingUsers($data["affiliate"], null, [User::AQUISITION_NEW])
        );
        $this->checkUsers(
            [],
            static::$affiliateService->getMatchingUsers(
                $data["affiliate"],
                new \DateTime("+30 days"),
                [User::AQUISITION_NEW]
            )
        );
        $this->checkUsers(
            [$data["bango"], $data["tango"], $data["hat"], $data["borb"], $data["tonyAbbot"]],
            static::$affiliateService->getMatchingUsers(
                $data["affiliate"],
                new \DateTime("30 days ago"),
                [User::AQUISITION_NEW]
            )
        );
    }

    /**
     * Tests that getting pending users works with get matching users.
     */
    public function testGetPendingUsers()
    {
        $data = $this->createState(new \DateTime());
        $this->checkUsers(
            [$data["hat"], $data["borb"], $data["tonyAbbot"]],
            static::$affiliateService->getMatchingUsers($data["affiliate"])
        );
        $this->checkUsers(
            [],
            static::$affiliateService->getMatchingUsers($data["affiliate"], new \DateTime("30 days ago"))
        );
    }

    /**
     * Tests that getting lost users works with get matching users.
     */
    public function testGetLostUsers()
    {
        $data = $this->createState(new \DateTime());
        $this->checkUsers(
            [$data["cancel"]],
            static::$affiliateService->getMatchingUsers(
                $data["affiliate"],
                new \DateTime(),
                [User::AQUISITION_LOST]
            )
        );
    }

    /**
     * Tests that getting potential users works with get matching users.
     */
    public function testGetPotentialUsers()
    {
        $data = $this->createState(new \DateTime());
        $this->checkUsers(
            [$data["camel"]],
            static::$affiliateService->getMatchingUsers(
                $data["affiliate"],
                new \DateTime(),
                [User::AQUISITION_POTENTIAL]
            )
        );
    }

    /**
     * Tests getting combinations of aquisition states with get matching users.
     */
    public function testGetMatchingUsers()
    {
        $data = $this->createState(new \DateTime());
        // New or Pending.
        $this->checkUsers(
            [$data["bango"], $data["tango"], $data["hat"], $data["borb"], $data["tonyAbbot"]],
            static::$affiliateService->getMatchingUsers(
                $data["affiliate"],
                new \DateTime("+30 days"),
                [User::AQUISITION_NEW, User::AQUISITION_PENDING]
            )
        );
        $this->checkUsers(
            [$data["bango"], $data["tango"], $data["hat"], $data["borb"], $data["tonyAbbot"]],
            static::$affiliateService->getMatchingUsers(
                $data["affiliate"],
                new \DateTime("30 days ago"),
                [User::AQUISITION_NEW, User::AQUISITION_PENDING]
            )
        );
        // Potential or Lost.
        $this->checkUsers(
            [$data["cancel"], $data["camel"]],
            static::$affiliateService->getMatchingUsers(
                $data["affiliate"],
                new \DateTime("+100 days"),
                [User::AQUISITION_LOST, User::AQUISITION_POTENTIAL]
            )
        );
    }

    /**
     * Every time you call this function it generates a bunch of unique but also predictable data and puts it into the
     * database.
     * @param \DateTime $date is the date to set everything as having been made at.
     * @return array an associative array with all the things held by the non unique part of their name.
     */
    private static function createState($date)
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
        $cancel =  self::createTestUser("P40D", "{$prefix}cancel", $date, "{$prefix}campaign");
        $policy = $cancel->getLatestPolicy();
        if ($policy) {
            $policy->setStatus(Policy::STATUS_CANCELLED);
        }
        return [
            "affiliate" => $affiliate,
            "bango" => self::createTestUser("P10D", "{$prefix}bango", $date, "{$prefix}campaign"),
            "tango" => self::createTestUser("P20D", "{$prefix}tango", $date, "", "{$prefix}lead"),
            "hat" => self::createTestUser("P30D", "{$prefix}hat", $date, "{$prefix}campaign"),
            "borb" => self::createTestUser("P40D", "{$prefix}borb", $date, "", "{$prefix}lead"),
            "tonyAbbot" => self::createTestUser("P50D", "{$prefix}tonyAbbot", $date, "{$prefix}campaign"),
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
     * @param string    $policyAge is the start date of the policy.
     * @param string    $name      is the first and last names and email address of the user.
     * @param \DateTime $date      is the date which the user was created policyAge days before.
     * @param string    $source    is the campaign source of the user.
     * @param string    $lead      is the lead source of the user.
     * @return User the user that has now been created.
     */
    private static function createTestUser($policyAge, $name, $date, $source = "", $lead = "")
    {
        $time = clone $date;
        $policy = self::createUserPolicy(true, $time->sub(new \DateInterval($policyAge)));
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
     * @param User      $user is the user to add the new policy to.
     * @param \DateTime $date is the date to set the creation of the policy to have occurred at.
     * @return Policy the new policy created.
     */
    private static function createTestPolicy($user, $date)
    {
        $policy = new PhonePolicy();
        $policy->setStatus(Policy::STATUS_ACTIVE);
        $policy->setUser($user);
        $policy->setStart(clone $date);
        $user->addPolicy($policy);
        self::$dm->persist($policy);
        self::$dm->flush();
        return $policy;
    }

    /**
     * Adds a new policy to the given user which is a renewal of their last policy.
     * @param User      $user is the user who must have an existing policy for this to work.
     * @param \DateTime $date is the date to set the renewal to have occured at.
     * @return Policy|null the policy that was just created.
     */
    private static function renewal($user, $date)
    {
        $policy = $user->getLatestPolicy();
        if (!$policy) {
            return null;
        }
        $policy->setStatus(Policy::STATUS_RENEWAL);
        $renewal = new PhonePolicy();
        $renewal->setUser($user);
        $renewal->setStart(clone $date);
        $renewal->setStatus(Policy::STATUS_ACTIVE);
        $policy->link($renewal);
        $user->addPolicy($renewal);
        self::$dm->persist($renewal);
        self::$dm->flush();
        return $renewal;
    }

    /**
     * Checks if the two arrays have the same elements.
     * @param array $expected contains the expected array.
     * @param array $got      contains the array you got.
     */
    protected function checkUsers($expected, $got)
    {
        foreach ($expected as $user) {
            $this->assertContains($user, $got);
        }
        foreach ($got as $user) {
            $this->assertContains($user, $expected);
        }
    }
}
