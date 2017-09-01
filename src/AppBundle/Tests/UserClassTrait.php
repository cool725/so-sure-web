<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Address;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Payment\GocardlessPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Reward;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Classes\Salva;
use Doctrine\ODM\MongoDB\DocumentManager;

trait UserClassTrait
{
    use ImeiTrait;

    public static $JUDO_TEST_CARD_NUM = '4921 8100 0000 5462';
    public static $JUDO_TEST_CARD_LAST_FOUR = '5462';
    public static $JUDO_TEST_CARD_EXP = '12/20';
    public static $JUDO_TEST_CARD_PIN = '441';

    public static $JUDO_TEST_CARD2_NUM = '4976 0000 0000 3436';
    public static $JUDO_TEST_CARD2_LAST_FOUR = '3436';
    public static $JUDO_TEST_CARD2_EXP = '12/20';
    public static $JUDO_TEST_CARD2_PIN = '452';

    public static function generateEmail($name, $caller)
    {
        return sprintf('%s@%s.so-sure.net', $name, str_replace("\\", ".", get_class($caller)));
    }

    public static function createUserPolicy($init = false, $date = null, $setId = false)
    {
        $user = new User();
        $user->setFirstName('foo');
        $user->setLastName('bar');
        self::addAddress($user);
        if ($setId) {
            $user->setId(rand(1, 999999));
        }

        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);

        if ($init) {
            $policy->init($user, self::getLatestPolicyTerms(static::$dm));
            $phone = self::$phone;
            if (!$phone) {
                $phone = self::getRandomPhone(self::$dm);
            }
            $policy->setPhone($phone, $date);
            $policy->create(rand(1, 999999), 'TEST', $date, rand(1, 999999));
        }

