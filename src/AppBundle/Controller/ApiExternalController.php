<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\IpUtils;

use AppBundle\Document\Phone;
use AppBundle\Document\User;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

use Lcobucci\JWT\Builder;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\ValidationData;
use Lcobucci\JWT\Signer\Hmac\Sha256;

/**
 * @Route("/external")
 */
class ApiExternalController extends BaseController
{
    /**
     * @Route("/zendesk", name="api_external_zendesk")
     * @Method({"POST"})
     */
    public function unauthZendeskAction(Request $request)
    {
        try {
            $zendeskKey = $this->getParameter('zendesk_key');
            if ($request->get('zendesk_key') != $zendeskKey) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'Invalid key', 404);
            }
            $userToken = trim($request->request->get('user_token'));
            if (strlen($userToken) == 0) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $clientIp = $request->getClientIp();
            // https://support.zendesk.com/hc/en-us/articles/203660846-Zendesk-Public-IP-addresses
            $zendeskIps = [
                '174.137.46.0/24',
                '96.46.150.192/27',
                '96.46.156.0/24',
                '104.218.200.0/21',
                '185.12.80.0/22',
                '188.172.128.0/20',
                '192.161.144.0/20',
                '216.198.0.0/18'
            ];
            if ($request->get('debug')) {
                $zendeskIps[] = '127.0.0.1';
            }
            if (!IpUtils::checkIp($clientIp, $zendeskIps)) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Unauthorized', 401);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find($userToken);
            if (!$user || $user->isExpired() || $user->isLocked()) {
                // @codingStandardsIgnoreStart
                // https://support.zendesk.com/hc/en-us/articles/218278138-Building-a-dedicated-JWT-endpoint-for-the-Zendesk-SDK
                // @codingStandardsIgnoreEnd
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'Unauthorized', 401);
            }

            $token = (new Builder())->setIssuedAt(time());
            $token->setId(uniqid('', true));
            $token->set('email', $user->getEmailCanonical());
            $token->set('name', $user->getName());

            $secret = $this->getParameter('zendesk_jwt_secret');
            $jwt = (string) $token->sign(new Sha256(), $secret)->getToken();

            return new JsonResponse(['jwt' => $jwt]);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api zenddesk. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
