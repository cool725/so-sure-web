<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\PhoneType;

use AppBundle\Document\Address;
use AppBundle\Document\Phone;
use AppBundle\Document\Sns;
use AppBundle\Document\User;
use AppBundle\Document\PolicyTerms;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/api/v1/unauth")
 */
class ApiUnauthController extends BaseController
{
    /**
     * @Route("/token", name="api_unauth_token")
     * @Method({"POST"})
     */
    public function unauthTokenAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['token', 'cognito_id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $identity = $this->get('app.user.cognitoidentity');
            $user = $identity->loadUserByUserToken($data['token']);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'Invalid token', 403);
            }

            // soft delete
            if ($user->isExpired()) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'User does not exist', 403);
            }

            if (!$user->isEnabled()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_RESET_PASSWORD,
                    'User account is temporarily disabled - reset password',
                    422
                );
            } elseif ($user->isLocked()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_SUSPENDED,
                    'User account is suspended - contact us',
                    422
                );
            }

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_TOKEN,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $cognitoIdentity = $this->get('app.cognito.identity');
            list($identityId, $token) = $cognitoIdentity->getCognitoIdToken($user, $data['cognito_id']);

            return new JsonResponse(['id' => $identityId, 'token' => $token]);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api unauthTokenAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
