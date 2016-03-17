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
use Facebook\Facebook;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RedirectResponse;

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
        $fb = $this->getFacebook();
        $addPermission = $this->getFacebookPermission(
            $fb,
            'user_friends',
            ['user_friends', 'email', 'publish_actions']
        );
        if ($addPermission) {
            return $addPermission;
        }

        $session = new Session();
        if (!$friends = $session->get('friends')) {
            $response = $fb->get('/me/taggable_friends?fields=id,name,picture.width(150).height(150)');
            $friends = [];
            $data = $response->getGraphEdge();
            $friends = array_merge($friends, $this->edgeToArray($data));
            while ($data = $fb->next($data)) {
                $friends = array_merge($friends, $this->edgeToArray($data));
            }
            $session->set('friends', $friends);
        }
        usort($friends, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return array(
            'friends' => $friends
        );
    }

    /**
     * @Route("/trust/{id}", name="user_trust")
     * @Template
     */
    public function trustAction($id)
    {
        $fb = $this->getFacebook();
        $fb->post('/me/{{ fb_og_namespace }}:trust', [ 'profile' => $id ]);

        return new RedirectResponse($this->generateUrl('user_home'));
    }

    private function edgeToArray($edge)
    {
        $arr = [];
        foreach ($edge as $data) {
            $arr[] = $data->asArray();
        }

        return $arr;
    }

    private function getFacebook()
    {
        $fb = new Facebook([
          'app_id' => $this->getParameter('fb_appid'),
          'app_secret' => $this->getParameter('fb_secret'),
          'default_graph_version' => 'v2.5',
          'default_access_token' => $this->getUser()->getFacebookAccessToken(),
        ]);

        return $fb;
    }

    /**
     * @param Facebook $fb
     * @param string   $requiredPermission
     * @param array    $allPermissions
     *
     * @return null|RedirectResponse
     */
    private function getFacebookPermission(Facebook $fb, $requiredPermission, $allPermissions)
    {
        $response = $fb->get('/me/permissions');
        $currentPermissions = $response->getGraphEdge();
        $foundPermission = false;
        foreach ($currentPermissions as $currentPermission) {
            if ($currentPermission['permission'] == $requiredPermission) {
                $foundPermission = true;
            }
        }

        if (!$foundPermission) {
            $helper = $fb->getRedirectLoginHelper();
            $permissions = $allPermissions; // optional
            $redirectUrl = $this->generateUrl('facebook_login', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $loginUrl = $helper->getLoginUrl($redirectUrl, $permissions);

            return $this->redirect($loginUrl);
        }

        return null;
    }
}
