<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Address;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\GocardlessPayment;
use AppBundle\Document\JudoPayment;
use AppBundle\Classes\Salva;
use Doctrine\ODM\MongoDB\DocumentManager;

trait UserClassTrait
{
    public static function generateEmail($name, $caller)
    {
        return sprintf('%s@%s.so-sure.net', $name, str_replace("\\", ".", get_class($caller)));
    }

    public static function createUserPolicy($init = false, $date = null)
    {
        $user = new User();
        self::addAddress($user);

        $policy = new SalvaPhonePolicy();
        $policy->setUser($user);

        if ($init) {
            $policy->init($user, self::getLatestPolicyTerms(static::$dm));
            $policy->setPhone(self::$phone, $date);
            $policy->create(rand(1, 999999), 'TEST', $date);
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

        // Most tests should be against non-prelaunch users
        $user->setCreated(new \DateTime('2017-01-01'));

        if ($phone) {
            $user->setMobileNumber(self::generateRandomMobile());
            $user->setFirstName('foo');
            $user->setLastName('bar');
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
        $phones = $phoneRepo->findAll();
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
            $payment = new JudoPayment();
            if ($date) {
                $newDate = clone $date;
                $newDate->add(new \DateInterval('PT1S'));
                $payment->setDate($newDate);
            }
            if ($monthly) {
                $policy->setPremiumInstallments(12);
                $payment->setAmount($policy->getPremium()->getMonthlyPremiumPrice());
                $payment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
            } else {
                $policy->setPremiumInstallments(1);
                $payment->setAmount($policy->getPremium()->getYearlyPremiumPrice());
                $payment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
            }
            $payment->setResult(JudoPayment::RESULT_SUCCESS);
            $payment->setReceipt(rand(1, 999999));
            $policy->addPayment($payment);
        }

        if ($createPolicy) {
            if (!$phone) {
                throw new \Exception('Attempted to create policy without setting a phone');
            }

            $policy->create(rand(1, 999999), 'TEST', $date);
        }

        $dm->persist($policy);
        $dm->flush();

        return $policy;
    }

    public static function addPayment($policy, $amount, $commission)
    {
        $payment = new JudoPayment();
        $payment->setAmount($amount);
        $payment->setTotalCommission($commission);
        $payment->setResult(JudoPayment::RESULT_SUCCESS);
        $payment->setReceipt(rand(1, 999999));
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

    public static function generateRandomImei()
    {
        $imei = [];
        for ($i = 0; $i < 14; $i++) {
            $imei[$i] = rand(0, 9);
        }

        $result = self::luhnGenerate(implode($imei));

        // strange bug - only sometimes will return 14 digits
        // TODO - fix this
        if (strlen($result) != 15) {
            return self::generateRandomImei();
        }

        return $result;
    }

    public static function luhnGenerate($number)
    {
        $stack = 0;
        $digits = str_split(strrev($number), 1);
        foreach ($digits as $key => $value) {
            if ($key % 2 === 0) {
                $value = array_sum(str_split($value * 2, 1));
            }
            $stack += $value;
        }
        $stack %= 10;
        if ($stack !== 0) {
            $stack -= 10;
        }
        return (int) (implode('', array_reverse($digits)) . abs($stack));
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
}
