<?php
namespace AppBundle\Security;

use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\FOSUBUserProvider as BaseClass;
use Symfony\Component\Security\Core\User\UserInterface;
use AppBundle\Service\FacebookService;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;

class FOSUBUserProvider extends BaseClass
{
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
            throw new AccountNotLinkedException(sprintf("Sorry, but we're unable to find a linked account. Please try logging in with your email or mobile number.%s => %s",$this->getProperty($response), $username));
            /*
            $service = $response->getResourceOwner()->getName();
            $setter = 'set'.ucfirst($service);
            $setter_id = $setter.'Id';
            $setter_token = $setter.'AccessToken';
            // create new user here
            $user = $this->userManager->createUser();
            $user->$setter_id($username);
            $user->$setter_token($this->getLongLivedAccessToken($response));

            $user->setEmail($response->getEmail());
            $user->setFirstName($response->getFirstName());
            $user->setLastName($response->getLastName());
            $user->setEnabled(true);

            $this->userManager->updateUser($user);
            return $user;
            */
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
