<?php

namespace AppBundle\Security;

use FOS\UserBundle\Model\UserManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessor;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use AppBundle\Document\User;
use AppBundle\Service\FacebookService;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

class CognitoIdentityUserProvider implements UserProviderInterface
{
    /** @var UserManagerInterface */
    protected $userManager;

    protected $cognito;
    
    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $developerLogin;

    /** @var string */
    protected $identityPoolId;

    /** @var LoggerInterface */
    protected $logger;

    /** @var FacebookService */
    protected $fb;

    /** @var string */
    protected $environment;

    /**
     */
    public function __construct(
        UserManagerInterface $userManager,
        DocumentManager $dm,
        $cognito,
        $developerLogin,
        $identityPoolId,
        LoggerInterface $logger,
        FacebookService $fb,
        $environment
    ) {
        $this->userManager = $userManager;
        $this->dm = $dm;
        $this->cognito = $cognito;
        $this->developerLogin = $developerLogin;
        $this->identityPoolId = $identityPoolId;
        $this->logger = $logger;
        $this->fb = $fb;
        $this->environment = $environment;
    }

    /**
     * @param string $cognitoIdentityId
     *
     * @return User|null
     */
    public function loadUserByCognitoIdentityId($cognitoIdentityId)
    {
        if (!$cognitoIdentityId || mb_strlen($cognitoIdentityId) == 0) {
            return null;
        }

        try {
            $repo = $this->dm->getRepository(User::class);
            if ($this->environment == "test") {
                /** @var User $user */
                $user = $repo->find($cognitoIdentityId);

                return $user;
            }
            $identities = $this->cognito->describeIdentity([
                'IdentityId' => $cognitoIdentityId
            ]);
            $logins = $identities['Logins'];
            if (!$logins) {
                return null;
            }

            /** @var User $user */
            $user = null;
            if (in_array($this->developerLogin, $logins)) {
                $result = $this->cognito->lookupDeveloperIdentity(array(
                    // IdentityPoolId is required
                    'IdentityPoolId' => $this->identityPoolId,
                    'IdentityId' => $cognitoIdentityId,
                    'MaxResults' => 10,
                ));
                if (isset($result['DeveloperUserIdentifierList'])) {
                    $userId = $result['DeveloperUserIdentifierList'][0];
                    /** @var User $user */
                    $user = $repo->find($userId);
                }
            } elseif (in_array("graph.facebook.com", $logins)) {
                $this->logger->error(sprintf('Logins map %s', json_encode($logins)));
                $this->fb->initToken($logins['graph.facebook.com']);
                $facebookId = $this->fb->getUserId();
                /** @var User $user */
                $user = $repo->findOneBy(['facebookId' => $facebookId]);
            }
    
            return $user;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in loadUserByCognitoIdentityId Ex: %s', $e->getMessage()));

            return null;
        }
    }

    /**
     * @param string $userToken
     *
     * @return User|null
     */
    public function loadUserByUserToken($userToken)
    {
        if (mb_strlen($userToken) == 0) {
            return null;
        }

        try {
            $repo = $this->dm->getRepository(User::class);
            /** @var User $user */
            $user = $repo->findOneBy(['token' => $userToken]);
    
            return $user;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in loadUserByUserToken Ex: %s', $e->getMessage()));

            return null;
        }
    }

    /**
     * @param string $username
     * @return \FOS\UserBundle\Model\UserInterface|null|UserInterface
     */
    public function loadUserByUsername($username)
    {
        // Compatibility with FOSUserBundle < 2.0
        if (class_exists('FOS\UserBundle\Form\Handler\RegistrationFormHandler')) {
            /** @var mixed $oldUserManager */
            $oldUserManager = $this->userManager;
            return $oldUserManager->loadUserByUsername($username);
        }

        return $this->userManager->findUserByUsername($username);
    }

    public function refreshUser(UserInterface $user)
    {
        // this is used for storing authentication in the session
        // but in this example, the token is sent in each request,
        // so authentication can be stateless. Throwing this exception
        // is proper to make things stateless
        throw new UnsupportedUserException();
    }

    /**
     * {@inheritDoc}
     */
    public function supportsClass($class)
    {
        $userClass = $this->userManager->getClass();

        return $userClass === $class || is_subclass_of($class, $userClass);
    }
}
