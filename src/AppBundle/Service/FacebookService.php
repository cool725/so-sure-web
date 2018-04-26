<?php
namespace AppBundle\Service;

use Facebook\Facebook;
use Facebook\FacebookSession;
use AppBundle\Document\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use GuzzleHttp\Client;
use AppBundle\Document\PhoneTrait;
use Symfony\Component\Routing\RouterInterface;

class FacebookService
{
    use PhoneTrait;

    /** @var LoggerInterface */
    protected $logger;

    /** @var RouterInterface */
    protected $router;

    /** @var string */
    protected $appId;

    /** @var string */
    protected $secret;

    /** @var Facebook */
    protected $fb;

    /** @var string */
    protected $accountKitSecret;

    /**
     * @param LoggerInterface $logger
     * @param RouterInterface $router
     * @param string          $appId
     * @param string          $secret
     * @param string          $accountKitSecret
     */
    public function __construct(
        LoggerInterface $logger,
        RouterInterface $router,
        $appId,
        $secret,
        $accountKitSecret
    ) {
        $this->logger = $logger;
        $this->router = $router;
        $this->appId = $appId;
        $this->secret = $secret;
        $this->accountKitSecret = $accountKitSecret;
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
          'default_graph_version' => 'v2.12',
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
            '/me/taggable_friends?fields=id,name,picture.width(%d).height(%d)',
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
