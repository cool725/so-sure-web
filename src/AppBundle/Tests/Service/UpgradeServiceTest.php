<?php
namespace AppBundle\Tests\Service;

use AppBundle\Document\Phone;
use AppBundle\Document\BankAccount;
use AppBundle\Document\CustomerCompany;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\PaymentMethod\PaymentMethod;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Service\PaymentService;
use AppBundle\Service\PolicyService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use AppBundle\Document\User;
use AppBundle\Document\Address;
use AppBundle\Document\Cashback;
use AppBundle\Document\Claim;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PhonePremium;
use AppBundle\Document\Policy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\SCode;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Connection\RenewalConnection;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Invitation\SmsInvitation;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Service\InvitationService;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\ValidationException;
use AppBundle\Classes\Salva;
use AppBundle\Classes\SoSure;
use AppBundle\Service\UpgradeService;
use AppBundle\Document\LogEntry;
use AppBundle\Tests\Create;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\LogEntryRepository;
use Symfony\Component\Validator\Constraints\Date;

/**
 * Tests the upgrade service.
 */
class UpgradeServiceTest extends WebTestCase
{
    use \AppBundle\Tests\PhingKernelClassTrait;

    protected static $container;
    /** @var DocumentManager */
    protected static $dm;
    /** @var PolicyService $policyService */
    protected static $policyService;
    /** @var UpgradeService $upgradeService */
    protected static $upgradeService;

    /**
     * @inheritDoc
     */
    public static function setUpBeforeClass()
    {
        $kernel = static::createKernel();
        $kernel->boot();
        self::$container = $kernel->getContainer();
        /** @var DocumentManager */
        $dm = self::$container->get('doctrine_mongodb.odm.default_document_manager');
        self::$dm = $dm;
        /** @var PolicyService $policyService */
        $policyService = self::$container->get('app.policy');
        self::$policyService = $policyService;
        /** @var UpgradeService $upgradeService */
        $upgradeService = self::$container->get('app.upgrade');
        self::$upgradeService = $upgradeService;
    }

