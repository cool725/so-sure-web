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
use AppBundle\Document\PolicyKeyFacts;
use AppBundle\Document\PolicyTerms;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/api/v1")
 */
class ApiController extends BaseController
{
    /**
     * @Route("/login", name="api_login")
     * @Method({"POST"})
     */
    public function loginAction(Request $request)
    {
        try {
            // throw new \Exception('Manual Exception Test');
            $data = json_decode($request->getContent(), true)['body'];
            if (isset($data['email_user'])) {
                if (!$this->validateFields($data['email_user'], ['email', 'password'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } elseif (isset($data['facebook_user'])) {
                if (!$this->validateFields($data['facebook_user'], ['facebook_id', 'facebook_access_token'])) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
                }
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_LOGIN,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = null;
            if (isset($data['email_user'])) {
                $email = strtolower($data['email_user']['email']);
                $user = $repo->findOneBy(['emailCanonical' => $email]);
            } elseif (isset($data['facebook_user'])) {
                $facebookId = trim($data['facebook_user']['facebook_id']);
                $user = $repo->findOneBy(['facebook_id' => $facebookId]);
            }
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'User not found', 403);
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

            if (isset($data['email_user'])) {
                $encoderService = $this->get('security.encoder_factory');
                $encoder = $encoderService->getEncoder($user);
                if (!$encoder->isPasswordValid(
                    $user->getPassword(),
                    $data['email_user']['password'],
                    $user->getSalt()
                )) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid password', 403);
                }
            } elseif (isset($data['facebook_user'])) {
                $facebookService = $this->get('app.facebook');
                if (!$facebookService->validateToken($user, trim($data['facebook_user']['facebook_access_token']))) {
                    return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid token', 403);
                }
                // TODO: Here we would either add a session linking a facebook_id to a cognito session
                // or on the client side, the cognito id returned, could be linked to the login
            }
            list($identityId, $token) = $this->getCognitoIdToken($user, $request);

            return new JsonResponse($user->toApiArray($identityId, $token));
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api loginAction. %s', $e->getMessage()));

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
     * @Route("/quote", name="api_quote")
     * @Method({"GET"})
     */
    public function quoteAction(Request $request)
    {
        try {
            $make = trim($request->get('make'));
            $device = trim($request->get('device'));
            $memory = (float) trim($request->get('memory'));
            $rooted = filter_var($request->get('rooted'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            $phones = $this->getQuotes($make, $device, true);
            if (!$phones) {
                throw new \Exception(sprintf('Unknown phone %s %s', $make, $device));
            }
            $deviceFound = $phones[0]->getMake() != "ALL";

            $stats = $this->get('app.stats');
            $cognitoId = $this->getCognitoIdentityId($request);
            $stats->quote(
                $cognitoId,
                new \DateTime(),
                $device,
                $memory,
                $deviceFound,
                $rooted
            );

            $quotes = [];
            // Memory is only an issue if there are multiple phones
            $memoryFound = count($phones) <= 1;
            foreach ($phones as $phone) {
                if ($memory <= $phone->getMemory()) {
                    $memoryFound = true;
                }
                $currentPhonePrice = $phone->getCurrentPhonePrice();
                if (!$currentPhonePrice) {
                    continue;
                }
                $quotes[] = [
                    'monthly_premium' => $currentPhonePrice->getMonthlyPremiumPrice(),
                    'monthly_loss' => 0,
                    'yearly_premium' => $currentPhonePrice->getYearlyPremiumPrice(),
                    'yearly_loss' => 0,
                    'phone' => $phone->toApiArray(),
                    'connection_value' => $currentPhonePrice->getInitialConnectionValue(),
                    'max_connections' => $currentPhonePrice->getMaxConnections(),
                    'max_pot' => $currentPhonePrice->getMaxPot(),
                ];
            }

            if (!$deviceFound || !$memoryFound) {
                $this->unknownDevice($device, $memory);
            }

            $differentMake = false;
            // Google phones are known to report different...
            if ($deviceFound && strtolower($phones[0]->getMake()) != strtolower($make) && $make != 'google') {
                $differentMake = true;
                $this->differentMake($phones[0]->getMake(), $make);
            }

            if ($rooted) {
                $this->rootedDevice($device, $memory);
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_UNABLE_TO_INSURE, 'Unable to insure', 422);
            }

            // Rooted is missing in the pre-launch app where, which returns the MSRP pricing data if phone not found
            // but for newer mobile versions, we should return an unable to insure if the phone isn't found
            if (strlen(trim($request->get('rooted'))) > 0 && !$deviceFound) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_UNABLE_TO_INSURE, 'Unable to insure', 422);
            }

            $response = [
                'quotes' => $quotes,
                'device_found' => $deviceFound,
            ];

            if ($request->get('debug')) {
                $response['memory_found'] = $memoryFound;
                $response['rooted'] = $rooted;
                $response['different_make'] = $differentMake;
            }

            return new JsonResponse($response);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api quoteAction. %s', $e->getMessage()));

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
            $email = strtolower($request->get('email'));
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
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api referralAciton. %s', $e->getMessage()));

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
            $email = strtolower($data['email']);
            $referralCode = $data['referral_code'];

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
            $dm->flush();

            $launchUser = $this->get('app.user.launch');
            $url = $launchUser->getShortLink($user->getId());

            return new JsonResponse(['url' => $url]);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api referralAction. %s', $e->getMessage()));

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
            $email = strtolower($data['email']);

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
                /** @var $tokenGenerator \FOS\UserBundle\Util\TokenGeneratorInterface */
                $tokenGenerator = $this->get('fos_user.util.token_generator');
                $user->setConfirmationToken($tokenGenerator->generateToken());
            }

            $this->container->get('fos_user.mailer')->sendResettingEmailMessage($user);
            $user->setPasswordRequestedAt(new \DateTime());
            $this->get('fos_user.user_manager')->updateUser($user);

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Reset password email sent', 200);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api referralAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/sns", name="api_sns")
     * @Method({"POST"})
     */
    public function snsAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['endpoint'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing endpoint', 400);
            }

            $endpoint = $data['endpoint'];
            $platform = isset($data['platform']) ? $data['platform'] : null;
            $version = isset($data['version']) ? $data['version'] : null;
            $this->snsSubscribe('all', $endpoint);
            $this->snsSubscribe('unregistered', $endpoint);
            if ($platform) {
                $this->snsSubscribe($platform, $endpoint);
                $this->snsSubscribe(sprintf('%s-%s', $platform, $version), $endpoint);
            }

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Endpoint added', 200);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api snsAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/keyfacts", name="api_get_policy_keyfacts")
     * @Method({"GET"})
     */
    public function getLatestKeyFactsAction()
    {
        try {
            $dm = $this->getManager();
            $policyKeyFactsRepo = $dm->getRepository(PolicyKeyFacts::class);
            $latestKeyFacts = $policyKeyFactsRepo->findOneBy(['latest' => true]);
            if (!$latestKeyFacts) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find keyfacts',
                    404
                );
            }
            $policyKeyFactsUrl = $this->get('router')->generate(
                'latest_policy_keyfacts',
                [
                    'policy_key' => $this->getParameter('policy_key'),
                ],
                true
            );

            return new JsonResponse($latestKeyFacts->toApiArray($policyKeyFactsUrl));
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getLatestKeyFactsAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/terms", name="api_get_policy_terms")
     * @Method({"GET"})
     */
    public function getLatestTermsAction()
    {
        try {
            $dm = $this->getManager();
            $policyTermsRepo = $dm->getRepository(PolicyTerms::class);
            $latestTerms = $policyTermsRepo->findOneBy(['latest' => true]);
            if (!$latestTerms) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_FOUND,
                    'Unable to find terms',
                    404
                );
            }
            $policyTermsUrl = $this->get('router')->generate(
                'latest_policy_terms',
                [
                    'policy_key' => $this->getParameter('policy_key'),
                ],
                true
            );

            return new JsonResponse($latestTerms->toApiArray($policyTermsUrl));
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api getLatestPolicyTerms. %s', $e->getMessage()));

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
                RateLimitService::DEVICE_TYPE_RESET,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            list($identityId, $token) = $this->getCognitoIdToken($user, $request);

            return new JsonResponse(['id' => $identityId, 'token' => $token]);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api tokenAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/token/unauth", name="api_token_unauth")
     * @Method({"POST"})
     */
    public function tokenUnauthAction(Request $request)
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
                RateLimitService::DEVICE_TYPE_RESET,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $cognitoIdentity = $this->get('app.cognito.identity');
            list($identityId, $token) = $cognitoIdentity->getCognitoIdToken($user, $data['cognito_id']);

            return new JsonResponse(['id' => $identityId, 'token' => $token]);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api tokenAction. %s', $e->getMessage()));

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
            $facebookId = isset($data['facebook_id']) ? $data['facebook_id'] : null;
            $mobileNumber = isset($data['mobile_number']) ? $data['mobile_number'] : null;
            $userExists = $repo->existsUser($data['email'], $facebookId, $mobileNumber);
            if ($userExists) {
                // Special case for prelaunch users - allow them to 'create' an account without
                // being recreated in account in the db.  This is only allowed once per user
                // and is only because the prelaunch app didn't do anything other than record email address
                $user = $repo->findOneBy(['emailCanonical' => strtolower($data['email'])]);
                if ($user && $user->isPreLaunch() && !$user->getLastLogin() && count($user->getPolicies()) == 0) {
                    $user->resetToken();
                    $user->setLastLogin(new \DateTime());
                    $dm->flush();
                    list($identityId, $token) = $this->getCognitoIdToken($user, $request);

                    return new JsonResponse($user->toApiArray($identityId, $token));
                }

                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_EXISTS,
                    'User already exists',
                    422
                );
            }

            $userManager = $this->get('fos_user.user_manager');
            $user = $userManager->createUser();
            $user->setEnabled(true);
            $user->setCognitoId($this->getCognitoIdentityId($request));
            $user->setEmail($data['email']);
            $user->setFirstName(isset($data['first_name']) ? $data['first_name'] : null);
            $user->setLastName(isset($data['last_name']) ? $data['last_name'] : null);
            $user->setFacebookId(isset($data['facebook_id']) ? $data['facebook_id'] : null);
            $user->setFacebookAccessToken(
                isset($data['facebook_access_token']) ? $data['facebook_access_token'] : null
            );
            $user->setSnsEndpoint(isset($data['sns_endpoint']) ? $data['sns_endpoint'] : null);
            $user->setMobileNumber($mobileNumber);
            $user->setReferer(isset($data['referer']) ? $data['referer'] : null);
            $birthday = $this->validateBirthday($data);
            if ($birthday instanceof Response) {
                return $birthday;
            }
            $user->setBirthday($birthday);

            // NOTE: not completely secure, but as we're only using for an indication, it's good enough
            // http://docs.aws.amazon.com/apigateway/latest/developerguide/api-gateway-mapping-template-reference.html
            // https://forums.aws.amazon.com/thread.jspa?messageID=673393
            $clientIp = $this->getCognitoIdentityIp($request);
            $user->setSignupIp($clientIp);

            $geoip = $this->get('app.geoip');
            $data = $geoip->find($clientIp);
            $user->setSignupCountry($geoip->getCountry());
            $user->setSignupLoc($geoip->getCoordinates());

            $validator = $this->get('validator');
            $errors = $validator->validate($user);
            if (count($errors) > 0) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, (string) $errors);
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

            return new JsonResponse($user->toApiArray($identityId, $token));
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api userAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/version", name="api_version")
     * @Method({"GET"})
     */
    public function versionAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['platform', 'version'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $platform = $request->get('platform');
            $version = $request->get('version');
            $redis = $this->get('snc_redis.default');

            if ($redis->exists('ERROR_NOT_YET_REGULATED')) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_YET_REGULATED,
                    "Coming soon",
                    422
                );
            }

