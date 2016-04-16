<?php

namespace AppBundle\Tests;

use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;

trait UserClassTrait
{
    public static function generateEmail($name, $caller)
    {
        return sprintf('%s@%s.so-sure.net', $name, str_replace("\\", ".", get_class($caller)));
    }

    public static function createUser($userManager, $email, $password)
    {
        $user = $userManager->createUser();
        $user->setEmail($email);
        $user->setPlainPassword($password);
        $user->setEnabled(true);
        $userManager->updateUser($user, true);

        return $user;
    }
    
    public static function createPolicy(User $user, \Doctrine\ODM\MongoDB\DocumentManager $dm)
    {
        $policy = new PhonePolicy();
        $policy->setUser($user);
        $policy->setImei(self::generateRandomImei());

        $dm->persist($policy);
        $dm->flush();

        return $policy;
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

    public static function getIdentityString($cognitoIdentityId)
    {
        // @codingStandardsIgnoreStart
        $identity = sprintf('{cognitoIdentityPoolId=eu-west-1:e7a6cfd2-c60f-4a04-a7a0-79eec2150720, accountId=812402538357, cognitoIdentityId=%s, caller=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials, apiKey=null, sourceIp=62.253.24.189, cognitoAuthenticationType=unauthenticated, cognitoAuthenticationProvider=null, userArn=arn:aws:sts::812402538357:assumed-role/Cognito_sosureUnauth_Role/CognitoIdentityCredentials, userAgent=aws-sdk-iOS/2.3.5 iPhone-OS/9.2.1 en_GB, user=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials}"', $cognitoIdentityId);
        // @codingStandardsIgnoreEnd

        return $identity;
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
                'identity' => static::getIdentityString($cognitoIdentityId)
            ))
        );
    }
}
