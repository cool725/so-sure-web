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
use AppBundle\Document\SCode;
use AppBundle\Document\User;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\PolicyTerms;

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use AppBundle\Exception\ValidationException;

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
            if ($this->getRequestBool($request, 'debug')) {
                throw new \Exception('Debug Exception Test');
            }

            $data = json_decode($request->getContent(), true)['body'];
            $emailUserData = null;
            $facebookUserData = null;
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
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = null;
            if ($emailUserData) {
                $email = strtolower($this->getDataString($emailUserData, 'email'));
                $user = $repo->findOneBy(['emailCanonical' => $email]);
            } elseif ($facebookUserData) {
                $facebookId = $this->getDataString($facebookUserData, 'facebook_id');
                $user = $repo->findOneBy(['facebookId' => $facebookId]);
            }
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'User not found', 403);
            }

            /* Apple appears to have problems logging in
             * so may want to re-enable for releases
            if ($user->getEmailCanonical() == "apple@so-sure.com") {
                list($identityId, $token) = $this->getCognitoIdToken($user, $request);

                return new JsonResponse($user->toApiArray($identityId, $token));
            }
            */

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByDevice(
                RateLimitService::DEVICE_TYPE_LOGIN,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
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
            }
            list($identityId, $token) = $this->getCognitoIdToken($user, $request);

            return new JsonResponse($user->toApiArray($identityId, $token));
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

                // If there is an end date, then quote should be valid until then
                $quoteValidTo = $currentPhonePrice->getValidTo();
                if (!$quoteValidTo) {
                    $quoteValidTo = new \DateTime();
                    $quoteValidTo->add(new \DateInterval('P1D'));
                }

                $promoAddition = 0;
                $isPromoLaunch = false;
                // We've decided not to include the promo values in the quote as wording might be too vague
                // but might want to have a promo in the quote in the future, so leaving code commented out
                /*
                $dm = $this->getManager();
                $repo = $dm->getRepository(SalvaPhonePolicy::class);
                if ($repo->isPromoLaunch() && !$this->getRequestBool($request, 'debug')) {
                    $promoAddition = SalvaPhonePolicy::PROMO_LAUNCH_VALUE;
                    $isPromoLaunch = true;
                }
                */

                $quotes[] = [
                    'monthly_premium' => $currentPhonePrice->getMonthlyPremiumPrice(),
                    'monthly_loss' => 0,
                    'yearly_premium' => $currentPhonePrice->getYearlyPremiumPrice(),
                    'yearly_loss' => 0,
                    'phone' => $phone->toApiArray(),
                    'connection_value' => $currentPhonePrice->getInitialConnectionValue($promoAddition),
                    'max_connections' => $currentPhonePrice->getMaxConnections($promoAddition, $isPromoLaunch),
                    'max_pot' => $currentPhonePrice->getMaxPot($isPromoLaunch),
                    'valid_to' => $quoteValidTo->format(\DateTime::ATOM),
                ];
            }

            if (!$deviceFound || !$memoryFound) {
                $this->unknownDevice($device, $memory);
            }

            $differentMake = false;
            if ($deviceFound && !$phones[0]->isSameMake($make)) {
                $differentMake = true;
                $this->differentMake($phones[0]->getMake(), $make);
            }

            if ($rooted) {
                $this->rootedDevice($device, $memory);
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_UNABLE_TO_INSURE, 'Unable to insure', 422);
            }

            // Rooted is missing in the pre-launch app where, which returns the MSRP pricing data if phone not found
            // but for newer mobile versions, we should return an unable to insure if the phone isn't found
            if (strlen($this->getRequestString($request, 'rooted')) > 0 && !$deviceFound) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_QUOTE_UNABLE_TO_INSURE, 'Unable to insure', 422);
            }

            $response = [
                'quotes' => $quotes,
                'device_found' => $deviceFound,
            ];

            if ($this->getRequestBool($request, 'debug')) {
                $response['memory_found'] = $memoryFound;
                $response['rooted'] = $rooted;
                $response['different_make'] = $differentMake;
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
            $email = strtolower($this->getRequestString($request, 'email'));
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
            $email = strtolower($this->getDataString($data, 'email'));
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
            $email = strtolower($this->getDataString($data, 'email'));

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

            $endpoint = $this->getDataString($data, 'endpoint');
            $platform = $this->getDataString($data, 'platform');
            $version = $this->getDataString($data, 'version');
            $oldEndpoint = $this->getDataString($data, 'old_endpoint');
            $this->snsSubscribe('all', $endpoint);
            $this->snsSubscribe('unregistered', $endpoint);
            if ($platform) {
                $this->snsSubscribe($platform, $endpoint);
                $this->snsSubscribe(sprintf('%s-%s', $platform, $version), $endpoint);
            }
            if ($oldEndpoint) {
                $this->snsUnsubscribe('all', $oldEndpoint);
                $this->snsUnsubscribe('unregistered', $oldEndpoint);
                $this->snsUnsubscribe('registered', $oldEndpoint);

                $dm = $this->getManager();
                $repo = $dm->getRepository(Sns::class);
                $remove = $repo->findOneBy(['endpoint' => $oldEndpoint]);
                if ($remove) {
                    $dm->remove($remove);
                    $dm->flush();
                }
            }

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Endpoint added', 200);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api snsAction.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/policy/terms", name="api_get_policy_terms")
     * @Method({"GET"})
     */
    public function getLatestTermsAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['maxPotValue'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

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
            $policyTermsRoute = $this->get('router')->generate(
                'latest_policy_terms',
                [
                    'policy_key' => $this->getParameter('policy_key'),
                    'maxPotValue' => $this->getRequestString($request, 'maxPotValue'),
                ],
                false
            );
            $policyTermsUrl = sprintf("%s%s", $this->getParameter('api_base_url'), $policyTermsRoute);

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
            $mobileNumber = $this->getDataString($data, 'mobile_number');
            $userExists = $repo->existsUser($this->getDataString($data, 'email'), $facebookId, $mobileNumber);
            if ($userExists) {
                // Special case for prelaunch users - allow them to 'create' an account without
                // being recreated in account in the db.  This is only allowed once per user
                // and is only because the prelaunch app didn't do anything other than record email address
                $user = $repo->findOneBy(['emailCanonical' => strtolower($this->getDataString($data, 'email'))]);
                if ($user && $user->isPreLaunch() && !$user->getLastLogin() && count($user->getPolicies()) == 0) {
                    $user->resetToken();
                    $user->setLastLogin(new \DateTime());
                } else {
                    return $this->getErrorJsonResponse(
                        ApiErrorCode::ERROR_USER_EXISTS,
                        'User already exists',
                        422
                    );
                }
            } else {
                $userManager = $this->get('fos_user.user_manager');
                $user = $userManager->createUser();
            }

            $user->setEnabled(true);
            $user->setEmail($this->getDataString($data, 'email'));
            $user->setFirstName(isset($data['first_name']) ? ucfirst($this->getDataString($data, 'first_name')) : null);
            $user->setLastName(isset($data['last_name']) ? ucfirst($this->getDataString($data, 'last_name')) : null);
            $user->setFacebookId($this->getDataString($data, 'facebook_id'));
            $user->setFacebookAccessToken($this->getDataString($data, 'facebook_access_token'));
            $user->setSnsEndpoint($this->getDataString($data, 'sns_endpoint'));
            $user->setMobileNumber($mobileNumber);
            $user->setReferer($this->getDataString($data, 'referer'));
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

            $this->validateObject($user);

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
            $redis = $this->get('snc_redis.default');

            if ($redis->exists('ERROR_NOT_YET_REGULATED')) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_NOT_YET_REGULATED,
                    "Coming soon",
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

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
        } catch (ValidationException $ex) {
            $this->get('logger')->warning('Failed validation.', ['exception' => $ex]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, $ex->getMessage(), 422);
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api versionAction.', ['exception' => $e]);

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
