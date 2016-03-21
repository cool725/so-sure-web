<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use AppBundle\Document\User;

class CognitoIdentityService
{
    /** @var LoggerInterface */
    protected $logger;

    protected $cognito;
    protected $dm;

    /** @var string */
    protected $developerLogin;

    /** @var string */
    protected $identityPoolId;

    /**
     * @param LoggerInterface $logger
     * @param                 $doctrine
     * @param                 $cognito
     * @param string          $developerLogin
     * @param string          $identityPoolId
     */
    public function __construct(LoggerInterface $logger, $doctrine, $cognito, $developerLogin, $identityPoolId)
    {
        $this->logger = $logger;
        $this->dm = $doctrine->getManager();
        $this->cognito = $cognito;
        $this->developerLogin = $developerLogin;
        $this->identityPoolId = $identityPoolId;
    }

    /**
     * @param string $requestContent
     *
     * @return array|null
     */
    public function parseIdentity($requestContent)
    {
        // TODO: Change to debug
        $this->logger->warning(sprintf("Raw: %s", $requestContent));
        try {
            $data = json_decode($requestContent, true);

            $str = $data['identity'];
            $str = str_replace(',', '&', $str);
            $str = str_replace('{', '', $str);
            $str = str_replace('}', '', $str);
            $str = str_replace(' ', '', $str);
            parse_str($str, $identity);

            // TODO: Change to debug
            $this->logger->warning(sprintf("Data: %s", print_r($data, true)));
            $this->logger->warning(sprintf("Identity: %s", print_r($identity, true)));

            return $identity;
        } catch (\Exception $e) {
            $this->logger->error(sprintf('Error processing identity: %s', $e->getMessage()));
        }

        return null;
    }

    /**
     * Get open id token for cognito
     *
     * @param User  $user
     * @param array $identity
     */
    public function getCognitoIdToken(User $user, $identity)
    {
        $devIdentity = array(
            'IdentityPoolId' => $this->identityPoolId,
            'Logins' => array(
                $this->developerLogin => $user->getId(),
            ),
            'TokenDuration' => 300,
        );
        if (isset($identity['cognitoIdentityId'])) {
            $devIdentity['IdentityId'] = $identity['cognitoIdentityId'];
        }
        $result = $this->cognito->getOpenIdTokenForDeveloperIdentity($devIdentity);
        $identityId = $result->get('IdentityId');
        $token = $result->get('Token');
        $this->logger->warning(sprintf('Found Cognito Identity %s', $identityId));

        return [$identityId, $token];
    }

    /**
     * Get the user from the identity
     *
     * @param array $identity
     *
     * @return User
     */
    public function getUser($identity)
    {
        if (!isset($identity['cognitoIdentityId'])) {
            return null;
        }

        $repo = $this->dm->getRepository(User::class);
        $identities = $this->cognito->describeIdentity(['IdentityId' => $identity['cognitoIdentityId']]);
        $logins = $identities['Logins'];
        $user = null;
        if (in_array($this->developerLogin, $logins)) {
            $result = $this->cognito->lookupDeveloperIdentity(array(
                // IdentityPoolId is required
                'IdentityPoolId' => $this->identityPoolId,
                'IdentityId' => $identity['cognitoIdentityId'],
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
    }
}
