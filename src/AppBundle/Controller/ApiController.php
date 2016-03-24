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
use AppBundle\Document\Policy;
use AppBundle\Document\Sns;
use AppBundle\Document\User;

use AppBundle\Classes\ApiErrorCode;
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

            $postcode = trim($request->get('postcode'));
            $number = trim($request->get('number'));

            $lookup = $this->get('app.address');
            $address = $lookup->getAddress($postcode, $number);

            return new JsonResponse($address->toArray());
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
            $identity = $this->parseIdentity($request);
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['email', 'password'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $email = strtolower($data['email']);
            $user = $repo->findOneBy(['emailCanonical' => $email]);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'User not found', 403);
            }

            $encoder_service = $this->get('security.encoder_factory');
            $encoder = $encoder_service->getEncoder($user);
            if (!$encoder->isPasswordValid($user->getPassword(), $data['password'], $user->getSalt())) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_EXISTS, 'Invalid password', 403);
            }

            return new JsonResponse($user->toApiArray());
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api loginAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/login/facebook", name="api_login_facebook")
     * @Method({"POST"})
     */
    public function loginFacebookAction(Request $request)
    {
        try {
            $identity = $this->parseIdentity($request);
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['facebook_id', 'facebook_access_token', 'cognito_id'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->findOneBy(['facebook_id' => $data['facebook_id']]);
            if (!$user) {
                return $this->getErrorJsonResponse(
                    ApiErrorCode::ERROR_USER_ABSENT,
                    'Unable to locate facebook user',
                    403
                );
            }

            // TODO: Consider how we validate the facebookAuthToken - can we check against the cognito id.
            // if auth token matches, is fine, but if its different, could indicate a new token
            // could perhaps validate token against fb?
            // or see if facebook is match to authed cognito id?
            // https://developers.facebook.com/docs/php/FacebookSession/5.0.0

            return new JsonResponse($user->toApiArray());
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in api loginFacebookAction. %s', $e->getMessage()));

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
    }

    /**
     * @Route("/quote", name="api_quote")
     * @Method({"GET"})
     */
    public function quoteAction(Request $request)
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Phone::class);
            $device = trim($request->get('device'));
            $deviceFound = true;
            $phones = $repo->findBy(['devices' => $device]);
            if (!$phones || count($phones) == 0 || $device == "") {
                $this->unknownDevice($device);
                $phones = $repo->findBy(['make' => 'ALL']);
                $deviceFound = false;
            }

            $quotes = [];
            foreach ($phones as $phone) {
                $quotes[] = [
                    'monthly_premium' => $phone->getPolicyPrice(),
                    'monthly_loss' => $phone->getLossPrice(),
                    'yearly_premium' => $phone->getYearlyPolicyPrice(),
                    'yearly_loss' => $phone->getYearlyLossPrice(),
                    'phone' => $phone->asApiArray(),
                    'connection_value' => $phone->getConnectionValue(),
                    'max_connections' => $phone->getMaxConnections(),
                    'max_pot' => $phone->getMaxPot(),
                ];
            }

            return new JsonResponse([
                'quotes' => $quotes,
                'device_found' => $deviceFound,
            ]);
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
            $identity = $this->parseIdentity($request);
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
            $identity = $this->parseIdentity($request);
            $data = json_decode($request->getContent(), true)['body'];
            $endpoint = isset($data['endpoint']) ? $data['endpoint'] : null;
            if (!$endpoint) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing endpoint', 400);
            }

            $this->snsSubscribe('all', $endpoint);
            $this->snsSubscribe('unregistered', $endpoint);

            return new JsonResponse();
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
            $identity = $this->parseIdentity($request);
            $data = json_decode($request->getContent(), true)['body'];
            if (!$this->validateFields($data, ['token'])) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
            }

            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->findOneBy(['token' => $data['token']]);
            if (!$user) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'Invalid token', 403);
            }

            list($identityId, $token) = $this->getCognitoIdToken($user, $identity);

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
            $identity = $this->parseIdentity($request);
            $data = json_decode($request->getContent(), true)['body'];

            $userManager = $this->get('fos_user.user_manager');
            $user = $userManager->createUser();
            $user->setEmail(isset($data['email']) ? $data['email'] : null);
            $user->setFirstName(isset($data['first_name']) ? $data['first_name'] : null);
            $user->setLastName(isset($data['last_name']) ? $data['last_name'] : null);
            $user->setFacebookId(isset($data['facebook_id']) ? $data['facebook_id'] : null);
            $user->setFacebookAccessToken(
                isset($data['facebook_access_token']) ? $data['facebook_access_token'] : null
            );
            $user->setSnsEndpoint(isset($data['sns_endpoint']) ? $data['sns_endpoint'] : null);
            // NOTE: not completely secure, but as we're only using for an indication, it's good enough
            // http://docs.aws.amazon.com/apigateway/latest/developerguide/api-gateway-mapping-template-reference.html
            // https://forums.aws.amazon.com/thread.jspa?messageID=673393
            $clientIp = $identity['sourceIp'];
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
                list($identityId, $token) = $this->getCognitoIdToken($addedUser['user'], $identity);
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

    private function getErrorJsonResponse($errorCode, $description, $httpCode = 422)
    {
        return new JsonResponse(['code' => $errorCode, 'description' => $description], $httpCode);
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
     *
     * @return boolean true if unknown device notification was sent
     */
    private function unknownDevice($device)
    {
        if ($device == "" || $device == "generic_x86" || $device == "generic_x86_64" || $device == "Simulator") {
            return false;
        }

        $message = \Swift_Message::newInstance()
            ->setSubject('Unknown Device')
            ->setFrom('tech@so-sure.com')
            ->setTo('tech@so-sure.com')
            ->setBody(
                sprintf('Unknown device queried: %s', $device),
                'text/html'
            );
        $this->get('mailer')->send($message);

        return true;
    }
}