    /**
     * General test that policies can be upgraded.
     */
    public function testUpgradePlain()
    {
        // old premium * 74 / 366 + new premium * 292 / 366 - old montly premium * 3
        $user = Create::user();
        $policy = Create::helvetiaPhonePolicy($user, '2020-01-01', Policy::STATUS_ACTIVE, 12);
        $phone = Create::phone();
        Create::save(static::$dm, $user, $policy, $phone);
        $upgradeDate = new \DateTime('2020-03-15');
        $oldPremium = $policy->getPremium();
        $newPremium = $phone->getCurrentMonthlyPhonePrice()->createPremium(null, $upgradeDate);
        $payment = Create::standardPayment($policy, $policy->getStart(), true);
        static::$policyService->generateScheduledPayments($policy);
        $i = 0;
        foreach ($policy->getScheduledPayments() as $scheduledPayment) {
            if ($i < 2) {
                $i++;
            } else {
                break;
            }
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SUCCESS);
            $payment = Create::standardPayment($policy, $scheduledPayment->getScheduled(), true);
            static::$dm->persist($scheduledPayment);
            static::$dm->persist($payment);
        }
        static::$dm->flush();
        $futureMonthly = $policy->getPremiumUpgradeCostMonthly($newPremium, $upgradeDate);
        $futureMonthlyFinal = $policy->getUpgradeFinalMonthDifference($newPremium, $upgradeDate);
        $futureYearly = $policy->getPremiumUpgradeCostYearly($newPremium, $upgradeDate);
        static::$upgradeService->upgrade(
            $policy,
            $phone,
            ImeiTrait::generateRandomImei(),
            null,
            $upgradeDate,
            $newPremium
        );
        Create::refresh(static::$dm, $policy);
        $scheduledAmount = array_reduce($policy->getActiveScheduledPayments(), function ($carry, $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                return CurrencyTrait::toTwoDp($carry + $scheduledPayment->getAmount());
            }
            return $carry;
        }, 0);
        $oldMonthly = $oldPremium->getAdjustedStandardMonthlyPremiumPrice();
        $oldYearly = $oldPremium->getAdjustedYearlyPremiumPrice();
        $newYearly = $newPremium->getAdjustedYearlyPremiumPrice();
        $this->assertEquals(
            CurrencyTrait::toTwoDp($oldYearly * 74 / 366 + $newYearly * 292 / 366 - $oldMonthly * 3),
            $scheduledAmount
        );
        $this->assertEquals($policy->getUpgradedStandardMonthlyPrice(), $futureMonthly);
        $this->assertEquals($policy->getUpgradedFinalMonthlyPrice(), $futureMonthlyFinal);
        $this->assertEquals($policy->getUpgradedYearlyPrice(), $futureYearly);
    }

    /**
     * Tests that when you upgrade a yearly policy it takes the right casche. Sadly we can only test this for bacs
     * because on checkout it requires a legit token, so that has to be tested manually.
     */
    public function testUpgradeYearlyBacs()
    {
        $user = Create::user();
        /** @var HelvetiaPhonePolicy $policy */
        $policy = Create::bacsPolicy($user, '2020-04-01', Policy::STATUS_ACTIVE, 1);
        $payment = Create::standardPayment($policy, $policy->getStart(), true);
        $phone = Create::phone();
        $oldPremium = $policy->getPremium();
        Create::save(static::$dm, $user, $policy, $payment, $phone);
        $upgradeDate = new \DateTime('2021-01-19');
        static::$dm->flush();
        static::$upgradeService->upgrade(
            $policy,
            $phone,
            ImeiTrait::generateRandomImei(),
            null,
            $upgradeDate,
            $phone->getCurrentMonthlyPhonePrice()->createPremium(null, $upgradeDate)
        );
        Create::refresh(static::$dm, $policy);
        $scheduledAmount = array_reduce($policy->getActiveScheduledPayments(), function ($carry, $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                return $carry + $scheduledPayment->getAmount();
            }
            return $carry;
        }, 0);
        $this->assertEquals(CurrencyTrait::toTwoDp(
            $oldPremium->getAdjustedYearlyPremiumPrice() * 293 / 365 +
            $policy->getPremium()->getAdjustedYearlyPremiumPrice() * 72 / 365
        ), $policy->getPremiumPaid() + $scheduledAmount);
    }

    /**
     * Tests upgrading when a user has got a pending bacs payment.
     */
    public function testUpgradeBacsPending()
    {
        // old premium * 119 / 366 + new premium * 247 / 366 - old montly premium * 5
        $user = Create::user();
        /** @var HelvetiaPhonePolicy $policy */
        $policy = Create::bacsPolicy($user, '2020-01-01', Policy::STATUS_ACTIVE, 12);
        $phone = Create::phone();
        Create::save(static::$dm, $user, $policy, $phone);
        $upgradeDate = new \DateTime('2020-04-29');
        $oldPremium = $policy->getPremium();
        $newPremium = $phone->getCurrentMonthlyPhonePrice()->createPremium(null, $upgradeDate);
        $payment = Create::standardPayment($policy, $policy->getStart(), true);
        static::$policyService->generateScheduledPayments($policy);
        $i = 0;
        foreach ($policy->getScheduledPayments() as $scheduledPayment) {
            if ($i < 4) {
                $i++;
            } else {
                break;
            }
            $scheduledPayment->setStatus(ScheduledPayment::STATUS_SUCCESS);
            if ($i == 4) {
                $scheduledPayment->setStatus(ScheduledPayment::STATUS_PENDING);
                /** @var BacsPayment $payment */
                $payment = Create::standardPayment($policy, $scheduledPayment->getScheduled(), null);
                $payment->setStatus(BacsPayment::STATUS_PENDING);
                $payment->setSuccess(null);
            } else {
                $payment = Create::standardPayment($policy, $scheduledPayment->getScheduled(), true);
            }
            static::$dm->persist($scheduledPayment);
            static::$dm->persist($payment);
        }
        static::$dm->flush();
        static::$upgradeService->upgrade(
            $policy,
            $phone,
            ImeiTrait::generateRandomImei(),
            null,
            $upgradeDate,
            $newPremium
        );
        Create::refresh(static::$dm, $policy);
        $scheduledAmount = array_reduce($policy->getActiveScheduledPayments(), function ($carry, $scheduledPayment) {
            if ($scheduledPayment->getStatus() == ScheduledPayment::STATUS_SCHEDULED) {
                return CurrencyTrait::toTwoDp($carry + $scheduledPayment->getAmount());
            }
            return $carry;
        }, 0);
        $oldMonthly = $oldPremium->getAdjustedStandardMonthlyPremiumPrice();
        $oldYearly = $oldPremium->getAdjustedYearlyPremiumPrice();
        $newYearly = $newPremium->getAdjustedYearlyPremiumPrice();
        $this->assertEquals(
            CurrencyTrait::toTwoDp($oldYearly * 119 / 366 + $newYearly * 247 / 366 - $oldMonthly * 5),
            $scheduledAmount
        );

    }
}
