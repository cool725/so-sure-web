<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;

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
use AppBundle\Exception\ValidationException;

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
            $user = $identity->loadUserByUserToken($this->getDataString($data, 'token'));
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'Invalid token', 403);
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
            if (!$rateLimit->allowedByIp(
                RateLimitService::DEVICE_TYPE_TOKEN,
                $this->getCognitoIdentityIp($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $cognitoIdentity = $this->get('app.cognito.identity');
            list($identityId, $token) = $cognitoIdentity->getCognitoIdToken(
                $user,
                $this->getDataString($data, 'cognito_id'),
                $this->getDataString($data, 'userpool_token')
            );

            // Record mobile access
            $user->setLatestMobileIdentityLog($this->getIdentityLog($request));
            $this->getManager()->flush();

            return new JsonResponse(['id' => $identityId, 'token' => $token]);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api unauthTokenAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
