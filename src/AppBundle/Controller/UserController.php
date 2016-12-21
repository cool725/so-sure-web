<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\EmailInvitationType;
use AppBundle\Form\Type\InvitationType;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\FacebookService;
use AppBundle\Security\InvitationVoter;
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
        if (!$user->hasActivePolicy()) {
            return new RedirectResponse($this->generateUrl('user_invalid_policy'));
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }
        $policy = $user->getCurrentPolicy();

        $invitationService = $this->get('app.invitation');
        $emailInvitiation = new EmailInvitation();
        $emailInvitationForm = $this->get('form.factory')
            ->createNamedBuilder('email', EmailInvitationType::class, $emailInvitiation)
            ->getForm();
        $invitationForm = $this->get('form.factory')
            ->createNamedBuilder('invitation', InvitationType::class, $user)
            ->getForm();

        if ($request->request->has('email')) {
            $emailInvitationForm->handleRequest($request);
            if ($emailInvitationForm->isSubmitted() && $emailInvitationForm->isValid()) {
                $invitationService->inviteByEmail($policy, $emailInvitiation->getEmail());
                $this->addFlash(
                    'success',
                    sprintf('%s was invited', $emailInvitiation->getEmail())
                );

                return new RedirectResponse($this->generateUrl('user_home'));
            }
        } elseif ($request->request->has('invitation')) {
            $invitationForm->handleRequest($request);
            if ($invitationForm->isSubmitted() && $invitationForm->isValid()) {
                foreach ($user->getUnprocessedReceivedInvitations() as $invitation) {
                    if ($invitationForm->get(sprintf('accept_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::ACCEPT, $invitation);
                        $invitationService->accept($invitation, $policy);
                        $this->addFlash(
                            'success',
                            sprintf("You're now connected with %s", $invitation->getInviter()->getName())
                        );

                        return new RedirectResponse($this->generateUrl('user_home'));
                    } elseif ($invitationForm->get(sprintf('reject_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::REJECT, $invitation);
                        $invitationService->reject($invitation);
                        $this->addFlash(
                            'warning',
                            sprintf("You've declined the invitation from %s", $invitation->getInviter()->getName())
                        );

                        return new RedirectResponse($this->generateUrl('user_home'));
                    }
                }
            }
        }

        return array(
            'policy' => $user->getCurrentPolicy(),
            'email_form' => $emailInvitationForm->createView(),
            'invitation_form' => $invitationForm->createView(),
        );
    }

    /**
     * @Route("/invalid", name="user_invalid_policy")
     * @Template
     */
    public function invalidPolicyAction()
    {
        $user = $this->getUser();

        // If there are any policies in progress, redirect to the purchase
        $initPolicies = $user->getInitPolicies();
        if (count($initPolicies) > 0) {
            return $this->redirectToRoute('purchase_step_phone');
        }

        return array(
        );
    }

    /**
     * @Route("/unpaid", name="user_unpaid_policy")
     * @Template
     */
    public function unpaidPolicyAction(Request $request)
    {
        $user = $this->getUser();
        $policy = $user->getCurrentPolicy();
        if ($policy->getPremiumPlan() != Policy::PLAN_MONTHLY) {
            throw new \Exception('Unpaid policy should only be triggered for monthly plans');
        }
        $amount = 0;
        $webpay = null;
        if (!$policy->isPolicyPaidToDate()) {
            $amount = $policy->getOutstandingPremiumToDate();

            $webpay = $this->get('app.judopay')->webpay(
                $policy,
                $amount,
                $request->getClientIp(),
                $request->headers->get('User-Agent')
            );
        }

        $data = [
            'phone' => $policy->getPhone(),
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
            'amount' => $amount,
            'policy' => $policy,
        ];

        return $data;
    }

    /**
     * @Route("/card-details", name="user_card_details")
     * @Template
     */
    public function cardDetailsAction(Request $request)
    {
        $user = $this->getUser();
        $webpay = $this->get('app.judopay')->webRegister(
            $user,
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        $data = [
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
            'user' => $user,
        ];

        return $data;
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

        $session = $request->getSession();
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
