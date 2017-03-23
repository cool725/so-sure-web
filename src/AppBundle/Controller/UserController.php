<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\EmailInvitationType;
use AppBundle\Form\Type\SCodeInvitationType;
use AppBundle\Form\Type\InvitationType;
use AppBundle\Document\Invitation\EmailInvitation;

use AppBundle\Service\FacebookService;
use AppBundle\Security\InvitationVoter;
use AppBundle\Service\MixpanelService;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Facebook\Facebook;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use AppBundle\Exception\DuplicateInvitationException;

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
        $dm = $this->getManager();
        $repo = $dm->getRepository(SCode::class);
        $user = $this->getUser();
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            // mainly for facebook registration, although makes sense for all users
            if ($this->getSessionQuotePhone($request)) {
                // TODO: If possible to detect if the user came via the purchase page or via the login page
                // login page would be nice to add a flash message saying their policy has not yet been purchased
                return new RedirectResponse($this->generateUrl('purchase_step_policy'));
            } else {
                return new RedirectResponse($this->generateUrl('user_invalid_policy'));
            }
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }

        $scode = null;
        $session = $request->getSession();
        if ($code = $session->get('scode')) {
            $scode = $repo->findOneBy(['code' => $code]);
        }

        $policy = $user->getCurrentPolicy();
        $scode = null;
        if ($session = $this->get('session')) {
            $scode = $repo->findOneBy(['code' => $session->get('scode'), 'active' => true]);
        }

        $invitationService = $this->get('app.invitation');
        $emailInvitiation = new EmailInvitation();
        $emailInvitationForm = $this->get('form.factory')
            ->createNamedBuilder('email', EmailInvitationType::class, $emailInvitiation)
            ->getForm();
        $invitationForm = $this->get('form.factory')
            ->createNamedBuilder('invitation', InvitationType::class, $user)
            ->getForm();
        $scodeForm = $this->get('form.factory')
            ->createNamedBuilder('scode', SCodeInvitationType::class, ['scode' => $scode ? $scode->getCode() : null])
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
                        $connection = $invitationService->accept($invitation, $policy);
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
        } elseif ($request->request->has('scode')) {
            $scodeForm->handleRequest($request);
            if ($scodeForm->isSubmitted() && $scodeForm->isValid()) {
                if ($session = $this->get('session')) {
                    $session->remove('scode');
                }

                $code = $scodeForm->getData()['scode'];
                $scode = $repo->findOneBy(['code' => $code]);
                if (!$scode || !SCode::isValidSCode($scode->getCode())) {
                    $this->addFlash(
                        'warning',
                        sprintf("SCode %s is missing or been withdrawn", $code)
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                }

                $policy = $this->getUser()->getCurrentPolicy();
                try {
                    $invitation = $this->get('app.invitation')->inviteBySCode($policy, $code);
                    $message = sprintf(
                        '%s has been invited',
                        $invitation->getInvitee()->getName()
                    );

                    $this->addFlash('success', $message);

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (DuplicateInvitationException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("SCode %s has already been used by you", $code)
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (ConnectedInvitationException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You're already connected")
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (OptOutException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("Sorry, but your friend has opted out of any more invitations")
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (InvalidPolicyException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("Please make sure your policy is paid to date before connecting")
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (SelfInviteException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You cannot invite yourself")
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (FullPotException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You or your friend has a full pot!")
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (ClaimException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You or your friend has a claim.")
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                } catch (NotFoundHttpException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("Not able to find this scode")
                    );

                    return new RedirectResponse($this->generateUrl('user_home'));
                }
            }
        } elseif ($scode) {
            // @codingStandardsIgnoreStart
            $this->addFlash(
                'success',
                sprintf(
                    '%s has invited you to connect. <a href="#" id="scode-link">Connect here!</a>',
                    $scode->getPolicy()->getUser()->__toString()
                )
            );
            // @codingStandardsIgnoreEnd
        }

        return array(
            'policy' => $user->getCurrentPolicy(),
            'email_form' => $emailInvitationForm->createView(),
            'invitation_form' => $invitationForm->createView(),
            'scode_form' => $scodeForm->createView(),
            'scode' => $scode,
        );
    }

    /**
     * @Route("/invalid", name="user_invalid_policy")
     * @Template
     */
    public function invalidPolicyAction()
    {
        $user = $this->getUser();
        if ($user->hasUnpaidPolicy() || $user->hasActivePolicy()) {
            // Causes too much user confusion to hit this page if they do have a policy in any state
            // but better to throw exception as shouldn't be getting here anyway and redirecting
            // could cause redirect loop
            throw new \Exception('Attempting to access invalid policy page with active/unpaid policy');
        }

        // If there are any policies in progress, redirect to the purchase
        $initPolicies = $user->getInitPolicies();
        if (count($initPolicies) > 0) {
            return $this->redirectToRoute('purchase_step_policy');
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
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/welcome", name="user_welcome")
     * @Template
     */
    public function welcomeAction()
    {
        $user = $this->getUser();
        if (!$user->hasActivePolicy()) {
            return new RedirectResponse($this->generateUrl('user_invalid_policy'));
        }

        $countUnprocessedInvitations = count($user->getUnprocessedReceivedInvitations());
        if ($countUnprocessedInvitations > 0) {
            $message = sprintf(
                'Hey, you already have %d invitation%s. 🤗 <a href="#download-apps">Download</a> our app to connect.',
                $countUnprocessedInvitations,
                $countUnprocessedInvitations > 1 ? 's' : ''
            );
            $this->addFlash('success', $message);
        }

        return array(
            'policy_key' => $this->getParameter('policy_key'),
            'policy' => $user->getCurrentPolicy()
        );
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
