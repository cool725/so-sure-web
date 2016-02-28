<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Policy;
use AppBundle\Document\Phone;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @Route("/user")
 */
class UserController extends BaseController
{
    /**
     * @Route("/", name="user_home")
     * @Template
     */
    public function indexAction()
    {
        $addPermission = $this->getFacebookPermission('user_friends', ['user_friends', 'email']);
        if ($addPermission) {
            return $addPermission;
        }

        $response = $fb->get('/me/taggable_friends');
        $friends = $response->getGraphEdge();

        return array(
            'friends' => $friends
        );
    }

    /**
     * @param string $requiredPermission
     * @param array  $allPermissions
     *
     * @return null|RedirectResponse
     */
    private function getFacebookPermission($requiredPermission, $allPermissions)
    {
        $fb = new \Facebook\Facebook([
          'app_id' => $this->getParameter('fb_appid'),
          'app_secret' => $this->getParameter('fb_secret'),
          'default_graph_version' => 'v2.5',
          'default_access_token' => $this->getUser()->getFacebookAccessToken(),
        ]);
        $response = $fb->get('/me/permissions');
        $currentPermissions = $response->getGraphEdge();
        $foundPermission = false;
        foreach ($currentPermissions as $currentPermission) {
            if ($currentPermission['permission'] == $requiredPermission) {
                $foundPermission = true;
            }
        }

        if (!$foundPermision) {
            $helper = $fb->getRedirectLoginHelper();
            $permissions = $allPermissions; // optional
            $redirectUrl = $this->generateUrl('facebook_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $loginUrl = $helper->getLoginUrl($redirectUrl, $permissions);

            return $this->redirect($loginUrl);
        }

        return null;
    }
}
