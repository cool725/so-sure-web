<?php
namespace AppBundle\Service;

use AppBundle\Document\DateTrait;
use AppBundle\Document\PhonePolicy;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Facebook\Facebook;
use Facebook\FacebookSession;
use AppBundle\Document\User;
use FacebookAds\Api;
use FacebookAds\Object\AdAccount;
use FacebookAds\Object\CustomAudience;
use FacebookAds\Object\CustomAudienceMultiKey;
use FacebookAds\Object\Fields\AdAccountFields;
use FacebookAds\Object\Fields\CustomAudienceFields;
use FacebookAds\Object\Fields\CustomAudienceMultikeySchemaFields;
use FacebookAds\Object\Fields\LookalikeSpecFields;
use FacebookAds\Object\LookalikeSpec;
use FacebookAds\Object\Values\CustomAudienceCustomerFileSourceValues;
use FacebookAds\Object\Values\CustomAudienceSubtypes;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use GuzzleHttp\Client;
use AppBundle\Document\PhoneTrait;
use Symfony\Component\Routing\RouterInterface;

class FacebookService
{
    use DateTrait;

    const COUNTRY = 'GB';

    use PhoneTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var RouterInterface */
    protected $router;

    /** @var DocumentManager */
    protected $dm;

    /** @var string */
    protected $appId;

    /** @var string */
    protected $secret;

    /** @var Facebook */
    protected $fb;

    /** @var string */
    protected $accountKitSecret;

    /** @var string */
    protected $adAccountId;

    /** @var string */
    protected $businessId;

    /** @var Api */
    protected $api;

    /** @var string */
    protected $environment;

    /**
     * @param LoggerInterface $logger
     * @param RouterInterface $router
     * @param DocumentManager $dm
     * @param string          $appId
     * @param string          $secret
     * @param string          $accountKitSecret
     * @param string          $businessId
     * @param string          $adAccountId
     * @param string          $systemUserAccessToken
     * @param string          $environment
     */
    public function __construct(
        LoggerInterface $logger,
        RouterInterface $router,
        DocumentManager $dm,
        $appId,
        $secret,
        $accountKitSecret,
        $businessId,
        $adAccountId,
        $systemUserAccessToken,
        $environment
    ) {
        $this->logger = $logger;
        $this->router = $router;
        $this->dm = $dm;
        $this->appId = $appId;
        $this->secret = $secret;
        $this->accountKitSecret = $accountKitSecret;
        $this->businessId = $businessId;
        $this->adAccountId = $adAccountId;
        $this->environment = $environment;

        $this->api = Api::init($appId, $secret, $systemUserAccessToken);
    }

    private function getServerAccessToken()
    {
        // @codingStandardsIgnoreStart
        $url = sprintf(
            'https://graph.facebook.com/oauth/access_token?client_id=%s&client_secret=%s&grant_type=client_credentials',
            $this->appId,
            $this->secret
        );
        // @codingStandardsIgnoreEnd

        $client = new Client();
        $res = $client->request('GET', $url);

        // @codingStandardsIgnoreStart
        // { "id" : <account_kit_user_id>, "access_token" : <account_access_token>, "token_refresh_interval_sec" : <refresh_interval> }
        // @codingStandardsIgnoreEnd
        $body = (string) $res->getBody();
        $this->logger->info(sprintf('Access Token response: %s', $body));
        $data = json_decode($body, true);

        return $data['access_token'];
    }

    /**
     * @param User $user
     *
     * @return Facebook
     */
    public function init(User $user)
    {
        return $this->initToken($user->getFacebookAccessToken());
    }

    /**
     * @param string $token
     *
     * @return Facebook
     */
    public function initToken($token)
    {
        $this->fb = new Facebook([
          'app_id' => $this->appId,
          'app_secret' => $this->secret,
          'default_graph_version' => 'v3.0',
          'default_access_token' => $token,
        ]);

        return $this->fb;
    }

    /**
     * Ensure that token is valid and matches the expected user
     */
    public function validateToken(User $user, $token)
    {
        return $this->validateTokenId($user->getFacebookId(), $token);
    }

