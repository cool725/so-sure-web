<?php
namespace AppBundle\Security;

use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\FOSUBUserProvider as BaseClass;
use Symfony\Component\Security\Core\User\UserInterface;
use AppBundle\Service\FacebookService;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use AppBundle\Validator\Constraints\AlphanumericValidator;
use Symfony\Component\HttpFoundation\RequestStack;

class FOSUBUserProvider extends BaseClass
{
    /** @var RequestStack */
    protected $requestStack;

    public function setRequestStack(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    /**
     * {@inheritDoc}
     */
    public function connect(UserInterface $user, UserResponseInterface $response)
    {
        $property = $this->getProperty($response);
        $username = $response->getUsername();

        //on connect - get the access token and the user ID
        $service = $response->getResourceOwner()->getName();

        $setter = 'set'.ucfirst($service);
        $setter_id = $setter.'Id';
        $setter_token = $setter.'AccessToken';

        //we "disconnect" previously connected users
        if (null !== $previousUser = $this->userManager->findUserBy(array($property => $username))) {
            $previousUser->$setter_id(null);
            $previousUser->$setter_token(null);
            $this->userManager->updateUser($previousUser);
        }

        //we connect current user
        $user->$setter_id($username);
        $user->$setter_token($this->getLongLivedAccessToken($response));

        $this->userManager->updateUser($user);
    }

    /**
     * {@inheritdoc}
     */
    public function loadUserByOAuthUserResponse(UserResponseInterface $response)
    {
        $username = $response->getUsername();
        $search = $this->getProperty($response);
        if ($search == "facebook_id") {
            $search = "facebookId";
        }
        $user = $this->userManager->findUserBy(array($search => $username));
        //when the user is registrating
        if (null === $user) {
            // Not guarenteed an email address
            if (!$response->getEmail()) {
                return null;
            }
            if ($this->userManager->findUserBy(['emailCanonical' => strtolower($response->getEmail())])) {
                $msg = 'You appear to already have an account, but its not connected. Please login and connect.';

                if ($request = $this->requestStack->getCurrentRequest()) {
                    if ($session = $request->getSession()) {
                        if ($session->isStarted()) {
                            $session->getFlashBag()->add('error', $msg);
                        }
                    }
                }

                throw new AccountNotLinkedException($msg);
            }
            $service = $response->getResourceOwner()->getName();
            $setter = 'set'.ucfirst($service);
            $setter_id = $setter.'Id';
            $setter_token = $setter.'AccessToken';
            // create new user here
            $user = $this->userManager->createUser();
            $user->$setter_id($username);
            $user->$setter_token($this->getLongLivedAccessToken($response));

            $user->setEmail($response->getEmail());
            $user->setFirstName($this->conformAlphanumeric(explode(' ', $response->getFirstName())[0], 50));
            $user->setLastName($this->conformAlphanumeric(explode(' ', $response->getLastName())[0], 50));
            $user->setEnabled(true);

            $this->userManager->updateUser($user);
            return $user;
        }

        //if user exists - go with the HWIOAuth way
        //$user = parent::loadUserByOAuthUserResponse($response);

        $serviceName = $response->getResourceOwner()->getName();
        $setter = 'set' . ucfirst($serviceName) . 'AccessToken';

        //update access token
        $user->$setter($this->getLongLivedAccessToken($response));

        return $user;
    }

    /**
     * @param FacebookService $facebook
     */
    public function setFacebook(FacebookService $facebook)
    {
        $this->facebook = $facebook;
    }

    protected function conformAlphanumeric($value, $length)
    {
        $validator = new AlphanumericValidator();

        return $validator->conform(substr($value, 0, $length));
    }

    private function getLongLivedAccessToken(UserResponseInterface $response)
    {
        $service = $response->getResourceOwner()->getName();
        if ($service != 'Facebook' || !$this->facebook) {
            return $response->getAccessToken();
        }

        $fb = $this->facebook->initToken($response->getAccessToken());
        $fb->setExtendedAccessToken(); //long-live access_token 60 days

        return $fb->getAccessToken();
    }
}
