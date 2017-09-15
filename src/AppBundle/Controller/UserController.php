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
use AppBundle\Document\Cashback;
use AppBundle\Document\Feature;
use AppBundle\Document\Form\Renew;
use AppBundle\Document\Form\RenewCashback;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\EmailInvitationType;
use AppBundle\Form\Type\UserEmailType;
use AppBundle\Form\Type\SCodeInvitationType;
use AppBundle\Form\Type\InvitationType;
use AppBundle\Form\Type\RenewType;
use AppBundle\Form\Type\RenewCashbackType;
use AppBundle\Form\Type\CashbackType;
use AppBundle\Form\Type\SentInvitationType;
use AppBundle\Form\Type\UnconnectedUserPolicyType;
use AppBundle\Form\Type\RenewConnectionsType;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Form\BillingDay;
use AppBundle\Form\Type\BillingDayType;

use AppBundle\Service\FacebookService;
use AppBundle\Security\InvitationVoter;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\JudopayService;

use AppBundle\Security\PolicyVoter;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Facebook\Facebook;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use AppBundle\Exception\DuplicateInvitationException;
use AppBundle\Exception\ValidationException;

/**
 * @Route("/user")
 */
class UserController extends BaseController
{
    /**
     * @Route("/", name="user_home")
     * @Route("/{policyId}", name="user_policy", requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function indexAction(Request $request, $policyId = null)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $scodeRepo = $dm->getRepository(SCode::class);
        $user = $this->getUser();
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            // mainly for facebook registration, although makes sense for all users
            // check for canPurchasePolicy is necessary to prevent redirect loop
            if ($this->getSessionQuotePhone($request) && $user->canPurchasePolicy()) {
                // TODO: If possible to detect if the user came via the purchase page or via the login page
                // login page would be nice to add a flash message saying their policy has not yet been purchased
                return new RedirectResponse($this->generateUrl('purchase_step_policy'));
            } else {
                return new RedirectResponse($this->generateUrl('user_invalid_policy'));
            }
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }

        if ($policyId) {
            $policy = $policyRepo->find($policyId);
        } else {
            $policy = $user->getLatestPolicy();
        }
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

        if ($policy->getStatus() == Policy::STATUS_RENEWAL) {
            return new RedirectResponse(
                $this->generateUrl('user_renew_policy', ['id' => $policy->getPreviousPolicy()->getId()])
            );
        }

        $renewMessage = false;
        foreach ($user->getValidPolicies(true) as $checkPolicy) {
            if ($checkPolicy->notifyRenewal() && !$checkPolicy->isRenewed() && !$checkPolicy->hasCashback()) {
                $this->addFlash(
                    'success',
                    sprintf(
                        '%s is ready for <a href="%s">renewal</a>',
                        $checkPolicy->getPolicyNumber(),
                        $this->generateUrl('user_renew_policy', ['id' => $checkPolicy->getId()])
                    )
                );
                $renewMessage = true;
            }
        }
        if (!$renewMessage) {
            $this->addCashbackFlash();
        }

        $scode = null;
        if ($session = $this->get('session')) {
            $scode = $scodeRepo->findOneBy(['code' => $session->get('scode'), 'active' => true]);
        }

        $invitationService = $this->get('app.invitation');
        $emailInvitiation = new EmailInvitation();
        $emailInvitationForm = $this->get('form.factory')
            ->createNamedBuilder('email', EmailInvitationType::class, $emailInvitiation)
            ->getForm();
        $invitationForm = $this->get('form.factory')
            ->createNamedBuilder('invitation', InvitationType::class, $user)
            ->getForm();
        $sentInvitationForm = $this->get('form.factory')
            ->createNamedBuilder('sent_invitation', SentInvitationType::class, $policy)
            ->getForm();
        $scodeForm = $this->get('form.factory')
            ->createNamedBuilder('scode', SCodeInvitationType::class, ['scode' => $scode ? $scode->getCode() : null])
            ->getForm();
        $unconnectedUserPolicyForm = $this->get('form.factory')
            ->createNamedBuilder('unconnectedPolicy', UnconnectedUserPolicyType::class, $policy)
            ->getForm();

        if ($request->request->has('email')) {
            $emailInvitationForm->handleRequest($request);
            if ($emailInvitationForm->isSubmitted() && $emailInvitationForm->isValid()) {
                $invitationService->inviteByEmail($policy, $emailInvitiation->getEmail());
                $this->addFlash(
                    'success',
                    sprintf('%s was invited', $emailInvitiation->getEmail())
                );

                return new RedirectResponse($this->generateUrl('user_policy', ['policyId' => $policy->getId()]));
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

                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                        );
                    } elseif ($invitationForm->get(sprintf('reject_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::REJECT, $invitation);
                        $invitationService->reject($invitation);
                        $this->addFlash(
                            'warning',
                            sprintf("You've declined the invitation from %s", $invitation->getInviter()->getName())
                        );

                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                        );
                    }
                }
            }
        } elseif ($request->request->has('sent_invitation')) {
            $sentInvitationForm->handleRequest($request);
            if ($sentInvitationForm->isSubmitted() && $sentInvitationForm->isValid()) {
                foreach ($policy->getSentInvitations() as $invitation) {
                    if ($sentInvitationForm->get(sprintf('reinvite_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::REINVITE, $invitation);
                        $connection = $invitationService->reinvite($invitation);
                        $this->addFlash(
                            'success',
                            sprintf("Re-sent invitation to %s", $invitation->getInviteeName())
                        );

                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                        );
                    } elseif ($sentInvitationForm->get(sprintf('cancel_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::CANCEL, $invitation);
                        $invitationService->cancel($invitation);
                        $this->addFlash(
                            'warning',
                            sprintf("Cancelled invitation to %s", $invitation->getInviteeName())
                        );

                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                        );
                    }
                }
            }
        } elseif ($request->request->has('unconnectedPolicy')) {
            $unconnectedUserPolicyForm->handleRequest($request);
            if ($unconnectedUserPolicyForm->isSubmitted() && $unconnectedUserPolicyForm->isValid()) {
                foreach ($policy->getUnconnectedUserPolicies() as $unconnectedPolicy) {
                    $buttonName = sprintf('connect_%s', $unconnectedPolicy->getId());
                    if ($unconnectedUserPolicyForm->get($buttonName)->isClicked()) {
                        $invitationService->connect($policy, $unconnectedPolicy);
                        $this->addFlash(
                            'success',
                            sprintf("You're now connected with %s", $unconnectedPolicy->getDefaultName())
                        );

                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                        );
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
                $scode = $scodeRepo->findOneBy(['code' => $code]);
                if (!$scode || !SCode::isValidSCode($scode->getCode())) {
                    $this->addFlash(
                        'warning',
                        sprintf("SCode %s is missing or been withdrawn", $code)
                    );

                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                        );
                }

                try {
                    $invitation = $this->get('app.invitation')->inviteBySCode($policy, $code);
                    if ($invitation) {
                        $message = sprintf(
                            '%s has been invited',
                            $invitation->getInvitee()->getName()
                        );
                    } else {
                        $message = sprintf(
                            'Your bonus has been added'
                        );
                    }
                    $this->addFlash('success', $message);

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (DuplicateInvitationException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("SCode %s has already been used by you", $code)
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (ConnectedInvitationException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You're already connected")
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (OptOutException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("Sorry, but your friend has opted out of any more invitations")
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (InvalidPolicyException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("Please make sure your policy is paid to date before connecting")
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (SelfInviteException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You cannot invite yourself")
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (FullPotException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You or your friend has a full pot!")
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (ClaimException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("You or your friend has a claim.")
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (NotFoundHttpException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("Not able to find this scode")
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                }
            }
        } elseif ($scode) {
            if ($scode->isStandard()) {
                $this->addFlash(
                    'success',
                    sprintf(
                        '%s has invited you to connect. <a href="#" id="scode-link">Connect here!</a>',
                        $scode->getUser()->getName()
                    )
                );
            } elseif ($scode->isReward()) {
                $this->addFlash(
                    'success',
                    sprintf(
                        'Get your £%0.2f reward bonus from %s. <a href="#" id="scode-link">Connect here!</a>',
                        $scode->getReward()->getDefaultValue(),
                        $scode->getUser()->getName()
                    )
                );
            }
        }

        $sixpack = $this->get('app.sixpack');
        $shareExperiment = $sixpack->participate(
            SixpackService::EXPERIMENT_SHARE_MESSAGE,
            [
                SixpackService::ALTERNATIVES_SHARE_MESSAGE_SIMPLE,
                SixpackService::ALTERNATIVES_SHARE_MESSAGE_ORIGINAL
            ],
            false,
            1,
            $policy->getStandardSCode()->getCode()
        );
        $shareExperimentText = $sixpack->getText(
            SixpackService::EXPERIMENT_SHARE_MESSAGE,
            $shareExperiment,
            [$policy->getStandardSCode()->getShareLink(), $policy->getStandardSCode()->getCode()]
        );

        return array(
            'policy' => $policy,
            'email_form' => $emailInvitationForm->createView(),
            'invitation_form' => $invitationForm->createView(),
            'sent_invitation_form' => $sentInvitationForm->createView(),
            'scode_form' => $scodeForm->createView(),
            'scode' => $scode,
            'unconnected_user_policy_form' => $unconnectedUserPolicyForm->createView(),
            'share_experiment_text' => $shareExperimentText,
        );
    }

    /**
     * @Route("/repurchase/{id}", name="user_repurchase_policy")
     */
    public function repurchasePolicyAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $this->denyAccessUnlessGranted(PolicyVoter::REPURCHASE, $policy);

