<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use Doctrine\ODM\MongoDB\DocumentManager;

class CognitoIdentityService
{
    /** @var LoggerInterface */
    protected $logger;

    protected $cognito;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $developerLogin;

    /** @var string */
    protected $identityPoolId;

    /**
     * @param LoggerInterface $logger
     * @param DocumentManager $dm
     * @param                 $cognito
     * @param string          $developerLogin
     * @param string          $identityPoolId
     */
    public function __construct(
        LoggerInterface $logger,
        DocumentManager $dm,
        $cognito,
        $developerLogin,
        $identityPoolId
    ) {
        $this->logger = $logger;
        $this->dm = $dm;
        $this->cognito = $cognito;
        $this->developerLogin = $developerLogin;
        $this->identityPoolId = $identityPoolId;
    }

    /**
     * Get open id token for cognito
     *
     * @param User   $user
     * @param string $cognitoIdentityId
     */
    public function getCognitoIdToken(User $user, $cognitoIdentityId = null)
    {
        $devIdentity = array(
            'IdentityPoolId' => $this->identityPoolId,
            'Logins' => array(
                $this->developerLogin => $user->getId(),
            ),
            'TokenDuration' => 300,
        );
        if ($cognitoIdentityId) {
            $devIdentity['IdentityId'] = $cognitoIdentityId;
        }
        $result = $this->cognito->getOpenIdTokenForDeveloperIdentity($devIdentity);
        $identityId = $result->get('IdentityId');
        $token = $result->get('Token');
        $this->logger->warning(sprintf('Found Cognito Identity %s', $identityId));

        return [$identityId, $token];
    }

    public function getId()
    {
        $result = $this->cognito->getId(array(
            'IdentityPoolId' => $this->identityPoolId,
        ));
        $identityId = $result->get('IdentityId');

        return $identityId;
    }
}
