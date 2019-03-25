<?php

namespace AppBundle\Controller;

use AppBundle\Document\ValidatorTrait;
use AppBundle\Exception\InvalidEmailException;
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
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Feature;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\ArrayToApiArrayTrait;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use AppBundle\Exception\ValidationException;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use GuzzleHttp\Client;

/**
 * @Route("/api/v1")
 */
class ApiController extends BaseController
{
    use ArrayToApiArrayTrait;
    use ValidatorTrait;

    /**
     * @Route("/login", name="api_login")
     * @Method({"POST"})
     */
    public function loginAction(Request $request)
    {
        try {
            if ($this->getRequestBool($request, 'debug')) {
                throw new \Exception('Debug Exception Test');
            }

            $data = json_decode($request->getContent(), true)['body'];

            $emailUserData = null;
            $facebookUserData = null;
            $googleUserData = null;
            $oauthEchoUserData = null;
            $accountKitUserData = null;
            if (isset($data['email_user'])) {
                $emailUserData = $data['email_user'];
                if (!$this->validateFields($emailUserData, ['email', 'password'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } elseif (isset($data['facebook_user'])) {
                $facebookUserData = $data['facebook_user'];
                if (!$this->validateFields($facebookUserData, ['facebook_id', 'facebook_access_token'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } elseif (isset($data['google_user'])) {
                $googleUserData = $data['google_user'];
                if (!$this->validateFields($googleUserData, ['google_id', 'google_access_token'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } elseif (isset($data['oauth_echo_user'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UPGRADE_APP, 'Digits is not available', 422);
            } elseif (isset($data['account_kit_user'])) {
                $accountKitUserData = $data['account_kit_user'];
                if (!$this->validateFields($accountKitUserData, ['authorization_code'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = null;
            if ($emailUserData) {
                $email = mb_strtolower($this->getDataString($emailUserData, 'email'));
                $user = $repo->findOneBy(['emailCanonical' => $email]);
            } elseif ($facebookUserData) {
                $facebookId = $this->getDataString($facebookUserData, 'facebook_id');
                $user = $repo->findOneBy(['facebookId' => $facebookId]);
            } elseif ($googleUserData) {
                $googleId = $this->getDataString($googleUserData, 'google_id');
                $user = $repo->findOneBy(['googleId' => $googleId]);
            } elseif ($accountKitUserData) {
                $authorizationCode = $this->getDataString($accountKitUserData, 'authorization_code');

                $facebook = $this->get('app.facebook');
                $mobileNumber = $facebook->getAccountKitMobileNumber($authorizationCode);
                $user = $repo->findOneBy(['mobileNumber' => $mobileNumber]);
            }

            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'User not found', 403);
            }

            /** @var RateLimitService $rateLimit */
            $rateLimit = $this->get('app.ratelimit');
            // Success logins should clear the rate limit (further below)
            if (!$rateLimit->allowedByUser($user)) {
                // If rate limiting occurs for a user, then the user should be locked
                $user->setLocked(true);
                $dm->flush();
            }

            /* Apple appears to have problems logging in
             * so may want to re-enable for releases
            if ($user->getEmailCanonical() == "apple@so-sure.com") {
                list($identityId, $token) = $this->getCognitoIdToken($user, $request);
                $intercomHash = $this->get('app.intercom')->getApiUserHash($user);

                return new JsonResponse($user->toApiArray($intercomHash, $identityId, $token));
            }
            */

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

            // In order for the locked, logic to occur, must be before rate limiting
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_LOGIN,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            if ($emailUserData) {
                $encoderService = $this->get('security.encoder_factory');
                $encoder = $encoderService->getEncoder($user);
                if (!$encoder->isPasswordValid(
                    $user->getPassword(),
                    $this->getDataString($emailUserData, 'password'),
                    $user->getSalt()
                )) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid password', 403);
                }
            } elseif ($facebookUserData) {
                $facebookService = $this->get('app.facebook');
                if (!$facebookService->validateToken(
                    $user,
                    $this->getDataString($facebookUserData, 'facebook_access_token')
                )) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid token', 403);
                }
                // TODO: Here we would either add a session linking a facebook_id to a cognito session
                // or on the client side, the cognito id returned, could be linked to the login
            } elseif ($googleUserData) {
                $googleService = $this->get('app.google');
                if (!$googleService->validateToken(
                    $user,
                    $this->getDataString($googleUserData, 'google_access_token')
                )) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid token', 403);
                }
            }
            list($identityId, $token) = $this->getCognitoIdToken($user, $request);

            // User has successfully logged in, so clear the rate limit
            $rateLimit->clearByUser($user);

            $intercomHash = $this->get('app.intercom')->getApiUserHash($user);

            if (!$user->getFirstLoginInApp()) {
                $user->setFirstLoginInApp(new \DateTime());
                $dm->flush();

                // User's first login in app is a KPI in the sms link experiment.
                // If they're not participating nothing will happen.
                $sixpack = $this->get('app.sixpack');
                $sixpack->convertByClientId(
                    $user->getId(),
                    $sixpack::EXPERIMENT_APP_LINK_SMS,
                    $sixpack::KPI_FIRST_LOGIN_APP
                );
            }

            $response = $user->toApiArray($intercomHash, $identityId, $token);
            $this->get('logger')->info(sprintf('loginAction Resp %s', json_encode($response)));

            return new JsonResponse($response);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api loginAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/ping", name="api_ping")
     * @Method({"GET", "POST"})
     */
    public function pingAction()
    {
        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }

    /**
     * This is purely for testing; it does not have an api gateway route defined
     *
     * @Route("/test/replay", name="api_test_replay")
     * @Method({"POST"})
     */
    public function testReplayAction(Request $request)
    {
        $rateLimit = $this->get('app.ratelimit');
        if (!$rateLimit->replay($this->getCognitoIdentityId($request), $request)) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Replay', 500);
        }

        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }

    /**
     * @Route("/quote", name="api_quote")
     * @Method({"GET"})
     */
    public function quoteAction(Request $request)
    {
        try {
            $make = $this->getRequestString($request, 'make');
            $device = $this->getRequestString($request, 'device');
            $memory = (float) $this->getRequestString($request, 'memory');
            $rooted = $this->getRequestBool($request, 'rooted');
            $quoteService = $this->get('app.quote');
            $quoteData = $quoteService->getQuotes($make, $device, $memory, $rooted);
            $phones = $quoteData['phones'];
            $deviceFound = $quoteData['deviceFound'];

            $stats = $this->get('app.stats');
            $cognitoId = $this->getCognitoIdentityId($request);
            $stats->quote(
                $cognitoId,
                \DateTime::createFromFormat('U', time()),
                $device,
                $memory,
                $deviceFound,
                $rooted
            );

            if (!$phones || !$deviceFound) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_PHONE_UNKNOWN, 'Unknown phone', 422);
            }

            $quotes = [];
            foreach ($phones as $phone) {
                /** @var Phone $phone */
                if ($quote = $phone->asQuoteApiArray()) {
                    $quotes[] = $quote;
                }
            }

            if ($rooted) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_UNABLE_TO_INSURE, 'Unable to insure', 422);
            }
            if (!$quoteData['anyActive']) {
                if ($quoteData['anyRetired']) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_EXPIRED, 'Phone(s) are retired', 422);
                } elseif (!$quoteData['anyPricing']) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_COMING_SOON, 'Coming soon', 422);
                }
            }

            $response = [
                'quotes' => $quotes,
                'device_found' => $deviceFound,
            ];

            if ($this->getRequestBool($request, 'debug')) {
                $response['memory_found'] = $quoteData['memoryFound'];
                $response['rooted'] = $rooted;
                $response['different_make'] = $quoteData['differentMake'];
            }

            return new JsonResponse($response);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api quoteAction.', ['exception' => $e]);
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/referral", name="api_referral")
     * @Method({"GET"})
     */
    public function referralAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['email'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $email = mb_strtolower($this->getRequestString($request, 'email'));
            $user = $repo->findOneBy(['emailCanonical' => $email]);
            if (!$user) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_ABSENT,
                    'Unable to locate referral for user',
                    422
                );
            }

            $launchUser = $this->get('app.user.launch');
            //$url = $launchUser->getLink($user->getId());
            $url = $launchUser->getShortLink($user->getId());

            return new JsonResponse(['url' => $url]);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api referralAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/referral", name="api_add_referral")
     * @Method({"POST"})
     */
    public function referralAddAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['email', 'referral_code'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $email = mb_strtolower($this->getDataString($data, 'email'));
            $referralCode = $this->getDataString($data, 'referral_code');

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->findOneBy(['emailCanonical' => $email]);
            $referralUser = $repo->find($referralCode);
            if (!$user || !$referralUser || $user->getReferred()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_ABSENT,
                    'Unable to locate user or referral code',
                    422
                );
            }
            $user->setReferred($referralUser);
            $this->validateObject($user);
            $dm->flush();

            $launchUser = $this->get('app.user.launch');
            $url = $launchUser->getShortLink($user->getId());

            return new JsonResponse(['url' => $url]);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api referralAddAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/reset", name="api_reset")
     * @Method({"POST"})
     */
    public function resetAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['email'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }
            $email = mb_strtolower($this->getDataString($data, 'email'));

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->findOneBy(['emailCanonical' => $email]);
            if (!$user) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_ABSENT,
                    'Unable to locate user',
                    422
                );
            }

            /** @var RateLimitService $rateLimit */
            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_RESET,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            if ($user->isLocked()) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_SUSPENDED,
                    'User account is suspended - contact us',
                    422
                );
            }

            if (null === $user->getConfirmationToken()) {
                /** @var \FOS\UserBundle\Util\TokenGeneratorInterface $tokenGenerator */
                $tokenGenerator = $this->get('fos_user.util.token_generator');
                $user->setConfirmationToken($tokenGenerator->generateToken());
            }

            $this->container->get('fos_user.mailer')->sendResettingEmailMessage($user);
            $user->setPasswordRequestedAt(\DateTime::createFromFormat('U', time()));
            $this->get('fos_user.user_manager')->updateUser($user);

            // If resetting password, clear the login rate limit
            // Rate limit on reset should prevent too many login/reset/login attempts
            $rateLimit->clearByDevice(
                RateLimitService::DEVICE_TYPE_LOGIN,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            );

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Reset password email sent', 200);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api resetAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/terms", name="api_get_policy_terms")
     * @Route("/policy/v2/terms", name="api_get_policy_terms2")
     * @Method({"GET"})
     */
    public function getLatestTermsAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['maxPotValue'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $latestTerms = $this->getLatestPolicyTerms();
            if (!$latestTerms) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find terms',
                    404
                );
            }
            if ($request->get('_route') == 'api_get_policy_terms') {
                $termsRoute = 'latest_policy_terms';
            } else {
                $termsRoute = 'latest_policy_terms2';
            }
            $policyTermsRoute = $this->get('router')->generate(
                $termsRoute,
                [
                    'policy_key' => $this->getParameter('policy_key'),
                    'maxPotValue' => $this->getRequestString($request, 'maxPotValue'),
                ],
                false
            );
            $policyTermsUrl = sprintf("%s%s", $this->getParameter('web_base_url'), $policyTermsRoute);

            return new JsonResponse($latestTerms->toApiArray($policyTermsUrl));
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getLatestPolicyTerms.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/scode/{code}", name="api_get_scode")
     * @Method({"GET"})
     */
    public function getSCodeAction($code)
    {
        try {
            $dm = $this->getManager();
            $scodeRepo = $dm->getRepository(SCode::class);
            $scode = $scodeRepo->findOneBy(['code' => $code]);
            if (!$scode || !$scode->isActive()) {
                throw new NotFoundHttpException();
            }

            return new JsonResponse($scode->toApiArray());
        } catch (AccessDeniedException $ade) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied', 403);
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find policy/code',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api getSCodeAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/token", name="api_token")
     * @Method({"POST"})
     */
    public function tokenAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['token'])) {
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
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_TOKEN,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            list($identityId, $token) = $this->getCognitoIdToken($user, $request);

            return new JsonResponse(['id' => $identityId, 'token' => $token]);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api tokenAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/user", name="api_user")
     * @Method({"POST"})
     */
    public function userAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['email'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $facebookId = $this->getDataString($data, 'facebook_id');
            $googleId = $this->getDataString($data, 'google_id');
            $mobileNumber = $this->getDataString($data, 'mobile_number');
            $userExists = $repo->existsUser(
                $this->getDataString($data, 'email'),
                $facebookId,
                $mobileNumber,
                $googleId
            );
            if ($userExists) {
                // Special case for prelaunch users - allow them to 'create' an account without
                // being recreated in account in the db.  This is only allowed once per user
                // and is only because the prelaunch app didn't do anything other than record email address
                $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($this->getDataString($data, 'email'))]);
                if ($user && $user->isPreLaunch() && !$user->getLastLogin() && count($user->getPolicies()) == 0) {
                    $user->resetToken();
                    $user->setLastLogin(\DateTime::createFromFormat('U', time()));
                } else {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_USER_EXISTS,
                        'User already exists',
                        422
                    );
                }
            } else {
                if ($facebookId !== null) {
                    $facebookService = $this->get('app.facebook');
                    if (!$facebookService->validateTokenId(
                        $facebookId,
                        $this->getDataString($data, 'facebook_access_token')
                    )) {
                        return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid token', 403);
                    }
                } elseif ($googleId !== null) {
                    $googleService = $this->get('app.google');
                    if (!$googleService->validateTokenId(
                        $googleId,
                        $this->getDataString($data, 'google_access_token')
                    )) {
                        return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid token', 403);
                    }
                }

                $userManager = $this->get('fos_user.user_manager');
                $user = $userManager->createUser();
                $user->setFirstLoginInApp(new \DateTime());
            }

            $user->setEnabled(true);
            $user->setEmail($this->getDataString($data, 'email'));
            $user->setFirstName(
                isset($data['first_name']) ?
                ucfirst(mb_strtolower($this->conformAlphanumeric($this->getDataString($data, 'first_name'), 50))) :
                null
            );
            $user->setLastName(
                isset($data['last_name']) ?
                ucfirst(mb_strtolower($this->conformAlphanumeric($this->getDataString($data, 'last_name'), 50))) :
                null
            );
            $user->setFacebookId($this->getDataString($data, 'facebook_id'));
            $user->setFacebookAccessToken($this->getDataString($data, 'facebook_access_token'));
            $user->setGoogleId($this->getDataString($data, 'google_id'));
            $user->setGoogleAccessToken($this->getDataString($data, 'google_access_token'));
            $user->setSnsEndpoint($this->getDataString($data, 'sns_endpoint'));
            $user->setMobileNumber($mobileNumber);
            $user->setReferer($this->conformAlphanumericSpaceDot($this->getDataString($data, 'referer'), 500));
            $birthday = $this->validateBirthday($data);
            if ($birthday instanceof Response) {
                return $birthday;
            }
            $user->setBirthday($birthday);
            $user->setIdentityLog($this->getIdentityLog($request));
            if ($this->isDataStringPresent($data, 'scode')) {
                $scodeRepo = $dm->getRepository(SCode::class);
                $scode = $scodeRepo->findOneBy(['code' => $this->getDataString($data, 'scode')]);
                if (!$scode || !$scode->isActive()) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, 'SCode missing', 404);
                }
                $scode->addAcceptor($user);
            }
            try {
                $this->validateObject($user);
            } catch (InvalidEmailException $ex) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid email format', 422);
            }

            $launchUser = $this->get('app.user.launch');
            $addedUser = $launchUser->addUser($user);

            // Should never occur as we're checking before, but just in case
            if (!$addedUser['new']) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'User already exists', 422);
            }

            $identityId = null;
            $token = null;
            // Important! This should only run if the user is a new user - otherwise, could impersonate an existing user
            if ($addedUser['new'] && $identityId) {
                list($identityId, $token) = $this->getCognitoIdToken($addedUser['user'], $request);
            }

            if ($user->getSnsEndpoint() != null) {
                $this->snsSubscribe('registered', $user->getSnsEndpoint());
                $this->snsUnsubscribe('unregistered', $user->getSnsEndpoint());
            }
            $intercomHash = $this->get('app.intercom')->getApiUserHash($user);
            $this->get('app.mixpanel')->queueAttribution($user);

            return new JsonResponse($user->toApiArray($intercomHash, $identityId, $token));
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api userAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/version", name="api_version")
     * @Route("/version/v2", name="api_version2")
     * @Method({"GET"})
     */
    public function versionAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['platform', 'version'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $platform = $this->getRequestString($request, 'platform');
            $version = $this->getRequestString($request, 'version');

            // TODO: Add missing param check for versions above certain number to require device, memory & uuid fields
            $uuid = $this->getRequestString($request, 'uuid');
            $device = $this->getRequestString($request, 'device');
            $memory = $this->getRequestString($request, 'memory');

            $additional = [
                        'platform' => $platform,
                        'version' => $version,
                        'uuid' => $uuid,
                        'device' => $device,
                        'memory' => $memory,
            ];

            $redis = $this->get('snc_redis.default');
            $cognitoId = $this->getCognitoIdentityId($request);
            $this->setAdditionalIdentityLog($cognitoId, $additional);

            // we could already be logged in
            $user = $this->getUser();
            if ($user) {
                $user->setLatestMobileIdentityLog($this->getIdentityLog($request));
                $this->getManager()->flush();
            }

            if ($redis->exists('ERROR_NOT_YET_REGULATED') == 1) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_YET_REGULATED,
                    "Coming soon",
                    422
                );
            }

            // Breaking change to return all the policies objects to support renewals
            if (($platform == 'ios' && version_compare($version, '1.5.63', '<')) ||
                ($platform == 'android' && version_compare($version, '1.5.67.0', '<')) ) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_UPGRADE_APP,
                    sprintf('%s %s must be upgraded due to payment method change', $platform, $version),
                    422
                );
            }

            // Test version
            $key = sprintf('UPGRADE_APP_VERSIONS_%s', $platform);
            if ($version == "0.0.0" || $redis->sismember($key, $version)) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_UPGRADE_APP,
                    sprintf('%s %s is not allowed', $platform, $version),
                    422
                );
            }

            if ($request->get('_route') == 'api_version') {
                return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
            } else {
                $includes = $this->getRequestString($request, 'include');
                $includeItems = explode(',', $includes);
                if (in_array('feature-flags', $includeItems)) {
                    $dm = $this->getManager();
                    $repo = $dm->getRepository(Feature::class);
                    $features = $repo->findAll();

                    return new JsonResponse([
                        'feature_flags' => [
                            'flags' => $this->eachApiArray($features),
                        ]
                    ]);
                } else {
                    return new JsonResponse();
                }
            }
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api versionAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }
}
