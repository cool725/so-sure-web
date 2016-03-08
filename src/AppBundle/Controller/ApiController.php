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
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

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
        $identity = $this->parseIdentity($request);
        $data = json_decode($request->getContent(), true)['body'];

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->findOneBy(['username' => $data['username']]);
        if (!$user) {
            return new JsonResponse(['user_exists' => false], 401);
        }

        $encoder_service = $this->get('security.encoder_factory');
        $encoder = $encoder_service->getEncoder($user);
        if (!$encoder->isPasswordValid($user->getPassword(), $data['password'], $user->getSalt())) {
            return new JsonResponse(['user_exists' => true], 401);
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

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->findOneBy(['facebook_id' => $data['facebook_id']]);
        if (!$user) {
            return new JsonResponse(['user_exists' => false], 401);
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

        $this->get('logger')->warning(sprintf('X-Forwarded: %s', $request->headers->get('X-FORWARDED-FOR')));

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
            return new JsonResponse(['url' => null]);
        }

        $launchUser = $this->get('app.user.launch');
        $url = $launchUser->getLink($user->getId());

        return new JsonResponse(['url' => $url]);
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

        $launchUser = $this->get('app.user.launch');
        $addedUser = $launchUser->addUser($user);

        $identityId = null;
        $token = null;
        // Important! This should only run if the user is a new user - otherwise, could impersonate an existing user
        if ($addedUser['new'] && $identityId) {
            list($identityId, $token) = $this->getCognitoIdToken($addedUser['user'], $identity);
        }

        return new JsonResponse($user->toApiArray($identityId, $token));
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
}
