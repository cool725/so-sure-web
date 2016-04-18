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

    /**
     */
    public function __construct(
        UserManagerInterface $userManager,
        DocumentManager $dm,
        $cognito,
        $developerLogin,
        $identityPoolId,
        LoggerInterface $logger,
        FacebookService $fb
    ) {
        $this->userManager = $userManager;
        $this->dm = $dm;
        $this->cognito = $cognito;
        $this->developerLogin = $developerLogin;
        $this->identityPoolId = $identityPoolId;
        $this->logger = $logger;
        $this->fb = $fb;
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
                $this->logger->error(sprintf('Facebook graph %s', $logins['graph.facebook.com']));
                $this->fb->initToken($logins['graph.facebook.com']);
                $facebookId = $this->fb->getUserId();
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
