<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\User;
use AppBundle\Document\PhoneTrait;
use GuzzleHttp\Client;

class DigitsService
{
    use PhoneTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $digitsConsumerKey;

    protected $allowedDigitsConsumerKeys;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $digitsConsumerKey
     * @param string          $allowedDigitsConsumerKeys
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $digitsConsumerKey,
        $allowedDigitsConsumerKeys
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->digitsConsumerKey = $digitsConsumerKey;
        $this->allowedDigitsConsumerKeys = explode(',', $allowedDigitsConsumerKeys);
    }

    public function validateUser($provider, $credentials, $cognitoId = null)
    {
        // https://docs.fabric.io/apple/digits/advanced-setup.html#oauth-echo

        // @codingStandardsIgnoreStart
        // Validate that the oauth_consumer_key header value in the X-Verify-Credentials-Authorization matches your oauth consumer key, to ensure the user is logging into your site. You can use an oauth library to parse the header and explicitly match the key value, e.g. parse(params['X-Verify-Credentials-Authorization']).oauth_consumer_key=<your oauth consumer key>.
        // @codingStandardsIgnoreEnd
        $foundConsumerKey = false;
        if (preg_match("/oauth_consumer_key=\"([^\"]*)\"/", $credentials, $matches) && isset($matches[1])) {
            $foundConsumerKey = in_array($matches[1], $this->allowedDigitsConsumerKeys);
        }
        if (!$foundConsumerKey) {
            $message = sprintf(
                'Invalid digits consumer key %s / %s',
                $this->digitsConsumerKey,
                $credentials
            );
            $this->logger->warning($message);

            throw new \Exception($message);
        }

        // @codingStandardsIgnoreStart
        // Verify the X-Auth-Service-Provider header, by parsing the uri and asserting the domain is api.digits.com, to ensure you are calling Digits.
        // @codingStandardsIgnoreEnd
        if (parse_url($provider, PHP_URL_HOST) != 'api.digits.com') {
            throw new \Exception(sprintf('Invalid digits api host %s', $provider));
        }
        
        $queryData = [];
        $querystring = parse_str(parse_url($provider, PHP_URL_QUERY), $queryData);

        $client = new Client();
        $res = $client->request('GET', $provider, ['headers' => ['Authorization' => $credentials]]);

        // @codingStandardsIgnoreStart
        // {"phone_number":"+447775740466","access_token":{"token":"719464658130857984-eBlXwMizxBkZ6QomhL5EOgChkuAlGZo","secret":"zSYBM5yYADHFPEzqO80ee9ZlHlFTbz21uJ6sNXTCqHO6F"},"id_str":"719464658130857984","verification_type":"sms","id":719464658130857984,"created_at":"Mon Apr 11 09:58:36 +0000 2016"} [] []
        // @codingStandardsIgnoreEnd
        $body = (string) $res->getBody();
        $this->logger->info(sprintf('Digits response: %s', $body));

        $data = json_decode($body, true);
        $mobileNumber = $this->normalizeUkMobile($data['phone_number']);
        $id = $data['id_str'];
        $verificationType = $data['verification_type'];

        // Validate the response from the verify_credentials call to ensure the user is successfully logged in
        // Assuming the below is a valid verification....
        if (!isset($data['access_token']) || !isset($data['access_token']['token']) ||
            strlen($data['access_token']['token']) < 10) {
            throw new \Exception(sprintf('Access token is not set %s', json_encode($data)));
        }
        if (!in_array($verificationType, ['sms', 'voicecall'])) {
            throw new \Exception(sprintf('Unknown digits verification type %s', $verificationType));
        }

        $repo = $this->dm->getRepository(User::class);
        $user = $repo->findOneBy(['digitsId' => $id]);
        if (!$user) {
            $user = $repo->findOneBy(['mobileNumber' => $mobileNumber]);

            if (!$user) {
                return null;
            } elseif (!$user->getDigitsId()) {
                // First time login, so we should store the digits id against the user record
                $user->setDigitsId($id);
                $user->setMobileNumberVerified(true);
                $this->dm->flush();
            } elseif ($user->getDigitsId() != $id) {
                throw new \Exception(sprintf(
                    'User %s has a different digits id [%s/%s]',
                    $user->getId(),
                    $id,
                    $mobileNumber
                ));
            }
        } elseif ($user->getMobileNumber() != $mobileNumber) {
            // Digits user exists, but has different registered mobile number to policy
            $this->logger->warning(sprintf(
                'User %s has a different digits mobile number %s',
                $user->getId(),
                $mobileNumber
            ));
        } else {
            // new digits user above will now have mobile verified set, but for older users, set if they login again
            if (!$user->getMobileNumberVerified()) {
                $user->setMobileNumberVerified(true);
                $this->dm->flush();
            }            
        }

        // Moved to last to help with debugging - onced resolved, could be place earlier in process
        // @codingStandardsIgnoreStart
        // Consider adding additional parameters to the signature to tie your app’s own session to the Digits session. Use the alternate form OAuthEchoHeadersToVerifyCredentialsWithParams: to provide additional parameters to include in the OAuth service URL. Verify these parameters are present in the service URL and that the API request succeeds.
        // @codingStandardsIgnoreEnd
        if ($cognitoId) {
            if (!isset($queryData['identity_id']) || $cognitoId != $queryData['identity_id']) {
                // TODO: Once we figure out why this is occurring, change back to an exception
                $this->logger->warning(sprintf(
                    'Cognito Id %s does not match session url %s. UserId: %s Digits: %s',
                    $cognitoId,
                    $provider,
                    $user ? $user->getId() : 'unknown',
                    json_encode($data)
                ));
            }
        }

        return $user;
    }
}
