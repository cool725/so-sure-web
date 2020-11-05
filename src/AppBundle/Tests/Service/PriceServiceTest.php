<?php

namespace AppBundle\Tests\Service;

use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\User;
use AppBundle\Exception\IncorrectPriceException;
use AppBundle\Service\PriceService;
use AppBundle\Tests\Create;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional-nonet
 * @group fixed
 */
class PriceServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;
    use \AppBundle\Tests\UserClassTrait;
    use \AppBundle\Document\DateTrait;

    protected static $container;
    /** @var PriceService */
    protected static $priceService;
    protected static $dm;

    /**
     * @InheritDoc
     */
    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();
        self::$userManager = self::$container->get('fos_user.user_manager');
        /** @var PriceService priceService */
        $priceService = self::$container->get('app.price');
        self::$priceService = $priceService;
        /** @var DocumentManager $dm */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
    }

    /**
     * Makes sure the price service can get the applicable price with it's source.
     */
    public function testUserPhonePriceStreams()
    {
        $data = $this->userData();
        $this->assertEquals(
            ["price" => $data["priceA"], "source" => $data["phone"]],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
                PhonePrice::STREAM_ANY,
                new \DateTime('2019-02-09')
            )
        );
        $this->assertEquals(
            ["price" => $data["priceB"], "source" => $data["phone"]],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
                PhonePrice::STREAM_ANY,
                new \DateTime('2019-03-09')
            )
        );
        $this->assertEquals(
            ["price" => $data["priceA"], "source" => $data["phone"]],
            self::$priceService->userPhonePriceSource(
                $data["user"],
                $data["phone"],
                PhonePrice::STREAM_MONTHLY,
                new \DateTime('2019-03-09')
            )
        );
    }

    /**
     * Tests determine premium to make sure it works when there are valid inputs to it.
     */
    public function testPhonePolicyDeterminePremium()
    {
        $data = $this->userData();
        // Yearly on price A befoe price b starts.
        self::$priceService->phonePolicyDeterminePremium(
            $data["policy"],
            $data["priceA"]->getYearlyPremiumPrice(),
            new \DateTime("2019-02-21")
        );
        $this->assertEquals(1, $data["policy"]->getPremiumInstallments());
        $this->assertEquals($data["priceA"]->getYearlyPremiumPrice(), $data["policy"]->getYearlyPremiumPrice());
        // Monthly on price A.
        self::$priceService->phonePolicyDeterminePremium(
            $data["policy"],
            $data["priceA"]->getMonthlyPremiumPrice(),
            new \DateTime("2019-02-06 03:00")
        );
        $this->assertEquals(12, $data["policy"]->getPremiumInstallments());
        $this->assertEquals(
            $data["priceA"]->getMonthlyPremiumPrice(),
            $data["policy"]->getPremium()->getMonthlyPremiumPrice()
        );
        // Yearly on price B.
        self::$priceService->phonePolicyDeterminePremium(
            $data["policy"],
            $data["priceB"]->getYearlyPremiumPrice(),
            new \DateTime("2019-04-01")
        );
        $this->assertEquals(1, $data["policy"]->getPremiumInstallments());
        $this->assertEquals($data["priceB"]->getYearlyPremiumPrice(), $data["policy"]->getYearlyPremiumPrice());
    }

    /**
     * Tests determine premium to make sure that when invalid inputs are sent to it it throws the right kind of
     * exception. As ugly as all these try catches are, they are necessary because otherwise it can only test for one
     * exception.
     */
    public function testPhonePolicyDeterminePremiumInvalid()
    {
        $data = $this->userData();
        $exceptions = 0;
        // Random numbers.
        try {
            self::$priceService->phonePolicyDeterminePremium($data["policy"], 3, new \DateTime("2019-03-19"));
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        try {
            self::$priceService->phonePolicyDeterminePremium($data["policy"], 71.23, new \DateTime("2019-06-11"));
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        // Yearly on A after B.
        try {
            self::$priceService->phonePolicyDeterminePremium(
                $data["policy"],
                $data["priceA"]->getYearlyPremiumPrice(),
                new \DateTime("2019-06-11")
            );
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        // Monthly on B
        try {
            self::$priceService->phonePolicyDeterminePremium(
                $data["policy"],
                $data["priceB"]->getMonthlyPremiumPrice(),
                new \DateTime("2019-06-11")
            );
        } catch (IncorrectPriceException $e) {
            $exceptions++;
        }
        $this->assertEquals(4, $exceptions);
    }

    /**
     * Tests that it is following the renewal pricing logic which is as this table states:
     * nClaims new price lower                new price higher
     * 0       higher of old - 10% or higher  old price
     * 1       old price                      old price
     * more    old price                      new price
     */
    public function testSetPhonePolicyRenewalPremium()
    {
        $this->renewalPolicyTester(7, 5, 12, 0, 6.3);
        $this->renewalPolicyTester(8, 6, 12, 1, 8);
        $this->renewalPolicyTester(9, 4, 12, 2, 9);
        $this->renewalPolicyTester(10, 5, 12, 6, 10);
        $this->renewalPolicyTester(3, 5, 12, 0, 3);
        $this->renewalPolicyTester(2, 6, 12, 1, 2);
        $this->renewalPolicyTester(4, 8, 12, 2, 8);
        $this->renewalPolicyTester(3, 6, 12, 6, 6);
    }

    /**
     * Creates a renewal policy and then tests what price it gets.
     * @param number $oldPrice     is the old phone gwp on the previous policy.
     * @param number $newPrice     is the phone's gwp at time of purchase.
     * @param int    $installments is the number of payment installments the policies have.
     * @param int    $claims       is the number of claims the previous policy has.
     * @param number $expectedGwp  is the gwp the new policy should end up with.
     */
    private function renewalPolicyTester(
        $oldPrice,
        $newPrice,
        $installments,
        $claims,
        $expectedGwp,
        $subvariant = null
    ) {
        $startDate = new \DateTime();
        $oldStartDate = (clone $startDate)->sub(new \DateInterval('P1Y'));
        $newPriceStart = (clone $startDate)->sub(new \DateInterval('P3M'));
        $phone = Create::phone();
        $phone->addPhonePrice(Create::phonePrice(
            $oldStartDate,
            PhonePrice::installmentsStream($installments),
            $subvariant,
            $oldPrice
        ));
        $phone->addPhonePrice(Create::phonePrice(
            $newPriceStart,
            PhonePrice::installmentsStream($installments),
            $subvariant,
            $newPrice
        ));
        $user = Create::user();
        Create::save(self::$dm, $phone, $user);
        $oldPolicy = Create::policy($user, $oldStartDate, Policy::STATUS_EXPIRED, $installments, $phone, $subvariant);
        Create::save(self::$dm, $oldPolicy);
        for ($i = 0; $i < $claims; $i++) {
            $claim = Create::claim($oldPolicy, Claim::TYPE_DAMAGE, $newPriceStart, Claim::STATUS_APPROVED);
            Create::save(self::$dm, $claim);
        }
        $policy = Create::policy($user, $startDate, Policy::STATUS_ACTIVE, $installments, $phone, $subvariant);
        $oldPolicy->link($policy);
        self::$priceService->setPhonePolicyRenewalPremium($policy, 0, $startDate);
        $this->assertEquals($expectedGwp, $policy->getPremium()->getGwp());
    }

    /**
     * Creates data used for each test.
     */
    private function userData()
    {
        $user = new User();
        $user->setEmail(uniqid()."@yandex.com");
        $user->setFirstName(uniqid());
        $user->setLastName(uniqid());
        $phone = new Phone();
        $priceA = new PhonePrice();
        $priceB = new PhonePrice();
        $priceA->setValidFrom(new \DateTime('2019-02-06'));
        $priceA->setStream(PhonePrice::STREAM_ALL);
        $priceA->setGwp(20);
        $priceB->setValidFrom(new \DateTime('2019-03-02'));
        $priceB->setStream(PhonePrice::STREAM_YEARLY);
        $priceB->setGwp(1.23);
        $phone->addPhonePrice($priceA);
        $phone->addPhonePrice($priceB);
        $policy = new HelvetiaPhonePolicy();
        $policy->setPhone($phone);
        $user->addPolicy($policy);
        self::$dm->persist($user);
        self::$dm->persist($phone);
        self::$dm->persist($policy);
        self::$dm->flush();
        return [
            "user" => $user,
            "phone" => $phone,
            "policy" => $policy,
            "priceA" => $priceA,
            "priceB" => $priceB
        ];
    }
}