        $policyService = $this->get('app.policy');
        $newPolicy = $policyService->repurchase($policy);

        return $this->redirectToRoute('purchase_step_policy_id', ['id' => $newPolicy->getId()]);
        // TODO: Find duplicate pending policy
        /*
        if ($policy->hasCashback()) {
            return $this->redirectToRoute('user_renew_only_cashback', ['id' => $id]);
        }
        */


    }

    /**
     * @Route("/renew", name="user_renew_policy_any")
     * @Route("/renew/{id}", name="user_renew_policy")
     * @Template
     */
    public function renewPolicyAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);

        if (!$id) {
            foreach ($this->getUser()->getPolicies() as $policy) {
                if ($policy->canRenew()) {
                    $id = $policy->getId();
                    break;
                }
            }
        }

        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        if ($policy->isRenewed()) {
            return $this->redirectToRoute('user_renew_completed', ['id' => $id]);
        } elseif ($policy->hasCashback()) {
            return $this->redirectToRoute('user_renew_only_cashback', ['id' => $id]);
        }

        $this->denyAccessUnlessGranted(PolicyVoter::RENEW, $policy);

        // TODO: Determine if policy is the old policy or an unpaid renewal
        $renew = new Renew();
        $renew->setPolicy($policy);
        $renew->useSimpleAmount();
        $renewForm = $this->get('form.factory')
            ->createNamedBuilder('renew_form')
            ->add('renew', SubmitType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('renew_form')) {
                $renewForm->handleRequest($request);
                if ($renewForm->isValid()) {
                    // TODO: If old policy
                    $policyService = $this->get('app.policy');
                    if (!$renew->getUsePot()) {
                        throw new \Exception(sprintf('Renew should be using pot'));
                    }
                    $renewalPolicy = $policyService->renew($policy, $renew->getNumPayments());
                    $message = sprintf(
                        'Thanks. Your policy is now scheduled to be renewed on %s',
                        $renewalPolicy->getStart()->format('d M Y')
                    );
                    $this->addFlash('success', $message);

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $renewalPolicy->getId()])
                    );
                } else {
                    $this->addFlash(
                        'error',
                        sprintf(
                            "Sorry, there's a problem renewing your policy. Please try again or contact us. %s",
                            $renewForm->getErrors()
                        )
                    );
                    return new RedirectResponse(
                        $this->generateUrl('user_renew_policy', ['id' => $id])
                    );
                }
            }
        }
        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_RENEWAL, [
            'Renew Type' => 'Quick',
        ]);

        return [
            'policy' => $policy,
            'phone' => $policy->getPhone(),
            'policy_key' => $this->getParameter('policy_key'),
            'renew_form' => $renewForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'renew' => $renew,
        ];
    }

    /**
     * @Route("/renew/{id}/custom", name="user_renew_custom_policy")
     * @Route("/renew/{id}/retry", name="user_renew_retry_policy")
     * @Template
     */
    public function renewPolicyCustomAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        if ($request->get('_route') != 'user_renew_retry_policy') {
            if ($policy->isRenewed()) {
                return $this->redirectToRoute('user_renew_completed', ['id' => $id]);
            } elseif ($policy->hasCashback()) {
                return $this->redirectToRoute('user_renew_only_cashback', ['id' => $id]);
            }
        }

        $this->denyAccessUnlessGranted(PolicyVoter::RENEW, $policy);

        // TODO: Determine if policy is the old policy or an unpaid renewal
        $renew = new Renew();
        $renew->setPolicy($policy);
        $renew->setCustom(true);
        $renew->useSimpleAmount();
        $renewForm = $this->get('form.factory')
            ->createNamedBuilder('renew_form', RenewType::class, $renew)
            ->getForm();
        $renewCashback = new RenewCashback();
        $renewCashback->setPolicy($policy);
        $renewCashback->setCustom(true);
        $renewCashback->useDefaultAmount();
        $renewCashbackForm = $this->get('form.factory')
            ->createNamedBuilder('renew_cashback_form', RenewCashbackType::class, $renewCashback)
            ->getForm();
        $cashback = new Cashback();
        $cashback->setDate(new \DateTime());
        $cashback->setPolicy($policy);
        $cashback->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $cashbackForm = $this->get('form.factory')
            ->createNamedBuilder('cashback_form', CashbackType::class, $cashback)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('renew_form')) {
                $renewForm->handleRequest($request);
                if ($renewForm->isValid()) {
                    // TODO: If old policy
                    $policyService = $this->get('app.policy');
                    if (!$renew->getUsePot()) {
                        throw new \Exception(sprintf('Renew should be using pot'));
                    }
                    $renewalPolicy = $policyService->renew($policy, $renew->getNumPayments());
                    $message = sprintf(
                        'Thanks. Your policy is now scheduled to be renewed on %s',
                        $renewalPolicy->getStart()->format('d M Y')
                    );
                    $this->addFlash('success', $message);

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $renewalPolicy->getId()])
                    );
                } else {
                    $this->addFlash(
                        'error',
                        sprintf(
                            "Sorry, there's a problem renewing your policy. Please try again or contact us. %s",
                            $renewForm->getErrors()
                        )
                    );
                }
            } elseif ($request->request->has('renew_cashback_form')) {
                $renewCashbackForm->handleRequest($request);
                try {
                    if ($renewCashbackForm->isValid()) {
                        $cashbackFromRenewal = $renewCashback->createCashback();
                        $this->validateObject($cashbackFromRenewal);

                        // TODO: If old policy
                        $policyService = $this->get('app.policy');
                        $renewalPolicy = $policyService->renew(
                            $policy,
                            $renewCashback->getNumPayments(),
                            $cashbackFromRenewal
                        );
                        $message = sprintf(
                            'Thanks. Your policy is now scheduled to be renewed on %s',
                            $renewalPolicy->getStart()->format('d M Y')
                        );
                        $this->addFlash('success', $message);

                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $renewalPolicy->getId()])
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            sprintf(
                                "Sorry, there's a problem renewing your policy. Please try again or contact us. %s",
                                $renewCashbackForm->getErrors()
                            )
                        );
                    }
                } catch (ValidationException $e) {
                    /*
                    $this->addFlash(
                        'error',
                        sprintf(
                            "Sorry, there's a problem renewing your policy. Please try again or contact us. %s",
                            $e->getMessage()
                        )
                    );
                    */
                    $this->addFlash(
                        'error',
                        sprintf(
                            "Sorry, there's a problem renewing your policy. Please try again or contact us."
                        )
                    );
                }
            } elseif ($request->request->has('cashback_form')) {
                $cashbackForm->handleRequest($request);
                if ($cashbackForm->isValid()) {
                    $policyService = $this->get('app.policy');
                    $policyService->cashback($policy, $cashback);

                    $message = sprintf(
                        'Your request for cashback has been accepted.'
                    );
                    $this->addFlash('success', $message);

                    return new RedirectResponse(
                        $this->generateUrl('user_renew_custom_policy', ['id' => $id])
                    );
                } else {
                    $this->addFlash(
                        'error',
                        sprintf(
                            "Sorry, there's a problem requesting cashback. Please try again or contact us. %s",
                            $cashbackForm->getErrors()
                        )
                    );
                }
            }
        }

        if ($request->get('_route') != 'user_renew_retry_policy') {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_RENEWAL, [
                'Renew Type' => 'Custom',
            ]);
        } else {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_RENEWAL, [
                'Renew Type' => 'Second Chance',
            ]);
        }

        return [
            'policy' => $policy,
            'phone' => $policy->getPhone(),
            'policy_key' => $this->getParameter('policy_key'),
            'renew_form' => $renewForm->createView(),
            'renew_cashback_form' => $renewCashbackForm->createView(),
            'cashback_form' => $cashbackForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'renew' => $renew,
        ];
    }

    /**
     * @Route("/renew/{id}/complete", name="user_renew_completed")
     * @Template
     */
    public function renewPolicyCompleteAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        if (!$policy->isRenewed()) {
            return $this->redirectToRoute('user_renew_policy', ['id' => $id]);
        }
        $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

        $renewConnectionsForm = $this->get('form.factory')
            ->createNamedBuilder('renew_connections_form', RenewConnectionsType::class, $policy->getNextPolicy())
            ->getForm();
        if ($request->request->has('renew_connections_form')) {
            $renewConnectionsForm->handleRequest($request);
            if ($renewConnectionsForm->isValid()) {
                $dm->flush();
                $this->addFlash('success', 'Your connections have been updated');

                return new RedirectResponse(
                    $this->generateUrl('user_home')
                );
            } else {
                $this->addFlash(
                    'error',
                    sprintf(
                        "Sorry, there's a problem updating your connections. Please try again or contact us. %s",
                        $renewConnectionsForm->getErrors()
                    )
                );
            }
        }

        return [
            'policy' => $policy,
            'renew_connections_form' => $renewConnectionsForm->createView(),
        ];
    }

    /**
     * @Route("/renew/{id}/only-cashback", name="user_renew_only_cashback")
     * @Template
     */
    public function renewPolicyOnlyCashbackAction($id)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        if (!$policy->hasCashback()) {
            return $this->redirectToRoute('user_renew_policy', ['id' => $id]);
        }
        $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

        return [
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/cashback/{id}", name="user_cashback")
     * @Template
     */
    public function cashbackAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $cashbackRepo = $dm->getRepository(Cashback::class);
        $cashback = $cashbackRepo->find($id);
        if (!$cashback) {
            throw $this->createNotFoundException('Cashback not found');
        }
        if (!in_array($cashback->getStatus(), [Cashback::STATUS_FAILED, Cashback::STATUS_MISSING])) {
            throw new \Exception(sprintf(
                'Update cashback can only be done for missing/failed status. Id: %s',
                $cashback->getId()
            ));
        }
        $this->denyAccessUnlessGranted(PolicyVoter::CASHBACK, $cashback->getPolicy());
        $cashbackForm = $this->get('form.factory')
            ->createNamedBuilder('cashback_form', CashbackType::class, $cashback)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cashback_form')) {
                $cashbackForm->handleRequest($request);
                if ($cashbackForm->isValid()) {
                    $policyService = $this->get('app.policy');
                    if (in_array($cashback->getPolicy()->getStatus(), [
                        Policy::STATUS_EXPIRED,
                        Policy::STATUS_EXPIRED_CLAIMABLE,
                        Policy::STATUS_EXPIRED_WAIT_CLAIM,
                    ])) {
                        $policyService->updateCashback($cashback, $cashback->getExpectedStatus());
                    } else {
                        throw new \Exception(sprintf('Unexpected policy status for cashback %s', $cashback->getId()));
                    }

                    $message = sprintf(
                        'Your request for cashback has been accepted.'
                    );
                    $this->addFlash('success', $message);

                    return new RedirectResponse(
                        $this->generateUrl('user_home')
                    );
                } else {
                    $this->addFlash(
                        'error',
                        sprintf(
                            "Sorry, there's a problem requesting cashback. Please try again or contact us. %s",
                            $cashbackForm->getErrors()
                        )
                    );
                }
            }
        }

        return [
            'cashback' => $cashback,
            'cashback_form' => $cashbackForm->createView(),
        ];
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

        foreach ($user->getUnInitPolicies() as $unInitPolicy) {
            $message = sprintf(
                'Insure your <a href="%s">%s phone</a>',
                $this->generateUrl('purchase_step_policy_id', ['id' => $unInitPolicy->getId()]),
                $unInitPolicy->getPhone()->__toString()
            );
            $this->addFlash('success', $message);
        }

        $this->addCashbackFlash();

        return array(
        );
    }

    private function addCashbackFlash()
    {
        $user = $this->getUser();
        foreach ($user->getDisplayableCashbackSorted() as $cashback) {
            if (in_array($cashback->getStatus(), [Cashback::STATUS_MISSING, Cashback::STATUS_FAILED])) {
                $message = sprintf(
                    'You have £%0.2f cashback just waiting for you. <a href="%s">Add/Update your banking details</a>.',
                    $cashback->getAmount(),
                    $this->generateUrl('user_cashback', ['id' => $cashback->getId()])
                );
                $this->addFlash('success', $message);
            }
        }
    }

    /**
     * @Route("/unpaid", name="user_unpaid_policy")
     * @Template
     */
    public function unpaidPolicyAction(Request $request)
    {
        $amount = 0;
        $webpay = null;

        $user = $this->getUser();
        $policy = $user->getUnpaidPolicy();
        if ($policy) {
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
            if ($policy->getPremiumPlan() != Policy::PLAN_MONTHLY) {
                throw new \Exception('Unpaid policy should only be triggered for monthly plans');
            }
            if (!$policy->isPolicyPaidToDate()) {
                $amount = $policy->getOutstandingPremiumToDate();

                if ($amount > 0) {
                    $webpay = $this->get('app.judopay')->webpay(
                        $policy,
                        $amount,
                        $request->getClientIp(),
                        $request->headers->get('User-Agent'),
                        JudopayService::WEB_TYPE_UNPAID
                    );
                }
            }
        }

        $data = [
            'phone' => $policy ? $policy->getPhone() : null,
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
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_invalid_policy'));
        }

        //$this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_LANDING_HOME);
        //$this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_CPC_QUOTE_MANUFACTURER);
        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_HOMEPAGE_PHONE_IMAGE);
        //$this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_QUOTE_SLIDER);
        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_PYG_HOME);
        //$this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_QUOTE_SIMPLE_COMPLEX_SPLIT);
        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_QUOTE_SIMPLE_SPLIT);
        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_CPC_MANUFACTURER_WITH_HOME);

        $countUnprocessedInvitations = count($user->getUnprocessedReceivedInvitations());
        if ($countUnprocessedInvitations > 0) {
            $message = sprintf(
                'Hey, you already have %d invitation%s. 🤗 <a href="#download-apps">Download</a> our app to connect.',
                $countUnprocessedInvitations,
                $countUnprocessedInvitations > 1 ? 's' : ''
            );
            $this->addFlash('success', $message);
        }
        $this->addFlash('error', sprintf(
            'Is your phone already damaged? <a href="%s">Click here</a>',
            $this->generateUrl('purchase_cancel', ['id' => $user->getLatestPolicy()->getId()])
        ));

        return array(
            'policy_key' => $this->getParameter('policy_key'),
            'policy' => $user->getLatestPolicy()
        );
    }

    /**
     * @Route("/payment-details", name="user_card_details")
     * @Route("/payment-details/{policyId}", name="user_card_details_policy",
     *      requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function cardDetailsAction(Request $request, $policyId = null)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        if ($policyId) {
            $policy = $policyRepo->find($policyId);
        } else {
            $policy = $user->getLatestPolicy();
        }
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

        $webpay = $this->get('app.judopay')->webRegister(
            $user,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
            $policy
        );
        $billing = new BillingDay();
        $billing->setPolicy($policy);
        $billingForm = $this->get('form.factory')
            ->createNamedBuilder('billing_form', BillingDayType::class, $billing)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('billing_form')) {
                $billingForm->handleRequest($request);
                if ($billingForm->isValid()) {
                    $policyService = $this->get('app.policy');
                    $policyService->billingDay($policy, $billing->getDay());

                    /*
                    $policyService = $this->get('app.policy');
                    $policyService->adjustScheduledPayments($policy);
                    $dm->flush();
                    */
                    $this->addFlash(
                        'success',
                        'Thanks for your request. We will be in touch soon.'
                    );

                    return $this->redirectToRoute('user_card_details_policy', ['policyId' => $policyId]);
                }
            }
        }

        $data = [
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
            'user' => $user,
            'policy' => $policy,
            'billing_form' => $billingForm->createView(),
        ];

        return $data;
    }

    /**
     * @Route("/contact-details", name="user_contact_details")
     * @Route("/contact-details/{policyId}", name="user_contact_details_policy",
     *      requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function contactDetailsAction(Request $request, $policyId = null)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        if ($policyId) {
            $policy = $policyRepo->find($policyId);
        } else {
            $policy = $user->getLatestPolicy();
        }
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

        $userEmailForm = $this->get('form.factory')
            ->createNamedBuilder('user_email_form', UserEmailType::class, $user)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('user_email_form')) {
                $userEmailForm->handleRequest($request);
                if ($userEmailForm->isValid()) {
                    $this->getManager()->flush();
                    $this->addFlash(
                        'success',
                        'Your email address is updated. You should receive an email confirmation shortly.'
                    );

                    return $this->redirectToRoute('user_contact_details_policy', ['policyId' => $policy->getId()]);
                }
            }
        }

        return [
            'user' => $user,
            'email_form' => $userEmailForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/list", name="user_policy_list")
     * @Route("/list/{policyId}", name="user_policy_list_policy",
     *      requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function policyListAction($policyId = null)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        if ($policyId) {
            $policy = $policyRepo->find($policyId);
        } else {
            $policy = $user->getLatestPolicy();
        }
        if ($policy) {
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        }

        return [
            'user' => $user,
            'policy' => $policy,
        ];
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
