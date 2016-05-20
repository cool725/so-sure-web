<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Address;
use AppBundle\Document\PolicyKeyFacts;
use AppBundle\Document\PolicyTerms;

trait UserClassTrait
{
    public static function generateEmail($name, $caller)
    {
        return sprintf('%s@%s.so-sure.net', $name, str_replace("\\", ".", get_class($caller)));
    }

    public static function createUser($userManager, $email, $password, $phone = null)
    {
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

    public static function transformMobile($mobile)
    {
        return str_replace("+44", "0", $mobile);
    }

    public static function createPolicy(
        User $user,
        \Doctrine\ODM\MongoDB\DocumentManager $dm,
        $phone = null,
        $date = null
    ) {
        self::addAddress($user);

        $policy = new PhonePolicy();
        $policy->setImei(self::generateRandomImei());
        $policy->init($user, self::getLatestPolicyTerms($dm), self::getLatestPolicyKeyFacts($dm));
        $policy->create(rand(1, 999999), $date);
        if ($phone) {
            $policy->setPhone($phone);
        }

        $dm->persist($policy);
        $dm->flush();

        return $policy;
    }

    public static function getLatestPolicyTerms(\Doctrine\ODM\MongoDB\DocumentManager $dm)
    {
        $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
        $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);

        return $latestTerms;
    }

    public static function getLatestPolicyKeyFacts(\Doctrine\ODM\MongoDB\DocumentManager $dm)
    {
        $policyKeyFactsRepo = $dm->getRepository(PolicyKeyFacts::class);
        $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);

        return $latestKeyFacts;
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
