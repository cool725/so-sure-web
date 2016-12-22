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
use AppBundle\Form\Type\InvitationType;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Service\FacebookService;
use AppBundle\Security\InvitationVoter;
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
            'token' => $this->get('form.csrf_provider')->generateCsrfToken('default'),
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
     * @Route("/scode/{code}", name="user_scode")
     * @Method({"POST"})
     */
    public function scodeAction(Request $request, $code)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Please reload this page and try again',
                404
            );
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(SCode::class);
        $scode = $repo->findOneBy(['code' => $code]);
        if (!$scode || !SCode::isValidSCode($scode->getCode())) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'SCode is missing or been withdrawn',
                404
            );
        }

        $policy = $this->getUser()->getCurrentPolicy();
        try {
            $invitation = $this->get('app.invitation')->inviteBySCode($policy, $code);
            $message = sprintf(
                '%s has been invited',
                $invitation->getInvitee()->getName()
            );

            $this->addFlash('success', $message);

            return $this->getSuccessJsonResponse($message);
        } catch (DuplicateInvitationException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_DUPLICATE,
                'Looks like you alredy entered this code',
                422
            );
        } catch (ConnectedInvitationException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_CONNECTED,
                'Looks like you are already connected',
                422
            );
        } catch (OptOutException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_OPTOUT,
                'Sorry, but that person does not want to connect anymore',
                422
            );
        } catch (InvalidPolicyException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_POLICY_PAYMENT_REQUIRED,
                'Please make sure your policy is paid before connecting',
                422
            );
        } catch (SelfInviteException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_SELF_INVITATION,
                'Sorry, you can not connect with yourself',
                422
            );
        } catch (FullPotException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_MAXPOT,
                'Sorry, but either you or your connection has a full pot and can not connect anymore',
                422
            );
        } catch (ClaimException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_INVITATION_POLICY_HAS_CLAIM,
                'Sorry, but you are unable to connect with this user right now',
                422
            );
        } catch (NotFoundHttpException $e) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_NOT_FOUND,
                'Unable to find policy/code',
                404
            );
        } catch (\Exception $e) {
            $this->get('logger')->error('Error in api newInvitation.', ['exception' => $e]);

            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Server Error', 500);
        }
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
