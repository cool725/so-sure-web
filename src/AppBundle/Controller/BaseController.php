<?php

namespace AppBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

abstract class BaseController extends Controller
{
    protected function getManager()
    {
        return $this->get('doctrine_mongodb')->getManager();
    }

    /**
     * @param Request $request
     *
     * @return array|null
     */
    protected function parseIdentity(Request $request)
    {
        $logger = $this->get('logger');
        $logger->warning(sprintf("Raw: %s", $request->getContent()));
        try {
            $data = json_decode($request->getContent(), true);

            // Sample:
            // {cognitoIdentityPoolId=eu-west-1:e80351d5-1068-462e-9702-3c9f642507f5, accountId=812402538357, cognitoIdentityId=eu-west-1:85376078-5f1f-43b8-8529-9021bb2096a4, caller=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials, apiKey=null, sourceIp=62.253.24.189, cognitoAuthenticationType=unauthenticated, cognitoAuthenticationProvider=null, userArn=arn:aws:sts::812402538357:assumed-role/Cognito_sosureUnauth_Role/CognitoIdentityCredentials, userAgent=aws-sdk-iOS/2.3.5 iPhone-OS/9.2.1 en_GB, user=AROAIOCRWVZM5HTY5DI3E:CognitoIdentityCredentials}
            $str = $data['identity'];
            $str = str_replace(',', '&', $str);
            $str = str_replace('{', '', $str);
            $str = str_replace('}', '', $str);
            $str = str_replace(' ', '', $str);
            parse_str($str, $identity);

            $logger->warning(sprintf("Data: %s", print_r($data, true)));
            $logger->warning(sprintf("Identity: %s", print_r($identity, true)));

            return $identity;
        } catch(\Exception $e) {
            $logger->error(sprintf('Error processing identity: %s', $e->getMessage()));
        }

        return null;
    }
}
