<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Phone;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\EmailInvitationType;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\FacebookService;
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
    public function indexAction(Request $request)
    {
        $user = $this->getUser();
        if (!$user->hasValidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_invalid_policy'));
        }
        $policy = $user->getCurrentPolicy();

        $emailInvitiation = new EmailInvitation();
        $emailInvitationForm = $this->get('form.factory')
            ->createNamedBuilder('email', EmailInvitationType::class, $emailInvitiation)
            ->getForm();

        if ($request->request->has('email')) {
            $emailInvitationForm->handleRequest($request);
            if ($emailInvitationForm->isSubmitted() && $emailInvitationForm->isValid()) {
                $invitationService = $this->get('app.invitation');
                $invitationService->inviteByEmail($policy, $emailInvitiation->getEmail());
                $this->addFlash(
                    'success',
                    sprintf('%s was invited', $emailInvitiation->getEmail())
                );

                return new RedirectResponse($this->generateUrl('user_home'));
            }
        }

        return array(
            'policy' => $user->getCurrentPolicy(),
            'email_form' => $emailInvitationForm->createView(),
        );
    }

    /**
     * @Route("/invalid", name="user_invalid_policy")
     * @Template
     */
    public function invalidPolicyAction()
    {
        return array(
        );
    }

    /**
     * @Route("/fb", name="user_facebook")
     * @Template
     */
    public function fbAction()
    {
        throw $this->createAccessDeniedException('Coming soon');

        $facebook = $this->get('app.facebook');
        $facebook->init($this->getUser());
        if ($redirect = $this->ensureFacebookPermission(
            $facebook,
            'publish_actions',
            ['user_friends', 'email', 'publish_actions']
        )) {
            return $redirect;
        }

        $session = new Session();
        if (!$friends = $session->get('friends')) {
            $friends = $facebook->getAllFriends();
            $session->set('friends', $friends);
        }

        return array(
            'friends' => $friends
        );
    }

    /**
     * @Route("/post/{id}", name="user_post")
     * @Template
     */
    public function postAction($id)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $facebook = $this->get('app.facebook');
        $facebook->init($this->getUser());
        $facebook->postToFeed(
            "I've insured my phone with so-sure, the social insurance provider.",
            'https://wearesosure.com',
            $id
        );

        return new RedirectResponse($this->generateUrl('user_home'));
    }

    /**
     * @Route("/trust/{id}", name="user_trust")
     * @Template
     */
    public function trustAction($id)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $facebook = $this->get('app.facebook');
        $fb = $facebook->init($this->getUser());
        $fbNamespace = $this->getParameter('fb_og_namespace');
        $fb->post(sprintf('/me/%s:trust', $fbNamespace), [ 'profile' => $id ]);

        return new RedirectResponse($this->generateUrl('user_home'));
    }

    /**
     * @param Facebook $fb
     * @param string   $requiredPermission
     * @param array    $allPermissions
     *
     * @return null|RedirectResponse
     */
    private function ensureFacebookPermission(FacebookService $fb, $requiredPermission, $allPermissions)
    {
        if ($fb->hasPermission($requiredPermission)) {
            return null;
        }

        return $this->redirect($fb->getPermissionUrl($allPermissions));
    }
}
