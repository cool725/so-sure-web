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
use Psr\Log\LoggerInterface;

class CognitoIdentityUserProvider implements UserProviderInterface
{
    /**
     * @var UserManagerInterface
     */
    protected $userManager;

    protected $cognito;
    protected $dm;

    /** @var string */
    protected $developerLogin;

    /** @var string */
    protected $identityPoolId;

    /** @var LoggerInterface */
    protected $logger;

    /**
     */
    public function __construct(
        UserManagerInterface $userManager,
        $doctrine,
        $cognito,
        $developerLogin,
        $identityPoolId,
        LoggerInterface $logger
    ) {
        $this->userManager = $userManager;
        $this->dm = $doctrine->getManager();
        $this->cognito = $cognito;
        $this->developerLogin = $developerLogin;
        $this->identityPoolId = $identityPoolId;
        $this->logger = $logger;
    }

    
    /**
     * @param string $cognitoIdentityId
     *
     * @return User
     */
    public function loadUserByCognitoIdentityId($cognitoIdentityId)
    {
        if (!$cognitoIdentityId || strlen($cognitoIdentityId) == 0) {
            return null;
        }

        try {
            $repo = $this->dm->getRepository(User::class);
            $identities = $this->cognito->describeIdentity([
                'IdentityId' => $cognitoIdentityId
            ]);
            $logins = $identities['Logins'];
            if (!$logins) {
                return null;
            }

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
                    $user = $repo->find($userId);
                }
            } elseif (in_array("graph.facebook.com", $logins)) {
                // TODO: need to see what's being returned here for facebook
                $user = $repo->findOneBy(['facebookId' => $logins['graph.facebook.com']]);
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
     * @return User
     */
    public function loadUserByUserToken($userToken)
    {
        if (strlen($userToken) == 0) {
            return null;
        }

        try {
            $repo = $this->dm->getRepository(User::class);
            $user = $repo->findOneBy(['token' => $userToken]);
    
            return $user;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error in loadUserByUserToken Ex: %s', $e->getMessage()));

            return null;
        }
    }
    
    /**
     * {@inheritDoc}
     */
    public function loadUserByUsername($username)
    {
        // Compatibility with FOSUserBundle < 2.0
        if (class_exists('FOS\UserBundle\Form\Handler\RegistrationFormHandler')) {
            return $this->userManager->loadUserByUsername($username);
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
