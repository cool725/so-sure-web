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

use AppBundle\Classes\ApiErrorCode;
use AppBundle\Service\RateLimitService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route("/api/v1")
 */
class ApiController extends BaseController
{
    /**
     * @Route("/address", name="api_address")
     * @Method({"GET"})
     */
    public function addressAction(Request $request)
    {
        try {
            if (!$this->validateQueryFields($request, ['postcode'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowed(
                RateLimitService::TYPE_ADDRESS,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $postcode = trim($request->get('postcode'));
            $number = trim($request->get('number'));

            $lookup = $this->get('app.address');
            $address = $lookup->getAddress($postcode, $number);

            return new JsonResponse($address->toApiArray());
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api addressAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/login", name="api_login")
     * @Method({"POST"})
     */
    public function loginAction(Request $request)
    {
        try {
            // throw new \Exception('Manual Exception Test');
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['email', 'password'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowed(
                RateLimitService::TYPE_LOGIN,
                $this->getCognitoIdentityIp($request),
                $this->getCognitoIdentityId($request)
            )) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_TOO_MANY_REQUESTS, 'Too many requests', 422);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $email = strtolower($data['email']);
            $user = $repo->findOneBy(['emailCanonical' => $email]);
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

            $encoder_service = $this->get('security.encoder_factory');
            $encoder = $encoder_service->getEncoder($user);
            if (!$encoder->isPasswordValid($user->getPassword(), $data['password'], $user->getSalt())) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid password', 403);
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

            $phones = $this->getQuotes($make, $device, true);
            $deviceFound = $phones[0]->getMake() != "ALL";

            $quotes = [];
            // Memory is only an issue if there are multiple phones
            $memoryFound = count($phones) <= 1;
            foreach ($phones as $phone) {
                if ($memory <= $phone->getMemory()) {
                    $memoryFound = true;
                }
                $quotes[] = [
                    'monthly_premium' => $phone->getPolicyPrice(),
                    'monthly_loss' => $phone->getLossPrice(),
                    'yearly_premium' => $phone->getYearlyPolicyPrice(),
                    'yearly_loss' => $phone->getYearlyLossPrice(),
                    'phone' => $phone->toApiArray(),
                    'connection_value' => $phone->getConnectionValue(),
                    'max_connections' => $phone->getMaxConnections(),
                    'max_pot' => $phone->getMaxPot(),
                ];
            }
            
            if (!$deviceFound || !$memoryFound) {
                $this->unknownDevice($device, $memory);
            }

            $response = [
                'quotes' => $quotes,
                'device_found' => $deviceFound,
            ];

            if ($request->get('debug')) {
                $response['memory_found'] = $memoryFound;
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
     * @Route("/sns", name="api_sns")
     * @Method({"POST"})
     */
    public function snsAction(Request $request)
    {
        try {
            $data = json_decode($request->getContent(), true)['body'];
            $endpoint = isset($data['endpoint']) ? $data['endpoint'] : null;
            if (!$endpoint) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing endpoint', 400);
            }

            $this->snsSubscribe('all', $endpoint);
            $this->snsSubscribe('unregistered', $endpoint);

            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Endpoint added', 200);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api snsAction. %s', $e->getMessage()));

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

            list($identityId, $token) = $this->getCognitoIdToken($user, $request);

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
            $user->setMobileNumber(isset($data['mobile_number']) ? $data['mobile_number'] : null);

            // NOTE: not completely secure, but as we're only using for an indication, it's good enough
            // http://docs.aws.amazon.com/apigateway/latest/developerguide/api-gateway-mapping-template-reference.html
            // https://forums.aws.amazon.com/thread.jspa?messageID=673393
            $clientIp = $this->getCognitoIdentityIp($request);
            $user->setSignupIp($clientIp);

            $geoip = $this->get('app.geoip');
            $data = $geoip->find($clientIp);
            $user->setSignupCountry($geoip->getCountry());
            $user->setSignupLoc($geoip->getCoordinates());

            $launchUser = $this->get('app.user.launch');
            $addedUser = $launchUser->addUser($user);

            if (!$addedUser['new']) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'User already exists');
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
        $client = $this->get('aws.sns');
        $result = $client->subscribe(array(
            // TopicArn is required
            'TopicArn' => $this->getArnForTopic($topic),
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
}
