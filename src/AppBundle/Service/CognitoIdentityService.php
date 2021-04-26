<?php
namespace AppBundle\Service;

use Aws\CognitoIdentity\CognitoIdentityClient;
use Psr\Log\LoggerInterface;
use AppBundle\Document\User;
use Doctrine\ODM\MongoDB\DocumentManager;

class CognitoIdentityService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var CognitoIdentityClient */
    protected $cognito;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $developerLogin;

    /** @var string */
    protected $identityPoolId;

    /** @var string */
    protected $environment;

    /**
     * @param LoggerInterface       $logger
     * @param DocumentManager       $dm
     * @param CognitoIdentityClient $cognito
     * @param string                $developerLogin
     * @param string                $identityPoolId
     * @param string                $environment
     */
    public function __construct(
        LoggerInterface $logger,
        DocumentManager $dm,
        CognitoIdentityClient $cognito,
        $developerLogin,
        $identityPoolId,
        $environment
    ) {
        $this->logger = $logger;
        $this->dm = $dm;
        $this->cognito = $cognito;
        $this->developerLogin = $developerLogin;
        $this->identityPoolId = $identityPoolId;
        $this->environment = $environment;
    }

    /**
     * Get open id token for cognito
     *
     * @param User   $user
     * @param string $cognitoIdentityId
     */
    public function getCognitoIdToken(User $user, $cognitoIdentityId = null, $userpoolToken = null)
    {
        $devIdentity = array(
            'IdentityPoolId' => $this->identityPoolId,
            'Logins' => array(
                $this->developerLogin => $user->getId(),
            ),
            'TokenDuration' => 300,
        );

        if ($userpoolToken) {
            if ($this->environment == 'prod') {
                $devIdentity['Logins']['cognito-idp.eu-west-2.amazonaws.com/eu-west-2_jMu8AHCWA'] = $userpoolToken;
            } else {
                $devIdentity['Logins']['cognito-idp.eu-west-2.amazonaws.com/eu-west-2_EuSVRGZ7v'] = $userpoolToken;
            }
        }

        if ($cognitoIdentityId) {
            $devIdentity['IdentityId'] = $cognitoIdentityId;
        }
        
        if ($this->environment != "test") {
            $result = $this->cognito->getOpenIdTokenForDeveloperIdentity($devIdentity);
            $identityId = $result->get('IdentityId');
            $token = $result->get('Token');
        } else {
            $identityId = $user->getId();
            $token = $user->getId();
        }
        $this->logger->debug(sprintf('Found Cognito Identity %s', $identityId));
        if (!$identityId || !$token) {
            $this->logger->error(sprintf(
                'Failed to find cognito id for user %s. [%s/%s/%s]',
                $user->getId(),
                $cognitoIdentityId,
                $identityId,
                $token
            ));
        }

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

    public function delete($cognitoIdentityId)
    {
        $result = $this->cognito->deleteIdentities(array(
            'IdentityIdsToDelete' => [$cognitoIdentityId],
        ));
        $this->logger->info(sprintf('Delete cognito id: %s Res: %s', $cognitoIdentityId, json_encode($result)));

        return true;
    }

    public function deleteLastestMobileToken(User $user)
    {
        $identityLog = $user->getLatestMobileIdentityLog();
        if ($identityLog && $identityLog->getCognitoId()) {
            return $this->delete($identityLog->getCognitoId());
        }

        return false;
    }
}
