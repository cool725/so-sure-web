<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\Sns;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route("/api/v1")
 */
class ApiController extends BaseController
{
    const ERROR_UKNOWN=1;
    const ERROR_MISSING_PARAM=2;
    const ERROR_USER_EXISTS=100;
    const ERROR_USER_ABSENT=101;

    /**
     * @Route("/login", name="api_login")
     * @Method({"POST"})
     */
    public function loginAction(Request $request)
    {
        $identity = $this->parseIdentity($request);
        $data = json_decode($request->getContent(), true)['body'];
        if (!$this->validateFields($data, ['username', 'password'])) {
            return $this->getErrorJsonResponse(self::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->findOneBy(['username' => $data['username']]);
        if (!$user) {
            return $this->getErrorJsonResponse(self::ERROR_USER_ABSENT, 'User not found', 403);
        }

        $encoder_service = $this->get('security.encoder_factory');
        $encoder = $encoder_service->getEncoder($user);
        if (!$encoder->isPasswordValid($user->getPassword(), $data['password'], $user->getSalt())) {
            return $this->getErrorJsonResponse(self::ERROR_USER_EXISTS, 'Invalid password', 403);
        }

        list($identityId, $token) = $this->getCognitoIdToken($user, $identity);

        return new JsonResponse($user->toApiArray($identityId, $token));
    }

    /**
     * @Route("/login/facebook", name="api_login_facebook")
     * @Method({"POST"})
     */
    public function loginFacebookAction(Request $request)
    {
        $identity = $this->parseIdentity($request);
        $data = json_decode($request->getContent(), true)['body'];
        if (!$this->validateFields($data, ['facebook_id', 'facebook_access_token', 'cognito_id'])) {
            return $this->getErrorJsonResponse(self::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->findOneBy(['facebook_id' => $data['facebook_id']]);
        if (!$user) {
            return $this->getErrorJsonResponse(self::ERROR_USER_ABSENT, 'Unable to locate facebook user', 403);
        }

        // TODO: Consider how we validate the facebookAuthToken - can we check against the cognito id.
        // if auth token matches, is fine, but if its different, could indicate a new token
        // could perhaps validate token against fb?
        // or see if facebook is match to authed cognito id?
        // https://developers.facebook.com/docs/php/FacebookSession/5.0.0

        return new JsonResponse($user->toApiArray());
    }

    /**
     * @Route("/quote", name="api_quote")
     * @Method({"GET"})
     */
    public function quoteAction(Request $request)
    {
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
                'yearly_premium' => $phone->getPolicyPrice() * 12,
                'yearly_loss' => $phone->getLossPrice() * 12,
                'phone' => $phone->asArray(),
            ];
        }

        return new JsonResponse([
            'quotes' => $quotes,
            'device_found' => $deviceFound,
        ]);
    }

    /**
     * @Route("/referral", name="api_referral")
     * @Method({"GET"})
     */
    public function referralAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->find($request->get('user_id'));
        if (!$user) {
            return $this->getErrorJsonResponse(self::ERROR_USER_ABSENT, 'Unable to locate referral for user', 422);
        }

        $launchUser = $this->get('app.user.launch');
        $url = $launchUser->getLink($user->getId());

        return new JsonResponse(['url' => $url]);
    }

    /**
     * @Route("/referral", name="api_add_referral")
     * @Method({"POST"})
     */
    public function referralAddAction(Request $request)
    {
        $identity = $this->parseIdentity($request);
        $data = json_decode($request->getContent(), true)['body'];
        if (!$this->validateFields($data, ['user_id', 'referral_code'])) {
            return $this->getErrorJsonResponse(self::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }
        $userId = $data['user_id'];
        $referralCode = $data['referral_code'];

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->find($userId);
        $referralUser = $repo->find($referralCode);
        if (!$user || !$referralUser || $user->getReferred()) {
            return $this->getErrorJsonResponse(self::ERROR_USER_ABSENT, 'Unable to locate user or referral code', 422);
        }
        $user->setReferred($referralUser);
        $dm->flush();

        $launchUser = $this->get('app.user.launch');
        $url = $launchUser->getLink($user->getId());

        return new JsonResponse(['url' => $url]);
    }

    /**
     * @Route("/sns", name="api_sns")
     * @Method({"POST"})
     */
    public function snsAction(Request $request)
    {
        $identity = $this->parseIdentity($request);
        $data = json_decode($request->getContent(), true)['body'];
        $endpoint = isset($data['endpoint']) ? $data['endpoint'] : null;
        if (!$endpoint) {
            return $this->getErrorJsonResponse(self::ERROR_MISSING_PARAM, 'Missing endpoint', 400);
        }

        $this->snsSubscribe('all', $endpoint);
        $this->snsSubscribe('unregistered', $endpoint);

        return new JsonResponse();
    }

    /**
     * @Route("/token", name="api_token")
     * @Method({"POST"})
     */
    public function tokenAction(Request $request)
    {
        $identity = $this->parseIdentity($request);
        $data = json_decode($request->getContent(), true)['body'];
        if (!$this->validateFields($data, ['token'])) {
            return $this->getErrorJsonResponse(self::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->findOneBy(['token' => $data['token']]);
        if (!$user) {
            return $this->getErrorJsonResponse(self::ERROR_USER_ABSENT, 'Invalid token', 403);
        }

        list($identityId, $token) = $this->getCognitoIdToken($user, $identity);

        return new JsonResponse(['id' => $identityId, 'token' => $token]);
    }

    /**
     * @Route("/user", name="api_user")
     * @Method({"POST"})
     */
    public function userAction(Request $request)
    {
        $identity = $this->parseIdentity($request);
        $data = json_decode($request->getContent(), true)['body'];

        $userManager = $this->get('fos_user.user_manager');
        $user = $userManager->createUser();
        $user->setEmail(isset($data['email']) ? $data['email'] : null);
        $user->setFirstName(isset($data['first_name']) ? $data['first_name'] : null);
        $user->setLastName(isset($data['last_name']) ? $data['last_name'] : null);
        $user->setFacebookId(isset($data['facebook_id']) ? $data['facebook_id'] : null);
        $user->setFacebookAccessToken(isset($data['facebook_access_token']) ? $data['facebook_access_token'] : null);
        $user->setSnsEndpoint(isset($data['sns_endpoint']) ? $data['sns_endpoint'] : null);

        $launchUser = $this->get('app.user.launch');
        $addedUser = $launchUser->addUser($user);

        if (!$addedUser['new']) {
            return $this->getErrorJsonResponse(self::ERROR_USER_EXISTS, 'User already exists');
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
    }

    private function getErrorJsonResponse($errorCode, $description, $httpCode = 422)
    {
        return new JsonResponse(['code' => $errorCode, 'description' => $description], $httpCode);
    }

    private function getArnForTopic($topic)
    {
        switch ($topic) {
            case 'all':
                return 'arn:aws:sns:eu-west-1:812402538357:Prelaunch_All';
            case 'registered':
                return 'arn:aws:sns:eu-west-1:812402538357:Prelaunch_Registered';
            case 'unregistered':
                return 'arn:aws:sns:eu-west-1:812402538357:Prelaunch_Unregistered';
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

    private function getCognitoIdToken(User $user, $identity)
    {
        $cognito = $this->get('aws.cognito');
        $devIdentity = array(
            'IdentityPoolId' => $this->getParameter('aws_cognito_identitypoolid'),
            'Logins' => array(
                'login.so-sure.com' => $user->getId(),
            ),
            'TokenDuration' => 300,
        );
        if (isset($identity['cognitoIdentityId'])) {
            $devIdentity['IdentityId'] = $identity['cognitoIdentityId'];
        }
        $result = $cognito->getOpenIdTokenForDeveloperIdentity($devIdentity);
        $identityId = $result->get('IdentityId');
        $token = $result->get('Token');
        $this->get('logger')->warning(sprintf('Found Cognito Identity %s', $identityId));

        return [$identityId, $token];
    }

    /**
     * @param string $device
     *
     * @return boolean true if unknown device notification was sent
     */
    private function unknownDevice($device)
    {
        if ($device == "" || $device == "generic_x86" || $device == "generic_x86_64") {
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

    /**
     * Validate that body fields are present
     *
     * @param array $data
     * @param array $fields
     *
     * @return boolean true if all fields are present
     */
    private function validateFields($data, $fields)
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || strlen($data[$field]) == 0) {
                return false;
            }
        }

        return true;
    }
}
