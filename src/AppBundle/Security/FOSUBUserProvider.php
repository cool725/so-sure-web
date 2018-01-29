<?php
namespace AppBundle\Security;

use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use HWI\Bundle\OAuthBundle\Security\Core\User\FOSUBUserProvider as BaseClass;
use Symfony\Component\Security\Core\User\UserInterface;
use AppBundle\Service\FacebookService;
use AppBundle\Document\User;
use HWI\Bundle\OAuthBundle\Security\Core\Exception\AccountNotLinkedException;
use AppBundle\Validator\Constraints\AlphanumericValidator;
use Symfony\Component\HttpFoundation\RequestStack;
use AppBundle\Validator\Constraints\UkMobileValidator;

class FOSUBUserProvider extends BaseClass
{
    const SERVICE_ACCOUNTKIT = 'accountkit';

    /** @var RequestStack */
    protected $requestStack;

    protected $encoderFactory;

    protected $authService;

    public function setRequestStack(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function setEncoderFactory($encoderFactory)
    {
        $this->encoderFactory = $encoderFactory;
    }

    public function setAuthService($authService)
    {
        $this->authService = $authService;
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
        $service = $response->getResourceOwner()->getName();
        $username = $response->getUsername();
        $search = $this->getProperty($response);
        if ($service == self::SERVICE_ACCOUNTKIT) {
            $search = 'mobileNumber';
            if (isset($response->getResponse()['phone'])) {
                $username = $response->getResponse()['phone']['number'];
            }
        }
        #if ($search == "facebook_id") {
        #    $search = "facebookId";
        #}
        if (!$username || !$search || strlen(trim($username)) == 0 || strlen(trim($search)) == 0) {
            // if username or search is empty, it could return the first in the db
            throw new \Exception(sprintf(
                'Unable to detect search %s',
                json_encode($response->getResponse())
            ));
        }
        $user = $this->userManager->findUserBy(array($search => $username));
        //when the user is registrating
        if (null === $user) {
            // Not guarenteed an email address
            if (!$response->getEmail()) {
                if ($service != self::SERVICE_ACCOUNTKIT) {
                    $msg = sprintf('Unable to find an account matching your %s details.', $service);
                } else {
                    $msg = sprintf('Unable to find an account with the mobile number provided.');
                }

                if ($request = $this->requestStack->getCurrentRequest()) {
                    if ($session = $request->getSession()) {
                        if ($session->isStarted()) {
                            $session->getFlashBag()->add('error', $msg);
                        }
                    }
                }

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

            // create new user here
            $user = $this->userManager->createUser();

            if ($service != self::SERVICE_ACCOUNTKIT) {
                $setter = 'set'.ucfirst($service);
                $setter_id = $setter.'Id';
                $setter_token = $setter.'AccessToken';
                $user->$setter_id($username);
                $user->$setter_token($this->getLongLivedAccessToken($response));
            }

            $user->setEmail($response->getEmail());
            $user->setFirstName($this->conformAlphanumeric(explode(' ', $response->getFirstName())[0], 50));
            $user->setLastName($this->conformAlphanumeric(explode(' ', $response->getLastName())[0], 50));

            // Starling
            if ($service == 'starling') {
                if (isset($response->getResponse()['dateOfBirth'])) {
                    $birthday = \DateTime::createFromFormat('Y-m-d', $response->getResponse()['dateOfBirth']);
                    $birthday = $birthday->setTime(0, 0, 0);
                    $user->setBirthday($birthday);
                }
                if (isset($response->getResponse()['phone'])) {
                    $validator = new UkMobileValidator();
                    $user->setMobileNumber($validator->conform($response->getResponse()['phone']));
                }
            }

            $user->setEnabled(true);

            $this->userManager->updateUser($user);
            return $user;
        }

        //if user exists - go with the HWIOAuth way
        //$user = parent::loadUserByOAuthUserResponse($response);

        if ($service != self::SERVICE_ACCOUNTKIT) {
            $setter = 'set' . ucfirst($service) . 'AccessToken';
            //update access token
            $user->$setter($this->getLongLivedAccessToken($response));
        }

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

    public function isHistoricalPassword($user, $plainPassword, $oldPassword, $oldSalt)
    {
        $password = $this->getPassword($user, $plainPassword, $oldSalt);

        $same = $oldPassword === $password;
        // @codingStandardsIgnoreStart
        // print sprintf('Checking %s %d (Salt: %s) %s ?= %s%s', $plainPassword, $same, $oldSalt, $oldPassword, $password, PHP_EOL);
        // @codingStandardsIgnoreEnd

        return $same;
    }

    public function getPassword(User $user, $plainPassword, $salt)
    {
        $encoder = $this->encoderFactory->getEncoder($user);

        return $encoder->encodePassword($plainPassword, $salt);
    }

    public function previousPasswordCheck(User $user)
    {
        $employee = true;
        $claims = true;
        try {
            // non url requests will not have firewall setup
            $employee = $this->authService->isGranted('ROLE_EMPLOYEE', $user);
        } catch (\Exception $e) {
            // fallback to check for known roles
            $employee = $user->hasRole('ROLE_EMPLOYEE') || $user->hasRole('ROLE_ADMIN');
        }
        try {
            // non url requests will not have firewall setup
            $claims = $this->authService->isGranted('ROLE_CLAIMS', $user);
        } catch (\Exception $e) {
            // fallback to check for known roles
            $employee = $user->hasRole('ROLE_CLAIMS');
        }

        if ($employee || $claims) {
            // PCI Requirement - 4 passwords
            $user->setPreviousPasswordCheck(!$this->hasPreviouslyUsedPassword($user, 4));
        } else {
            // For users make it simple
            $user->setPreviousPasswordCheck(!$this->hasPreviouslyUsedPassword($user, 2));
        }

        return $user->getPreviousPasswordCheck();
    }

    public function hasPreviouslyUsedPassword(User $user, $attempts = null)
    {
        $oldPasswords = $user->getPreviousPasswords();

        if (!is_array($oldPasswords)) {
            $oldPasswords = $user->getPreviousPasswords->getValues();
        }
        if (count($oldPasswords) == 0) {
            return false;
        }
        $plainPassword = $user->getPlainPassword();

        // current password not allowed
        if ($this->isHistoricalPassword($user, $plainPassword, $user->getPassword(), $user->getSalt())) {
            return true;
        }

        krsort($oldPasswords);
        $count = 1;
        foreach ($oldPasswords as $timestamp => $passwordData) {
            if ($attempts && $count >= $attempts) {
                break;
            }

            if ($this->isHistoricalPassword($user, $plainPassword, $passwordData['password'], $passwordData['salt'])) {
                return true;
            }

            $count++;
        }

        return false;
    }
}
