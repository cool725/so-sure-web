<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Document\User;
use GuzzleHttp\Client;

class DigitsService
{
    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $digitsConsumerKey;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $digitsConsumerKey
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $digitsConsumerKey
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->digitsConsumerKey = $digitsConsumerKey;
    }

    public function validateUser($provider, $credentials)
    {
        // @codingStandardsIgnoreStart
        // https://docs.fabric.io/apple/digits/advanced-setup.html#oauth-echo

        // Validate that the oauth_consumer_key header value in the X-Verify-Credentials-Authorization matches your oauth consumer key, to ensure the user is logging into your site. You can use an oauth library to parse the header and explicitly match the key value, e.g. parse(params['X-Verify-Credentials-Authorization']).oauth_consumer_key=<your oauth consumer key>.
        if (strpos($credentials, sprintf('oauth_consumer_key="%s"', $this->digitsConsumerKey)) === false) {
            throw new \Exception(sprintf(
                'Invalid digits consumer key %s / %s',
                $this->digitsConsumerKey,
                $credentials
            ));
        }
        // Verify the X-Auth-Service-Provider header, by parsing the uri and asserting the domain is api.digits.com, to ensure you are calling Digits.
        if (parse_url($provider, PHP_URL_HOST) != 'api.digits.com') {
            throw new \Exception(sprintf('Invalid digits api host %s', $provider));
        }

        $client = new Client();
        $res = $client->request('GET', $provider, ['headers' => ['Authorization' => $credentials]]);

        // {"phone_number":"+447775740466","access_token":{"token":"719464658130857984-eBlXwMizxBkZ6QomhL5EOgChkuAlGZo","secret":"zSYBM5yYADHFPEzqO80ee9ZlHlFTbz21uJ6sNXTCqHO6F"},"id_str":"719464658130857984","verification_type":"sms","id":719464658130857984,"created_at":"Mon Apr 11 09:58:36 +0000 2016"} [] []
        $body = (string) $res->getBody();
        $this->logger->info(sprintf('Digits response: %s', $body));

        $data = json_decode($body, true);
        $mobileNumber = $data['phone_number'];
        $id = $data['id_str'];
        $verificationType = $data['verification_type'];

        // Validate the response from the verify_credentials call to ensure the user is successfully logged in
        // Assuming the below is a valid verification....
        if (!isset($data['access_token']) || !isset($data['access_token']['token']) ||
            strlen($data['access_token']['token']) < 10) {
            throw new \Exception(sprintf('Access token is not set %s', json_encode($data)));
        }
        if ($verificationType != 'sms') {
            throw new \Exception(sprintf('Unknown digits verification type %s', $verificationType));
        }
        // TODO: Consider adding additional parameters to the signature to tie your app’s own session to the Digits session. Use the alternate form OAuthEchoHeadersToVerifyCredentialsWithParams: to provide additional parameters to include in the OAuth service URL. Verify these parameters are present in the service URL and that the API request succeeds.
        // TODO: Store digits id against user record??

        // @codingStandardsIgnoreEnd

        $repo = $this->dm->getRepository(User::class);
        $user = $repo->findOneBy(['mobileNumber' => $mobileNumber]);

        return $user;
    }
}