    /**
     * Ensure that token is valid and matches the expected id
     */
    public function validateTokenId($id, $token)
    {
        try {
            $this->initToken($token);

            return $this->getUserId() == $id;
        } catch (\Exception $e) {
            $this->logger->error(sprintf(
                'Unable to validate facebook token for fb id %s, Ex: %s',
                $id,
                $e->getMessage()
            ));

            return false;
        }
    }

    public function getUserId()
    {
        $response = $this->fb->get('/me?fields=id');
        $user = $response->getGraphUser();

        return $user['id'];
    }

    /**
     * @param integer $pictureSize
     *
     * @return array
     */
    public function getAllFriends($pictureSize = 150)
    {
        $response = $this->fb->get(sprintf(
            '/me/friends?fields=id,name,picture.width(%d).height(%d)',
            $pictureSize,
            $pictureSize
        ));
        $friends = [];
        $data = $response->getGraphEdge();
        $friends = array_merge($friends, $this->edgeToArray($data));
        while ($data = $this->fb->next($data)) {
            $friends = array_merge($friends, $this->edgeToArray($data));
        }
        usort($friends, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return $friends;
    }

    /**
     * @param array $allPermissions
     *
     * @return string
     */
    public function getPermissionUrl($allPermissions)
    {
        $helper = $this->fb->getRedirectLoginHelper();
        $redirectUrl = $this->router->generate(
            'facebook_login',
            [],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $helper->getLoginUrl($redirectUrl, $allPermissions);
    }

    public function postToFeed($message, $link = 'https://wearesosure.com', $tags = null)
    {
        $data = [
            'message' => $message,
        ];
        if ($link) {
            $data['link'] = $link;
        }
        if ($tags) {
            $data['tags'] = $tags;
        }

        $this->fb->post('/me/feed', $data);
    }

    /**
     * @param string $requiredPermission
     *
     * @return boolean
     */
    public function hasPermission($requiredPermission)
    {
        $response = $this->fb->get('/me/permissions');
        $currentPermissions = $response->getGraphEdge();
        foreach ($currentPermissions as $currentPermission) {
            if ($currentPermission['permission'] == $requiredPermission) {
                return true;
            }
        }

        return false;
    }
    
    private function edgeToArray($edge)
    {
        $arr = [];
        foreach ($edge as $data) {
            $arr[] = $data->asArray();
        }

        return $arr;
    }

    public function monthlyLookalike(\DateTime $date)
    {
        //$startMonth = new \DateTime('2018-01-01');
        $startMonth = $this->startOfMonth($date);
        $endMonth = $this->endOfMonth($date);
        $caUsers = [];

        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $policyRepo->findAllStartedPolicies($startMonth, $endMonth);
        foreach ($policies as $policy) {
            /** @var PhonePolicy $policy */
            $users[] = $policy->getUser();
        }

        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        $users = $userRepo->findBy(['created' => ['$gte' => $startMonth, '$lt' => $endMonth]]);
        foreach ($users as $user) {
            $caUsers[] = $user;
        }

        $customAudienceId = $this->createCustomAudience($date);
        $this->populateCustomAudience($customAudienceId, $caUsers);
        // create lookalike seems to not be working - potentially GB is not the right country code
        // however as can easily be done manually in fb, not worth debugging right now
        // FacebookAds\Http\Exception\AuthorizationException: (#2654) Source is Too Small:
        // Please choose a source that includes at least 100 people in the same country.
        // $this->createLookalike($customAudienceId, $date);
    }

    public function createCustomAudience(\DateTime $date)
    {
        $name = sprintf('%s [%s]', $date->format('M Y'), $this->environment);
        $adAccount = new AdAccount(sprintf('act_%s', $this->adAccountId));
        /** @var CustomAudience $customAudienceResponse */
        $customAudienceResponse = $adAccount->createCustomAudience([], [
            CustomAudienceFields::NAME => sprintf('CA %s', $name),
            CustomAudienceFields::SUBTYPE => CustomAudienceSubtypes::CUSTOM,
            CustomAudienceFields::DESCRIPTION => 'Leads and Users created in so-sure db during month',
            CustomAudienceFields::CUSTOMER_FILE_SOURCE => CustomAudienceCustomerFileSourceValues::USER_PROVIDED_ONLY,
        ]);
        //print_r($customAudienceResponse);
        //print_r($customAudienceResponse->exportAllData());
        return $customAudienceResponse->exportAllData()['id'];
    }

    public function populateCustomAudience($customAudienceId, $users)
    {
        if (count($users) == 0) {
            throw new \Exception('No users available');
        }
        $schema = array(
            CustomAudienceMultikeySchemaFields::FIRST_NAME,
            CustomAudienceMultikeySchemaFields::LAST_NAME,
            CustomAudienceMultikeySchemaFields::EMAIL,
            CustomAudienceMultikeySchemaFields::PHONE,
            CustomAudienceMultikeySchemaFields::COUNTRY,
        );

        $data = [];
        foreach ($users as $user) {
            /** @var User $user */
            $data[] = [
                $user->getFirstName(),
                $user->getLastName(),
                $user->getEmailCanonical(),
                $user->getMobileNumber() ? str_replace('+44', '', $user->getMobileNumber()) : null,
                self::COUNTRY,
            ];
        }
        // print_r($data);
        $audience = new CustomAudienceMultiKey($customAudienceId);
        $resp = $audience->addUsers($data, $schema);

        return $resp;
    }

    public function createLookalike($customAudienceId, \DateTime $date)
    {
        $name = sprintf('%s [%s]', $date->format('M Y'), $this->environment);

        $lookalike = new CustomAudience(null, sprintf('act_%s', $this->adAccountId));
        $lookalike->setData([
            CustomAudienceFields::NAME => sprintf('LA %s', $name),
            CustomAudienceFields::DESCRIPTION => sprintf(
                'Leads and Users created in db during month (based on CA %s)',
                $name
            ),
            CustomAudienceFields::SUBTYPE => CustomAudienceSubtypes::LOOKALIKE,
            CustomAudienceFields::ORIGIN_AUDIENCE_ID => $customAudienceId,
            CustomAudienceFields::LOOKALIKE_SPEC => [
                LookalikeSpecFields::TYPE => 'similarity',
                LookalikeSpecFields::COUNTRY => self::COUNTRY,
            ],
        ]);
        $lookalike->create();
    }

    public function getAccountKitMobileNumber($authorizationCode)
    {
        $userData = $this->getAccountKitUserData($authorizationCode);

        return $this->getAccountKitMobileNumberFromUserData($userData);
    }

    public function getAccountKitUserData($authorizationCode)
    {
        $accessToken = $this->getAccountKitAccessToken($authorizationCode);

        return $this->getAccountKitUserDataFromToken($accessToken);
    }

    public function getAccountKitAccessToken($authorizationCode)
    {
        // @codingStandardsIgnoreStart
        $url = sprintf(
            'https://graph.accountkit.com/v1.2/access_token?grant_type=authorization_code&code=%s&access_token=AA|%s|%s',
            $authorizationCode,
            $this->appId,
            $this->accountKitSecret
        );
        // @codingStandardsIgnoreEnd

        $client = new Client();
        $res = $client->request('GET', $url);

        // @codingStandardsIgnoreStart
        // { "id" : <account_kit_user_id>, "access_token" : <account_access_token>, "token_refresh_interval_sec" : <refresh_interval> }
        // @codingStandardsIgnoreEnd
        $body = (string) $res->getBody();
        $this->logger->info(sprintf('Account Kit response: %s', $body));
        $data = json_decode($body, true);

        return $data['access_token'];
    }

    public function getAccountKitUserDataFromToken($accessToken)
    {
        $appSecretProof = hash_hmac('sha256', $accessToken, $this->accountKitSecret);
        $url = sprintf(
            'https://graph.accountkit.com/v1.2/me?access_token=%s&appsecret_proof=%s',
            $accessToken,
            $appSecretProof
        );
        $client = new Client();
        $res = $client->request('GET', $url);

        // @codingStandardsIgnoreStart
        // {  "id":"12345", "phone":{  "number":"+15551234567", "country_prefix": "1", "national_number": "5551234567" } }
        // @codingStandardsIgnoreEnd
        $body = (string) $res->getBody();
        $this->logger->info(sprintf('Account Kit response: %s', $body));
        $data = json_decode($body, true);

        return $data;
    }

    public function getAccountKitMobileNumberFromUserData($userData)
    {
        if (isset($userData['phone']['number'])) {
            return $this->normalizeUkMobile($userData['phone']['number']);
        }

        return null;
    }
}