            // Test version
            if ($version == "0.0.0") {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_UPGRADE_APP,
                    sprintf('%s %s is not allowed', $platform, $version),
                    422
                );
            }

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api versionAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    private function getArnForTopic($topic)
    {
        switch ($topic) {
            case 'all':
                return $this->getParameter('sns_prelaunch_all');
            case 'registered':
                return $this->getParameter('sns_prelaunch_registered');
            case 'unregistered':
                return $this->getParameter('sns_prelaunch_unregistered');
        }

        return null;
    }

    private function snsSubscribe($topic, $endpoint)
    {
        $topicArn = $this->getArnForTopic($topic);
        if (!$topicArn) {
            return;
        }

        $client = $this->get('aws.sns');
        $result = $client->subscribe(array(
            // TopicArn is required
            'TopicArn' => $topicArn,
            // Protocol is required
            'Protocol' => 'application',
            'Endpoint' => $endpoint,
        ));
        $subscriptionArn = $result['SubscriptionArn'];

        $dm = $this->getManager();
        $snsRepo = $dm->getRepository(Sns::class);
        $sns = $snsRepo->findOneBy(['endpoint' => $endpoint]);
        if (!$sns) {
            $sns = new Sns();
            $sns->setEndpoint($endpoint);
            $dm->persist($sns);
        }
        switch ($topic) {
            case 'all':
                $sns->setAll($subscriptionArn);
                break;
            case 'registered':
                $sns->setRegistered($subscriptionArn);
                break;
            case 'unregistered':
                $sns->setUnregistered($subscriptionArn);
                break;
            default:
                $sns->addOthers($topic, $subscriptionArn);
        }

        $dm->flush();
    }

    private function snsUnsubscribe($topic, $endpoint)
    {
        $dm = $this->getManager();
        $snsRepo = $dm->getRepository(Sns::class);
        $sns = $snsRepo->findOneBy(['endpoint' => $endpoint]);
        $subscriptionArn = null;
        switch ($topic) {
            case 'all':
                $subscriptionArn = $sns->getAll();
                $sns->setAll(null);
                break;
            case 'registered':
                $subscriptionArn = $sns->getRegistered();
                $sns->setRegistered(null);
                break;
            case 'unregistered':
                $subscriptionArn = $sns->getUnregistered();
                $sns->getUnregistered(null);
                break;
        }

        if (!$subscriptionArn) {
            return;
        }

        $client = $this->get('aws.sns');
        $result = $client->unsubscribe(array(
            'SubscriptionArn' => $subscriptionArn,
        ));
        $dm->flush();
    }

    /**
     * @param string $device
     * @param float  $memory
     *
     * @return boolean true if unknown device notification was sent
     */
    private function unknownDevice($device, $memory)
    {
        if ($device == "" || $device == "generic_x86" || $device == "generic_x86_64" || $device == "Simulator") {
            return false;
        }

        $body = sprintf(
            'Unknown device queried: %s (%s GB). If device exists, memory may be higher than expected',
            $device,
            $memory
        );
        $message = \Swift_Message::newInstance()
            ->setSubject('Unknown Device/Memory')
            ->setFrom('tech@so-sure.com')
            ->setTo('tech@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);

        return true;
    }

    /**
     * @param string $dbMake
     * @param string $phoneMake
     */
    private function differentMake($dbMake, $phoneMake)
    {
        $body = sprintf(
            'Make in db is different than phone make. Db: %s Phone: %s',
            $dbMake,
            $phoneMake
        );
        $message = \Swift_Message::newInstance()
            ->setSubject('Make different in db')
            ->setFrom('tech@so-sure.com')
            ->setTo('tech@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);
    }

    /**
     * @param string $device
     * @param float  $memory
     */
    private function rootedDevice($device, $memory)
    {
        $body = sprintf(
            'Rooted device queried: %s (%s GB).',
            $device,
            $memory
        );
        $message = \Swift_Message::newInstance()
            ->setSubject('Rooted Device/Memory')
            ->setFrom('tech@so-sure.com')
            ->setTo('tech@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);
    }
}
