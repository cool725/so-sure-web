<?php
namespace AppBundle\Service;

use Facebook\Facebook;
use AppBundle\Document\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FacebookService
{
    /** @var LoggerInterface */
    protected $logger;

    protected $router;

    /** @var string */
    protected $appId;

    /** @var string */
    protected $secret;

    /** @var Facebook */
    protected $fb;

    /**
     * @param LoggerInterface $logger
     * @param                 $router
     * @param string          $apId
     * @param string          $secret
     */
    public function __construct(LoggerInterface $logger, $router, $appId, $secret)
    {
        $this->logger = $logger;
        $this->router = $router;
        $this->appId = $appId;
        $this->secret = $secret;
    }

    /**
     * @param User $user
     *
     * @return Facebook
     */
    public function init(User $user)
    {
        $this->fb = new Facebook([
          'app_id' => $this->appId,
          'app_secret' => $this->secret,
          'default_graph_version' => 'v2.5',
          'default_access_token' => $user->getFacebookAccessToken(),
        ]);

        return $this->fb;
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
            $data['tags'] = $id;
        }

        $this->fb->post('/me/feed', $data);
    }

    /**
     * @param string   $requiredPermission
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
}