        return $policy;
    }

    public static function createUser(
        $userManager,
        $email,
        $password,
        $phone = null,
        \Doctrine\ODM\MongoDB\DocumentManager $dm = null
    ) {
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setPlainPassword($password);
        $user->setEnabled(true);

        if ($phone) {
            $user->setMobileNumber(self::generateRandomMobile());
            $user->setFirstName('Foo');
            $user->setLastName('Bar');
            $user->setBirthday(new \DateTime('1980-01-01'));
        }

        $userManager->updateUser($user, true);
        if ($dm) {
            $dm->persist($user);
        }

        return $user;
    }

    public static function addAddress(User $user)
    {
        $address = new Address();
        $address->setType(Address::TYPE_BILLING);
        $address->setLine1('123 s road');
        $address->setCity('London');
        $address->setPostcode('BX11LT');
        $user->setBillingAddress($address);
    }

    public static function generateRandomMobile()
    {
        $mobile = sprintf('+4477009%05d', rand(1, 99999));
        if (strlen($mobile) != 13) {
            throw new \Exception('Random mobile is not the right length');
        }

        return $mobile;
    }
    
    public static function getRandomPhone(\Doctrine\ODM\MongoDB\DocumentManager $dm)
    {
        $phoneRepo = $dm->getRepository(Phone::class);
        $phones = $phoneRepo->findBy(['active' => true]);
        $phone = null;
        while ($phone == null) {
            $phone = $phones[rand(0, count($phones) - 1)];
            // Many tests rely on past dates, so ensure the date is ok for the past
            if (!$phone->getCurrentPhonePrice(new \DateTime('2016-01-01')) || $phone->getMake() == "ALL") {
                $phone = null;
            }
        }

        return $phone;
    }

    public static function transformMobile($mobile)
    {
        return str_replace("+44", "0", $mobile);
    }

    public static function initPolicy(
        User $user,
        \Doctrine\ODM\MongoDB\DocumentManager $dm,
        $phone = null,
        $date = null,
        $addPayment = false,
        $createPolicy = false,
        $monthly = true
    ) {
        self::addAddress($user);

        $policy = new SalvaPhonePolicy();
        $policy->setImei(self::generateRandomImei());
        $policy->init($user, self::getLatestPolicyTerms($dm));

        if ($phone) {
            $policy->setPhone($phone, $date);
        }

        if ($addPayment) {
            if (!$policy->getPhone()) {
                throw new \Exception('Missing phone for adding payment');
            }
            $newDate = null;
            if ($date) {
                $newDate = clone $date;
                $newDate->add(new \DateInterval('PT1S'));
            }
            if ($monthly) {
                $policy->setPremiumInstallments(12);
                self::addPayment(
                    $policy,
                    $policy->getPremium($date)->getMonthlyPremiumPrice(),
                    Salva::MONTHLY_TOTAL_COMMISSION,
                    null,
                    $newDate
                );
            } else {
                $policy->setPremiumInstallments(1);
                self::addPayment(
                    $policy,
                    $policy->getPremium($date)->getYearlyPremiumPrice(),
                    Salva::YEARLY_TOTAL_COMMISSION,
                    null,
                    $newDate
                );
            }
        }

        if ($createPolicy) {
            if (!$phone) {
                throw new \Exception('Attempted to create policy without setting a phone');
            }

            $policy->create(rand(1, 999999), 'TEST', $date, rand(1, 999999));
        }

        $dm->persist($policy);
        try {
            $dm->flush();
        } catch (\Exception $e) {
            $policy->createAddSCode(rand(1, 999999));
            $dm->flush();
        }
        return $policy;
    }

    public static function addJudoPayPayment($judopay, $policy, $date = null, $monthly = true)
    {
        if ($monthly) {
            $policy->setPremiumInstallments(12);
            $premium = $policy->getPremium()->getMonthlyPremiumPrice($date);
            $commission = Salva::MONTHLY_TOTAL_COMMISSION;
        } else {
            $policy->setPremiumInstallments(1);
            $premium = $policy->getPremium()->getYearlyPremiumPrice($date);
            $commission = Salva::YEARLY_TOTAL_COMMISSION;
        }

        $details = self::runJudoPayPayment($judopay, $policy->getUser(), $policy, $premium);
        $receiptId = $details['receiptId'];
        self::addPayment($policy, $premium, $commission, $receiptId);
    }

    public static function runJudoPayPayment($judopay, User $user, Policy $policy, $amount)
    {
        return $judopay->testPayDetails(
            $user,
            $policy->getId(),
            $amount,
            self::$JUDO_TEST_CARD_NUM,
            self::$JUDO_TEST_CARD_EXP,
            self::$JUDO_TEST_CARD_PIN
        );
    }

    public static function addPayment($policy, $amount, $commission, $receiptId = null, $date = null)
    {
        if (!$receiptId) {
            $receiptId = rand(1, 999999);
        }
        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt($receiptId);
        if ($date) {
            $payment->setDate($date);
        }
        $policy->addPayment($payment);

        return $payment;
    }

    public static function addSoSureStandardPayment($policy, $date = null, $refund = true, $monthly = true)
    {
        if ($monthly) {
            $premium = $policy->getPremium()->getMonthlyPremiumPrice($date);
            $commission = Salva::MONTHLY_TOTAL_COMMISSION;
        } else {
            $premium = $policy->getPremium()->getYearlyPremiumPrice($date);
            $commission = Salva::YEARLY_TOTAL_COMMISSION;
        }
        if (!$refund) {
            $premium = 0 - $premium;
            $commission = 0 - $commission;
        }
        self::addSoSurePayment($policy, $premium, $commission, $date);
    }
    
    public static function addSoSurePayment($policy, $amount, $commission, $date = null)
    {
        $payment = new SoSurePayment();
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        $payment->setSuccess(true);
        if ($date) {
            $payment->setDate($date);
        }
        $policy->addPayment($payment);
    }

    public static function connectPolicies($invitationService, $policyA, $policyB, $date)
    {
        $invitation = $invitationService->inviteByEmail($policyA, $policyB->getUser()->getEmail());
        $invitationService->accept($invitation, $policyB, $date);
    }

    public static function getLatestPolicyTerms(\Doctrine\ODM\MongoDB\DocumentManager $dm)
    {
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        return $latestTerms;
    }

    public static function authUser($cognito, $user)
    {
        list($identityId, $token) = $cognito->getCognitoIdToken($user, $cognito->getId());

        return $identityId;
    }

    public static function postRequest($client, $cognitoIdentityId, $url, $body)
    {
        return self::cognitoRequest($client, $cognitoIdentityId, $url, $body, "POST");
    }

    public static function putRequest($client, $cognitoIdentityId, $url, $body)
    {
        return self::cognitoRequest($client, $cognitoIdentityId, $url, $body, "PUT");
    }

    public static function deleteRequest($client, $cognitoIdentityId, $url, $body)
    {
        return self::cognitoRequest($client, $cognitoIdentityId, $url, $body, "DELETE");
    }

    public static function clearEmail($container)
    {
        $redis = $container->get('snc_redis.mailer');
        $redis->del('swiftmailer');
    }

    private static function cognitoRequest($client, $cognitoIdentityId, $url, $body, $method)
    {
        return $client->request(
            $method,
            $url,
            array(),
            array(),
            array(
                'CONTENT_TYPE' => 'application/json',
            ),
            json_encode(array(
                'body' => $body,
                'cognitoIdentityId' => $cognitoIdentityId,
                'sourceIp' => '62.253.24.189',
            ))
        );
    }

    protected static function createReward($email)
    {
        $user = static::createUser(
            static::$userManager,
            $email,
            'bar'
        );
        $reward = new Reward();
        $reward->setUser($user);
        static::$dm->persist($user);
        static::$dm->persist($reward);
        static::$dm->flush();

        return $reward;
    }

    protected function createLinkedConnections($policyA, $policyB, $valueA, $valueB)
    {
        $connectionA = new StandardConnection();
        $connectionA->setValue($valueA);
        if ($valueA > 10) {
            $connectionA->setValue(10);
            $connectionA->setPromoValue($valueA - 10);
        }
        $connectionA->setLinkedUser($policyB->getUser());
        $connectionA->setLinkedPolicy($policyB);
        $policyA->addConnection($connectionA);

        $connectionB = new StandardConnection();
        $connectionB->setValue($valueB);
        if ($valueB > 10) {
            $connectionB->setValue(10);
            $connectionB->setPromoValue($valueB - 10);
        }
        $connectionB->setLinkedUser($policyA->getUser());
        $connectionB->setLinkedPolicy($policyA);
        $policyB->addConnection($connectionB);

        return [$connectionA, $connectionB];
    }
}
