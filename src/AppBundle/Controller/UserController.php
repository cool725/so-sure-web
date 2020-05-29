<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Stats;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\File\PolicyTermsFile;
use AppBundle\Document\Note\StandardNote;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Exception\CannotApplyRewardException;
use AppBundle\Security\UserVoter;
use AppBundle\Security\ClaimVoter;
use AppBundle\Service\BacsService;
use AppBundle\Service\ClaimsService;
use AppBundle\Service\InvitationService;
use AppBundle\Service\PaymentService;
use AppBundle\Service\PCAService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\SequenceService;
use AppBundle\Service\RouterService;
use AppBundle\Service\PriceService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\HelvetiaPhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\Cashback;
use AppBundle\Document\Charge;
use AppBundle\Document\Feature;
use AppBundle\Document\User;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\BankAccount;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Form\Renew;
use AppBundle\Document\Form\RenewCashback;
use AppBundle\Form\Type\UserCancelType;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Form\ClaimFnol;
use AppBundle\Document\Form\ClaimFnolDamage;
use AppBundle\Document\Form\ClaimFnolTheftLoss;
use AppBundle\Document\Form\ClaimFnolUpdate;
use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\Form\PurchaseStepPhone;
use AppBundle\Document\Form\PurchaseStepPledge;
use AppBundle\Form\Type\BacsType;
use AppBundle\Form\Type\BacsConfirmType;
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
use AppBundle\Form\Type\PhoneMakeType;
use AppBundle\Form\Type\PurchaseStepPhoneType;
use AppBundle\Form\Type\PurchaseStepPledgeType;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\Form\BillingDay;
use AppBundle\Form\Type\BillingDayType;
use AppBundle\Form\Type\ClaimFnolType;
use AppBundle\Form\Type\ClaimFnolConfirmType;
use AppBundle\Form\Type\ClaimFnolDamageType;
use AppBundle\Form\Type\ClaimFnolTheftLossType;
use AppBundle\Form\Type\ClaimFnolUpdateType;

use AppBundle\Service\FacebookService;
use AppBundle\Security\InvitationVoter;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\JudopayService;

use AppBundle\Security\PolicyVoter;

use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Facebook\Facebook;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use AppBundle\Exception\ValidationException;

use AppBundle\Exception\OldDataException;
use AppBundle\Exception\RateLimitException;
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\SelfInviteException;
use AppBundle\Exception\FullPotException;
use AppBundle\Exception\InvalidPolicyException;
use AppBundle\Exception\OptOutException;
use AppBundle\Exception\ConnectedInvitationException;
use AppBundle\Exception\ClaimException;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Exception\DuplicateInvitationException;
use AppBundle\Exception\LostStolenImeiException;
use AppBundle\Exception\InvalidImeiException;
use AppBundle\Exception\ImeiBlacklistedException;
use AppBundle\Exception\ImeiPhoneMismatchException;
use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\DirectDebitBankException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @Route("/user")
 */
class UserController extends BaseController
{
    use DateTrait;
    use CurrencyTrait;
    use ValidatorTrait;

    /**
     * @Route("", name="user_home")
     * @Route("/", name="user_home_slash")
     * @Route("/{policyId}", name="user_policy", requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function indexAction(Request $request, $policyId = null)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $scodeRepo = $dm->getRepository(SCode::class);
        $user = $this->getUser();
        if ($user->hasPolicyCancelledAndPaymentOwed()) {
            foreach ($user->getAllPolicies() as $policy) {
                if ($policy->isCancelledAndPaymentOwed()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_remainder_policy', ['id' => $policy->getId()])
                    );
                }
            }
        } elseif (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy() && !$user->hasPicsureRequiredPolicy()) {
            if ($this->getSessionQuotePhone($request) && $user->canPurchasePolicy()) {
                if ($user->hasPartialPolicy()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_step_phone_id', [
                            'id' => $user->getPartialPolicies()[0]->getId()
                        ])
                    );
                } else {
                    return new RedirectResponse($this->generateUrl('purchase_step_phone'));
                }
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

        $scode = null;

        if ($session = $this->get('session')) {
            $scode = $scodeRepo->findOneBy(['code' => $session->get('scode'), 'active' => true]);
        }

        /** @var InvitationService $invitationService */
        $invitationService = $this->get('app.invitation');
        $emailInvitiation = new EmailInvitation();
        $emailInvitationForm = $this->get('form.factory')
            ->createNamedBuilder('email', EmailInvitationType::class, $emailInvitiation)
            ->getForm();
        // TODO: Remove setting code once all codes applied from payment page
        $scodeForm = $this->get('form.factory')
            ->createNamedBuilder('scode', SCodeInvitationType::class)
            ->getForm();
        // @codingStandardsIgnoreStart
        $scodeFormTwo = $this->get('form.factory')
            ->createNamedBuilder('scode_two', SCodeInvitationType::class)
            ->getForm();
        // @codingStandardsIgnoreEnd

        if ($request->request->has('email')) {
            $emailInvitationForm->handleRequest($request);
            if ($emailInvitationForm->isSubmitted() && $emailInvitationForm->isValid()) {
                try {
                    $invitationService->inviteByEmail(
                        $policy,
                        $emailInvitiation->getEmail(),
                        null,
                        null,
                        'User Home'
                    );
                    $this->addFlash(
                        'success',
                        sprintf('%s was invited', $emailInvitiation->getEmail())
                    );
                } catch (SelfInviteException $e) {
                    $this->addFlash('error', 'Sorry, you are not able to invite yourself');
                } catch (\Exception $e) {
                    $msg = sprintf('Sorry, there was an error inviting %s', $emailInvitiation->getEmail());
                    $this->get('logger')->error($msg, ['exception' => $e]);
                    $this->addFlash('error', $msg);
                }

                return new RedirectResponse($this->generateUrl('user_policy', ['policyId' => $policy->getId()]));
            }
        } elseif ($request->request->has('scode')) {
            $scodeForm->handleRequest($request);
            $scodeFormTwo->handleRequest($request);
            // @codingStandardsIgnoreStart
            if (($scodeForm->isSubmitted() && $scodeForm->isValid()) or ($scodeFormTwo->isSubmitted() && $scodeFormTwo->isValid())) {
                if ($session = $this->get('session')) {
                    $session->remove('scode');
                }

                $code = $scodeForm->getData()['scode'];
                $scode = $scodeRepo->findOneBy(['code' => $code]);
                if (!$scode || !SCode::isValidSCode($scode->getCode())) {
                    $code = mb_strtoupper($code);
                    $scode = $scodeRepo->findOneBy(['code' => $code]);
                    if (!$scode || !SCode::isValidSCode($scode->getCode())) {
                        $code = mb_strtolower($code);
                        $scode = $scodeRepo->findOneBy(['code' => $code]);
                        if (!$scode || !SCode::isValidSCode($scode->getCode())) {
                            $this->addFlash(
                                'warning',
                                sprintf("SCode %s is missing or incorrect", $code)
                            );
                            return new RedirectResponse(
                                $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                            );
                        }
                    }
                }
                if ($scode->isReward() && $scode->isActive()) {
                    $reward = $scode->getReward();
                    if (!$reward || !$reward->canApply($policy, new \DateTime())) {
                        $this->addFlash('warning', sprintf('Sorry, promo code %s cannot be applied', $code));
                        return new RedirectResponse(
                            $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                        );
                    }
                }
                try {
                    $invitation = $this->get('app.invitation')->inviteBySCode($policy, $code);
                    if ($invitation && !$scode->isReward()) {
                        $message = sprintf(
                            '%s has been invited  to connect',
                            $invitation->getInvitee()->getName()
                        );
                    } else {
                        $message = 'Your bonus has been added';
                    }
                    $this->addFlash('success', $message);

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (DuplicateInvitationException $e) {
                    $message = sprintf("SCode %s has already been used by you", $code);
                    if ($scode->isReward()) {
                        $message = sprintf("Promo Code %s has already been applied", $code);
                    }
                    $this->addFlash(
                        'warning',
                        $message
                    );

                    return new RedirectResponse(
                        $this->generateUrl('user_policy', ['policyId' => $policy->getId()])
                    );
                } catch (ConnectedInvitationException $e) {
                    $message = sprintf("You're already connected");
                    if ($scode->isReward()) {
                        $message = sprintf("Promo Code %s has already been applied", $code);
                    }
                    $this->addFlash(
                        'warning',
                        $message
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
                } catch (CannotApplyRewardException $e) {
                    $this->addFlash(
                        'warning',
                        sprintf("Cannot apply Promo Code to policy.")
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
            // @codingStandardsIgnoreEnd
        }

        $renewMessage = false;
        foreach ($user->getValidPolicies(true) as $checkPolicy) {
            if ($checkPolicy->notifyRenewal() && !$checkPolicy->isRenewed() && !$checkPolicy->hasCashback()) {
                $renewMessage = true;
            }

            if ($checkPolicy->getPolicyTerms()->isPicSureEnabled() && !$checkPolicy->isPicSureValidated()) {
                $url = null;
                // TODO: Change to branch open pic-sure link
                if ($checkPolicy->getPhone()->isITunes()) {
                    $url = $this->generateUrl('download_apple', ['medium' => 'pic-sure-warning']);
                } elseif ($checkPolicy->getPhone()->isGooglePlay()) {
                    $url = $this->generateUrl('download_google', ['medium' => 'pic-sure-warning']);
                }
            }
        }
        if (!$renewMessage) {
            $this->addCashbackFlash();
        }
        $this->addRepurchaseExpiredPolicyFlash();
        $this->addUnInitPolicyInsureFlash();

        $fbFriends = null;
        if ($this->get('app.feature')->isEnabled(Feature::FEATURE_APP_FACEBOOK_USERFRIENDS_PERMISSION)) {
            $fbFriends = $this->getFacebookFriends($request, $policy);
        }

        // Get max connection
        $maxConnections = $policy->getMaxConnections();

        return array(
            'policy' => $policy,
            'email_form' => $emailInvitationForm->createView(),
            'scode_form' => $scodeForm->createView(),
            'scode_form_two' => $scodeFormTwo->createView(),
            'scode' => $scode,
            'friends' => $fbFriends,
            'max_connections' => $maxConnections
        );
    }

    /**
     * @Route("/invites", name="user_invite")
     * @Route("/invites/{policyId}", name="user_invite_policy", requirements={"policyId":"[0-9a-f]{24,24}"}))
     * @Template
     */
    public function invitesAction(Request $request, $policyId = null)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $scodeRepo = $dm->getRepository(SCode::class);
        $user = $this->getUser();
        if ($user->hasPolicyCancelledAndPaymentOwed()) {
            foreach ($user->getAllPolicies() as $policy) {
                if ($policy->isCancelledAndPaymentOwed()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_remainder_policy', ['id' => $policy->getId()])
                    );
                }
            }
        } elseif (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy() && !$user->hasPicsureRequiredPolicy()) {
            if ($this->getSessionQuotePhone($request) && $user->canPurchasePolicy()) {
                if ($user->hasPartialPolicy()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_step_phone_id', [
                            'id' => $user->getPartialPolicies()[0]->getId()
                        ])
                    );
                } else {
                    return new RedirectResponse($this->generateUrl('purchase_step_phone'));
                }
            } else {
                return new RedirectResponse($this->generateUrl('user_invalid_policy'));
            }
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            if ($user->hasPicsureRequiredPolicy()) {
                return $this->redirectToRoute("user_home");
            } else {
                throw $this->createNotFoundException('No active policy found');
            }
        }
        if ($policyId) {
            $policy = $policyRepo->find($policyId);
        } else {
            $policy = $user->getLatestPolicy();
        }
        if ($policy) {
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        }

        $scode = null;
        $codeMessage = null;

        if ($session = $this->get('session')) {
            $scode = $scodeRepo->findOneBy(['code' => $session->get('scode'), 'active' => true]);
        }

        /** @var InvitationService $invitationService */
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
                try {
                    $invitationService->inviteByEmail(
                        $policy,
                        $emailInvitiation->getEmail(),
                        null,
                        null,
                        'User Home'
                    );
                    $this->addFlash(
                        'success',
                        sprintf('%s was invited', $emailInvitiation->getEmail())
                    );
                } catch (SelfInviteException $e) {
                    $this->addFlash('error', 'Sorry, you are not able to invite yourself');
                } catch (\Exception $e) {
                    $msg = sprintf('Sorry, there was an error inviting %s', $emailInvitiation->getEmail());
                    $this->get('logger')->error($msg, ['exception' => $e]);
                    $this->addFlash('error', $msg);
                }

                return new RedirectResponse($this->generateUrl('user_invite_policy', ['policyId' => $policy->getId()]));
            }
        } elseif ($request->request->has('invitation')) {
            $invitationForm->handleRequest($request);
            if ($invitationForm->isSubmitted() && $invitationForm->isValid()) {
                foreach ($user->getUnprocessedReceivedInvitations() as $invitation) {
                    if ($invitationForm->get(sprintf('accept_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::ACCEPT, $invitation);
                        try {
                            $connection = $invitationService->accept($invitation, $policy);
                            $this->addFlash(
                                'success',
                                sprintf("You're now connected with %s", $invitation->getInviter()->getName())
                            );
                        } catch (ClaimException $e) {
                            $this->addFlash(
                                'warning',
                                sprintf("You or your friend have a claim and are unable to connect.")
                            );
                        }

                        return new RedirectResponse(
                            $this->generateUrl('user_invite_policy', ['policyId' => $policy->getId()])
                        );
                    } elseif ($invitationForm->get(sprintf('reject_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::REJECT, $invitation);
                        $invitationService->reject($invitation);
                        $this->addFlash(
                            'warning',
                            sprintf("You've declined the invitation from %s", $invitation->getInviter()->getName())
                        );

                        return new RedirectResponse(
                            $this->generateUrl('user_invite_policy', ['policyId' => $policy->getId()])
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
                            $this->generateUrl('user_invite_policy', ['policyId' => $policy->getId()])
                        );
                    } elseif ($sentInvitationForm->get(sprintf('cancel_%s', $invitation->getId()))->isClicked()) {
                        $this->denyAccessUnlessGranted(InvitationVoter::CANCEL, $invitation);
                        $invitationService->cancel($invitation);
                        $this->addFlash(
                            'warning',
                            sprintf("Cancelled invitation to %s", $invitation->getInviteeName())
                        );

                        return new RedirectResponse(
                            $this->generateUrl('user_invite_policy', ['policyId' => $policy->getId()])
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
                        try {
                            $invitationService->connect($policy, $unconnectedPolicy);
                            $this->addFlash(
                                'success',
                                sprintf("You're now connected with %s", $unconnectedPolicy->getDefaultName())
                            );
                        } catch (ClaimException $e) {
                            $this->addFlash(
                                'warning',
                                sprintf("You or your friend have a claim and are unable to connect.")
                            );
                        }

                        return new RedirectResponse(
                            $this->generateUrl('user_invite_policy', ['policyId' => $policy->getId()])
                        );
                    }
                }
            }
        }

        return [
            'user' => $user,
            'policy' => $policy,
            'email_form' => $emailInvitationForm->createView(),
            'invitation_form' => $invitationForm->createView(),
            'sent_invitation_form' => $sentInvitationForm->createView(),
            'scode_form' => $scodeForm->createView(),
            'scode' => $scode,
            'unconnected_user_policy_form' => $unconnectedUserPolicyForm->createView(),
        ];
    }

    /**
     * @Route("/data-portability", name="user_data_portability")
     */
    public function dataPortabilityAction()
    {
        /** @var User $user */
        $user = $this->getUser();
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);

        $lines[] = [
            'Name',
            'DOB',
            'Address',
            'Mobile Number',
            'Policy Number',
            'Policy Start Date',
            'Policy End Date',
            'Phone',
            'IMEI',
        ];

        foreach ($user->getPolicies() as $policy) {
            if ($policy instanceof SalvaPhonePolicy) {
                $lines[] = [
                    sprintf('"%s"', $user->getName()),
                    sprintf('"%s"', $user->getBirthday() ? $user->getBirthday()->format('d/m/Y') : null),
                    sprintf('"%s"', $user->getBillingAddress()),
                    sprintf('"%s"', $user->getMobileNumber()),
                    sprintf('"%s"', $policy->getPolicyNumber()),
                    sprintf('"%s"', $policy->getStart() ? $policy->getStart()->format('d/m/Y') : null),
                    sprintf('"%s"', $policy->getEnd() ? $policy->getEnd()->format('d/m/Y') : null),
                    sprintf('"%s"', $policy->getPhone() ? $policy->getPhone()->__toString() : null),
                    sprintf('"%s"', $policy->getImei()),
                ];
            } else {
                if ($iterations = $policy->getPreviousIterations()) {
                    foreach ($iterations as $iteration) {
                        $lines[] = [
                            sprintf('"%s"', $user->getName()),
                            sprintf('"%s"', $user->getBirthday() ? $user->getBirthday()->format('d/m/Y') : null),
                            sprintf('"%s"', $user->getBillingAddress()),
                            sprintf('"%s"', $user->getMobileNumber()),
                            sprintf('"%s"', $policy->getPolicyNumber()),
                            sprintf('"%s"', $iteration->getStart() ? $iteration->getStart()->format('d/m/Y') : null),
                            sprintf('"%s"', $iteration->getEnd() ? $iteration->getEnd()->format('d/m/Y') : null),
                            sprintf('"%s"', $iteration->getPhone() ? $iteration->getPhone()->__toString() : null),
                            sprintf('"%s"', $iteration->getImei()),
                        ];
                    }
                }
                $iteration = $policy->getCurrentIteration();
                $lines[] = [
                    sprintf('"%s"', $user->getName()),
                    sprintf('"%s"', $user->getBirthday() ? $user->getBirthday()->format('d/m/Y') : null),
                    sprintf('"%s"', $user->getBillingAddress()),
                    sprintf('"%s"', $user->getMobileNumber()),
                    sprintf('"%s"', $policy->getPolicyNumber()),
                    sprintf('"%s"', $iteration->getStart() ? $iteration->getStart()->format('d/m/Y') : null),
                    sprintf('"%s"', $iteration->getEnd() ? $iteration->getEnd()->format('d/m/Y') : null),
                    sprintf('"%s"', $iteration->getPhone() ? $iteration->getPhone()->__toString() : null),
                    sprintf('"%s"', $iteration->getImei()),
                ];
            }
        }

        $response = new StreamedResponse();
        $response->setCallback(function () use ($lines) {
            $handle = fopen('php://output', 'w+');
            foreach ($lines as $line) {
                fputcsv(
                    $handle, // The file pointer
                    $line
                );
            }
            fclose($handle);
        });

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="so-sure-data-portability.csv"');

        return $response;
    }

    /**
     * @Route("/repurchase/{id}", name="user_repurchase_policy")
     */
    public function repurchasePolicyAction($id)
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

        return $this->redirectToRoute('purchase_step_phone_id', ['id' => $newPolicy->getId()]);
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
        } elseif ($policy->hasCashback() || $policy->isRenewalDeclined()) {
            return $this->redirectToRoute('user_renew_declined', ['id' => $id]);
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
                    // $this->addFlash('success', $message);

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
                return $this->redirectToRoute('user_renew_declined', ['id' => $id]);
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
        $cashback->setDate(\DateTime::createFromFormat('U', time()));
        $cashback->setPolicy($policy);
        $cashback->setStatus(Cashback::STATUS_PENDING_CLAIMABLE);
        $cashbackForm = $this->get('form.factory')
            ->createNamedBuilder('cashback_form', CashbackType::class, $cashback)
            ->getForm();
        $declineForm = $this->get('form.factory')
            ->createNamedBuilder('decline_form')->add('decline', SubmitType::class)
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
                    $policyService->declineRenew($policy, $cashback);

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
            } elseif ($request->request->has('decline_form')) {
                $declineForm->handleRequest($request);
                if ($declineForm->isValid()) {
                    $policyService = $this->get('app.policy');
                    $policyService->declineRenew($policy);

                    $message = sprintf(
                        'Your request to terminate your policy has been accepted.'
                    );
                    // $this->addFlash('success', $message);

                    return new RedirectResponse(
                        $this->generateUrl('user_renew_declined', ['id' => $id])
                    );
                } else {
                    $this->addFlash(
                        'error',
                        sprintf(
                            "Sorry, there's a problem terminating your policy. Please try again or contact us. %s",
                            $declineForm->getErrors()
                        )
                    );
                }
            }
        }

        // TODO: Adjust for declined
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
            'decline_form' => $declineForm->createView(),
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

                return $this->redirectToRoute('user_renew_completed', ['id' => $id]);
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
     * @Route("/renew/{id}/declined", name="user_renew_declined")
     * @Template
     */
    public function renewPolicyDeclinedAction($id)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_RENEWAL, [
            'Renew Type' => 'Declined',
        ]);

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
        /** @var Cashback $cashback */
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
                    /** @var PolicyService $policyService */
                    $policyService = $this->get('app.policy');
                    $policy = $cashback->getPolicy();
                    if (in_array($policy->getStatus(), [
                        Policy::STATUS_EXPIRED,
                        Policy::STATUS_EXPIRED_CLAIMABLE,
                        Policy::STATUS_EXPIRED_WAIT_CLAIM,
                    ])) {
                        $policyService->updateCashback($cashback, $cashback->getExpectedStatus());
                    } elseif ($policy->getStatus() == Policy::STATUS_CANCELLED && $policy->isRefundAllowed()) {
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

        $this->addRepurchaseExpiredPolicyFlash();

        $this->addUnInitPolicyInsureFlash();

        $this->addCashbackFlash();

        return array(
        );
    }

    private function addRepurchaseExpiredPolicyFlash()
    {
        $user = $this->getUser();
        $excludePolicyImei = [];
        foreach ($user->getPartialPolicies() as $partialPolicy) {
            $excludePolicyImei[] = $partialPolicy->getImei();
        }
        foreach ($user->getPolicies() as $policy) {
            if ($policy->isPolicyExpiredWithin30Days() && !in_array($policy->getImei(), $excludePolicyImei)) {
                $message = sprintf(
                    'Re-purchase insurance for your <a href="%s">%s phone</a>',
                    $this->generateUrl('user_repurchase_policy', ['id' => $policy->getId()]),
                    $policy->getPhone()->__toString()
                );
                $this->addFlash('success-raw', $message);
            }
        }
    }

    private function addUnInitPolicyInsureFlash()
    {
        $user = $this->getUser();
        foreach ($user->getPartialPolicies() as $partialPolicy) {
            $message = sprintf(
                'Finish insuring your <a href="%s">%s phone</a>',
                $this->generateUrl('purchase_step_phone_id', ['id' => $partialPolicy->getId()]),
                $partialPolicy->getPhone()->__toString()
            );
            $this->addFlash('success-raw', $message);
        }
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
                $this->addFlash('success-raw', $message);
            }
        }
    }

    /**
     * @Route("/unpaid", name="user_unpaid_policy")
     * @Template("@AppBundle/Resources/views/User/unpaidPolicyNoBacs.html.twig")
     */
    public function unpaidPolicyAction()
    {
        /** @var User $user */
        $user = $this->getUser();
        /** @var Policy $policy */
        $policy = $user->getUnpaidPolicy();
        if (!$policy) {
            $this->get('logger')->error(sprintf('Unable to locate unpaid policy for user %s.', $user->getId()));
            return new RedirectResponse($this->generateUrl('user_home'));
        }
        // Validate unpaid policy and use rescheduled amount if no owed amount due to bacs timing or whatever.
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        $policyService = $this->get('app.policy');
        $amount = $policyService->checkOwedPremium($policy, new \DateTime());
        if ($amount == 0) {
            return new RedirectResponse($this->generateUrl('user_home'));
        }
        $unpaidReason = $policy->getUnpaidReason();
        if (in_array($unpaidReason, [
            Policy::UNPAID_BACS_UNKNOWN,
            Policy::UNPAID_CARD_UNKNOWN,
            Policy::UNPAID_UNKNOWN
        ])) {
            $this->get('logger')->warning(sprintf(
                'Policy %s has unknown unpaid reason (%s)',
                $policy->getId(),
                $unpaidReason
            ));
        }
        // Check feature flags.
        $bacsFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_BACS);
        $checkoutFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_CHECKOUT);
        if ($policy->getPremiumPlan() != Policy::PLAN_MONTHLY) {
            $bacsFeature = false;
        }
        if (!($bacsFeature || $checkoutFeature)) {
            $this->get('logger')->error('No payment providers available.');
        }
        // Send relevant information to the template. Checkout payment happens on frontend and returns to the
        // purchase controller via their server.
        return [
            'policy' => $policy,
            'amount' => $amount,
            'bacs_feature' => $bacsFeature,
            'unpaid_reason' => $unpaidReason,
            'card_provider' => SoSure::PAYMENT_PROVIDER_CHECKOUT
        ];
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/welcome", name="user_welcome")
     * @Route("/welcome/{id}", name="user_welcome_policy_id")
     * @Route("/complete", name="user_instore")
     * @Route("/complete/{id}", name="user_instore_id")
     * @Route("/validation-required", name="user_validation_required")
     * @Route("/validation-required/{id}", name="user_validation_required_id")
     * @Template
     */
    public function welcomeAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $user = $this->getUser();
        $invitationService = $this->get('app.invitation');
        $policyService = $this->get('app.policy');

        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy() && !$user->hasPicsureRequiredPolicy()) {
            return new RedirectResponse($this->generateUrl('user_invalid_policy'));
        }

        // fetch latest policy if none provided
        if ($id === null) {
            $policy = $user->getLatestPolicy();
        } else {
            $policyRepo = $dm->getRepository(Policy::class);
            $policy = $policyRepo->find($id);
        }

        // if policy id was given and user does not match
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

        // A/B Email Optional
        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_EMAIL_OPTIONAL);

        if ($policy->getPremiumPlan() == Policy::PLAN_YEARLY) {
            // A/B Pricing Messaging Experiment
            $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_PRICING_MESSAGING);
        }

        $pageVisited = $policy->getVisitedWelcomePage() ? true : false;
        if ($policy->getVisitedWelcomePage() === null) {
            $policy->setVisitedWelcomePage(\DateTime::createFromFormat('U', time()));
            $dm->flush($policy);
        }

        /** @var RouterService $router */
        $router = $this->get('app.router');
        // TODO: Should this account for policy ID?
        $invitePage = $router->generateUrl('user_invite', []);

        $countUnprocessedInvitations = count($user->getUnprocessedReceivedInvitations());
        if ($countUnprocessedInvitations > 0) {
            $message = sprintf(
                'Hey, you already have %d invitation%s. 🤗 <a href="%s">Connect here</a>.',
                $countUnprocessedInvitations,
                $countUnprocessedInvitations > 1 ? 's' : '',
                $invitePage
            );
            $this->addFlash('success-raw', $message);
        }

        $oauth2FlowParams = null;
        $session = $request->getSession();
        if ($session && $session->has('oauth2Flow')) {
            $url = $session->get('oauth2Flow.targetPath');
            $query = parse_url($url, PHP_URL_QUERY);
            parse_str($query, $oauth2FlowParams);
        }

        // In-store
        $instore = $this->get('session')->get('store');

        $template = 'AppBundle:User:onboarding.html.twig';

        $referralFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_REFERRAL);

        // TODO: This should not be a feature
        if ($referralFeature) {
            $template = 'AppBundle:User:welcomeReferral.html.twig';
        }

        if ($request->get('_route') == 'user_instore') {
            $template = 'AppBundle:User:complete.html.twig';
        } elseif ($request->get('_route') == 'user_validation_required') {
            $template = 'AppBundle:User:validationRequired.html.twig';
        }

        return $this->render($template, [
            'cancel_url' => $this->generateUrl('purchase_cancel_damaged', ['id' => $user->getLatestPolicy()->getId()]),
            'policy_key' => $this->getParameter('policy_key'),
            'policy' => $policy,
            'has_visited_welcome_page' => $pageVisited,
            'oauth2FlowParams' => $oauth2FlowParams,
            'user' => $user,
            'instore' => $instore,
        ]);
    }

    /**
     * @Route("/payment-details", name="user_payment_details")
     * @Route("/payment-details/{policyId}", name="user_payment_details_policy",
     *      requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function paymentDetailsAction(Request $request, $policyId = null)
    {
        $dm = $this->getManager();
        /** @var User $user */
        $user = $this->getUser();
        $policyRepo = $dm->getRepository(Policy::class);
        if ($user->hasPolicyCancelledAndPaymentOwed()) {
            foreach ($user->getAllPolicies() as $policy) {
                if ($policy->isCancelledAndPaymentOwed()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_remainder_policy', ['id' => $policy->getId()])
                    );
                }
            }
        } elseif (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy() && !$user->hasPicsureRequiredPolicy()) {
            if ($this->getSessionQuotePhone($request) && $user->canPurchasePolicy()) {
                if ($user->hasPartialPolicy()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_step_phone_id', [
                            'id' => $user->getPartialPolicies()[0]->getId()
                        ])
                    );
                } else {
                    return new RedirectResponse($this->generateUrl('purchase_step_phone'));
                }
            } else {
                return new RedirectResponse($this->generateUrl('user_invalid_policy'));
            }
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            if ($user->hasPicsureRequiredPolicy()) {
                return $this->redirectToRoute("user_home");
            } else {
                throw $this->createNotFoundException('No active policy found');
            }
        }
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        // If a user has an unpaid policy, then avoid updating card details (email directing to here)
        // as its then in a very odd state - card correct, but unpaid. better ask user to take the payment immediately
        if ($user->hasUnpaidPolicy() && $request->get('_route') != 'user_payment_details_bacs') {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }

        if ($policyId) {
            /** @var Policy $policy */
            $policy = $policyRepo->find($policyId);
        } else {
            /** @var Policy $policy */
            $policy = $user->getLatestPolicy();
            if (!$policy) {
                $policy = $user->getLatestPolicy(false);
            }
        }

        // page no longer makes much sense unless associated with a policy
        if (!$policy) {
            return new RedirectResponse($this->generateUrl('user_invalid_policy'));
        }

        $lastPaymentInProgress = false;
        if ($policy) {
            $lastPaymentCredit = $policy->getLastPaymentCredit();
            if ($policy->hasPolicyOrUserBacsPaymentMethod()) {
                if ($lastPaymentCredit && $lastPaymentCredit instanceof BacsPayment) {
                    /** @var BacsPayment $lastPaymentCredit */
                    $lastPaymentInProgress = $lastPaymentCredit->inProgress();
                }
            }
        }

        $bacsFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_BACS);
        $swapFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_CARD_SWAP_FROM_BACS);
        $checkoutFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_CHECKOUT);
        $cardProvider = SoSure::PAYMENT_PROVIDER_CHECKOUT;

        // For now, only allow monthly policies with bacs
        if ($bacsFeature && $policy->getPremiumPlan() != Policy::PLAN_MONTHLY) {
            $bacsFeature = false;
        }
        // we need enough time for bacs to be billed + reverse payment to be notified + 1 day internal processing
        // or no point in swapping to bacs
        if ($bacsFeature && !$policy->canBacsPaymentBeMadeInTime()) {
            $bacsFeature = false;
        }

        /** @var PaymentService $paymentService */
        $paymentService = $this->get('app.payment');

        $billing = new BillingDay();
        if ($policy) {
            $billing->setPolicy($policy);
        }
        /** @var FormInterface $billingForm */
        $billingForm = $this->get('form.factory')
            ->createNamedBuilder('billing_form', BillingDayType::class, $billing)
            ->getForm();
        $bacs = new Bacs();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('billing_form')) {
                $billingForm->handleRequest($request);
                if ($billingForm->isValid()) {
                    $policyService = $this->get('app.policy');
                    $policyService->billingDay($policy, $billing->getDay());
                    $this->addFlash(
                        'success',
                        'Thanks for your request. We will be in touch soon.'
                    );

                    return $this->redirectToRoute('user_payment_details_policy', ['policyId' => $policyId]);
                }
            }
        }

        $data = [
            'user' => $user,
            'policy' => $policy,
            'billing_form' => $billingForm->createView(),
            'bacs_feature' => $bacsFeature,
            'bacs' => $bacs,
            'bacs_last_payment_in_progress' => $lastPaymentInProgress,
            'card_provider' => $cardProvider,
        ];

        return $data;
    }

    /**
     * @Route("/payment-details/bacs", name="user_payment_details_bacs")
     * @Template
     */
    public function paymentBacsDetailsAction(Request $request, $policyId = null)
    {
        $dm = $this->getManager();
        /** @var User $user */
        $user = $this->getUser();
        $policyRepo = $dm->getRepository(Policy::class);
        if ($user->hasPolicyCancelledAndPaymentOwed()) {
            foreach ($user->getAllPolicies() as $policy) {
                if ($policy->isCancelledAndPaymentOwed()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_remainder_policy', ['id' => $policy->getId()])
                    );
                }
            }
        } elseif (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy() && !$user->hasPicsureRequiredPolicy()) {
            if ($this->getSessionQuotePhone($request) && $user->canPurchasePolicy()) {
                if ($user->hasPartialPolicy()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_step_phone_id', [
                            'id' => $user->getPartialPolicies()[0]->getId()
                        ])
                    );
                } else {
                    return new RedirectResponse($this->generateUrl('purchase_step_phone'));
                }
            } else {
                return new RedirectResponse($this->generateUrl('user_invalid_policy'));
            }
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            if ($user->hasPicsureRequiredPolicy()) {
                return $this->redirectToRoute("user_home");
            } else {
                throw $this->createNotFoundException('No active policy found');
            }
        }
        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        // Redirect if unpaid
        if ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }

        if ($policyId) {
            /** @var Policy $policy */
            $policy = $policyRepo->find($policyId);
        } else {
            /** @var Policy $policy */
            $policy = $user->getLatestPolicy();
            if (!$policy) {
                $policy = $user->getLatestPolicy(false);
            }
        }

        // page no longer makes much sense unless associated with a policy
        if (!$policy) {
            return new RedirectResponse($this->generateUrl('user_invalid_policy'));
        }

        $lastPaymentInProgress = false;
        if ($policy) {
            $lastPaymentCredit = $policy->getLastPaymentCredit();
            if ($policy->hasPolicyOrUserBacsPaymentMethod()) {
                if ($lastPaymentCredit && $lastPaymentCredit instanceof BacsPayment) {
                    /** @var BacsPayment $lastPaymentCredit */
                    $lastPaymentInProgress = $lastPaymentCredit->inProgress();
                }
            }
        }

        $bacsFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_BACS);
        $swapFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_CARD_SWAP_FROM_BACS);

        // For now, only allow monthly policies with bacs
        if ($bacsFeature && $policy->getPremiumPlan() != Policy::PLAN_MONTHLY) {
            $bacsFeature = false;
        }
        // we need enough time for bacs to be billed + reverse payment to be notified + 1 day internal processing
        // or no point in swapping to bacs
        if ($bacsFeature && !$policy->canBacsPaymentBeMadeInTime()) {
            $bacsFeature = false;
        }

        /** @var PaymentService $paymentService */
        $paymentService = $this->get('app.payment');

        // To show the correct form
        $bacsStep = 1;

        $billing = new BillingDay();
        if ($policy) {
            $billing->setPolicy($policy);
        }
        $bacs = new Bacs();
        $bacs->setValidateName($user->getName());
        /** @var FormInterface $bacsForm */
        $bacsForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_form', BacsType::class, $bacs)
            ->getForm();
        $bacsConfirm = new Bacs();
        /** @var FormInterface $bacsConfirmForm */
        $bacsConfirmForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_confirm_form', BacsConfirmType::class, $bacsConfirm)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('bacs_form')) {
                $bacsForm->handleRequest($request);
                if ($bacsForm->isValid()) {
                    if (!$bacs->isValid()) {
                        $this->addFlash('error', 'Sorry, but this bank account is not valid');
                    } else {
                        $paymentService->generateBacsReference($bacs, $user);
                        $bacsConfirm = clone $bacs;
                        $bacsStep = 2;
                        $bacsConfirmForm = $this->get('form.factory')
                            ->createNamedBuilder('bacs_confirm_form', BacsConfirmType::class, $bacsConfirm)
                            ->getForm();
                    }
                }
            } elseif ($request->request->has('bacs_confirm_form')) {
                $bacsConfirmForm->handleRequest($request);
                if ($bacsConfirmForm->isValid()) {
                    $paymentService->confirmBacs(
                        $policy,
                        $bacsConfirm->transformBacsPaymentMethod($this->getIdentityLogWeb($request))
                    );

                    $this->addFlash(
                        'success',
                        'Your direct debit is now setup. You will receive an email confirmation shortly.'
                    );

                    return $this->redirectToRoute('user_payment_details_policy', ['policyId' => $policyId]);
                }
            }
        }

        $data = [
            'user' => $user,
            'policy' => $policy,
            'bacs_form' => $bacsForm->createView(),
            'bacs_confirm_form' => $bacsConfirmForm->createView(),
            'bacs_feature' => $bacsFeature,
            'bacs' => $bacs,
            'bacs_last_payment_in_progress' => $lastPaymentInProgress,
            'bacs_step' => $bacsStep,
        ];

        return $this->render('AppBundle:User:bacsDetails.html.twig', $data);
    }

    /**
     * @Route("/settings", name="user_settings")
     * @Route("/settings/{policyId}", name="user_settings_policy",
     *      requirements={"policyId":"[0-9a-f]{24,24}"})
     * @Template
     */
    public function settingsAction(Request $request, $policyId = null)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        if ($policyId) {
            $policy = $policyRepo->find($policyId);
        } else {
            $policy = $user->getLatestPolicy();
        }

        $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);

        $userEmailForm = $this->get('form.factory')
            ->createNamedBuilder('user_email_form', UserEmailType::class, $user)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('user_email_form')) {
                $userEmailForm->handleRequest($request);
                if ($userEmailForm->isValid()) {
                    $userRepo = $this->getManager()->getRepository(User::class);
                    $existingUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($user->getEmail())]);
                    if ($existingUser) {
                        // @codingStandardsIgnoreStart
                        $this->addFlash(
                            'error',
                            'Sorry, but that email already exists in our system. Please contact us to resolve this issue.'
                        );
                        // @codingStandardsIgnoreEnd
                    } else {
                        $this->getManager()->flush();
                        $this->addFlash(
                            'success',
                            'Your email address is updated. You should receive an email confirmation shortly.'
                        );
                    }

                    if ($policy) {
                        return $this->redirectToRoute('user_settings_policy', [
                            'policyId' => $policy->getId()
                        ]);
                    } else {
                        return $this->redirectToRoute('user_settings');
                    }
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
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $user = $this->getUser();

        if ($policyId) {
            $policy = $policyRepo->find($policyId);
        } else {
            $policy = $user->getLatestPolicy();
        }
        if ($policy) {
            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        }

        if ($user->hasPolicyCancelledAndPaymentOwed()) {
            foreach ($user->getAllPolicies() as $policy) {
                if ($policy->isCancelledAndPaymentOwed()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_remainder_policy', ['id' => $policy->getId()])
                    );
                }
            }
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }

        return [
            'user' => $user,
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/claim", name="user_claim")
     * @Template
     */
    public function claimAction(Request $request)
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->hasPolicyCancelledAndPaymentOwed()) {
            foreach ($user->getAllPolicies() as $policy) {
                if ($policy->isCancelledAndPaymentOwed()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_remainder_policy', ['id' => $policy->getId()])
                    );
                }
            }
        } elseif (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy() && !$user->hasPicsureRequiredPolicy()) {
            if ($this->getSessionQuotePhone($request) && $user->canPurchasePolicy()) {
                if ($user->hasPartialPolicy()) {
                    return new RedirectResponse(
                        $this->generateUrl('purchase_step_phone_id', [
                            'id' => $user->getPartialPolicies()[0]->getId()
                        ])
                    );
                } else {
                    return new RedirectResponse($this->generateUrl('purchase_step_phone'));
                }
            } else {
                return new RedirectResponse($this->generateUrl('user_invalid_policy'));
            }
        } elseif ($user->hasUnpaidPolicy()) {
            return new RedirectResponse($this->generateUrl('user_unpaid_policy'));
        }
        $this->denyAccessUnlessGranted(UserVoter::VIEW, $user);
        if (!$user->hasActivePolicy() && !$user->hasUnpaidPolicy()) {
            if ($user->hasPicsureRequiredPolicy()) {
                $this->addFlash(
                    "error",
                    "Reminder: You can only claim once you have validated your phone."
                );
                return $this->redirectToRoute("user_home");
            } else {
                throw $this->createNotFoundException('No active policy found');
            }
        }
        // If the user has an open claim on all of their policies then they can't make a claim right now so send them to
        // one of their open claims.
        if (count($user->getValidPoliciesWithoutOpenedClaim(true)) == 0) {
            $policies = $user->getValidPolicies();
            foreach ($policies as $policy) {
                /** @var Policy $policy */
                if ($policy->getLatestFnolClaim() ||
                    $policy->getLatestSubmittedClaim() ||
                    $policy->getLatestInReviewClaim()
                ) {
                    return $this->redirectToRoute('user_claim_policy', ['policyId' => $policy->getId()]);
                }
            }
            throw $this->createNotFoundException('No active claimable policy found');
        }
        // check if there is a cached fnol object for this user.
        $dm = $this->getManager();
        /** @var ClaimsService $claimsService */
        $claimsService = $this->get("app.claims");
        /** @var PolicyRepository $policyRepository */
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = null;
        $fnol = $claimsService->findCachedFnol($user);
        $cachedFnol = true;
        if (!$fnol) {
            $cachedFnol = false;
            $fnol = new ClaimFnol();
            $fnol->setUser($user);
        } else {
            $policy = $policyRepo->find($fnol->getPolicyNumber());
        }
        $fnolConfirm = clone $fnol;
        $claimForm = $this->get('form.factory')
            ->createNamedBuilder('claim_form', ClaimFnolType::class, $fnol)
            ->getForm();
        $claimConfirmForm = $this->get('form.factory')
            ->createNamedBuilder('claim_confirm_form', ClaimFnolConfirmType::class, $fnolConfirm)
            ->getForm();
        // Process forms if they have been submitted.
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claim_form')) {
                $claimForm->handleRequest($request);
                if ($claimForm->isValid()) {
                    /** @var PhonePolicy $policy */
                    $policy = $policyRepo->find($fnol->getPolicyNumber());
                    if (!$policy) {
                        throw $this->createNotFoundException('Policy not found');
                    }
                    $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
                    if (in_array($fnol->getType(), [Claim::TYPE_LOSS, Claim::TYPE_THEFT]) &&
                        !$policy->isAdditionalClaimLostTheftApprovedAllowed()) {
                        // @codingStandardsIgnoreStart
                        $this->addFlash(
                            'error',
                            'Sorry, but we are unable to accept an additional claim on your policy for loss or theft '.
                            'as you already have had 2 successful claims'
                        );
                        // @codingStandardsIgnoreEnd
                    } else {
                        // create and cache fnol object, then see where we need to go next.
                        $claimsService->cacheFnol($fnol);
                        if ($policy->fullPremiumToBePaidForClaim(new \DateTime(), $fnol->getType())) {
                            // if the full premium is required then we send them to the payent page.
                            return $this->redirectToRoute('user_claim_pay', ["policyId" => $policy->getId()]);
                        } else {
                            // if the full premium is not required then we run the request again with the fnol cached.
                            $cachedFnol = true;
                            $fnolConfirm = $claimsService->findCachedFnol($policy->getUser());
                            $claimConfirmForm = $this->get('form.factory')
                                ->createNamedBuilder('claim_confirm_form', ClaimFnolConfirmType::class, $fnolConfirm)
                                ->getForm();
                        }
                    }
                }
            } elseif ($request->request->has('claim_confirm_form')) {
                $claimConfirmForm->handleRequest($request);
                if ($claimConfirmForm->isValid()) {
                    /** @var PhonePolicy $policy */
                    $policy = $policyRepo->find($fnol->getPolicyNumber());
                    if (!$policy) {
                        throw $this->createNotFoundException('Policy not found');
                    }
                    $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
                    $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);
                    $claim = $claimsService->createClaim($claimConfirmForm->getData());
                    $claim->setPolicy($policy);
                    $policy->addClaim($claim);
                    $claimsService->notifyFnolSubmission($claim);
                    $dm->flush();
                    $claimsService->uncacheFnol($user);
                    return $this->redirectToRoute('user_claim_policy', ['policyId' => $policy->getId()]);
                }
            }
        }
        // Render one of the two tabs.
        $data = [];
        if ($cachedFnol) {
            if ($policy->fullPremiumToBePaidForClaim(new \DateTime(), $fnol->getType())) {
                return $this->redirectToRoute('user_claim_pay', ['policyId' => $policy->getId()]);
            }
            $phoneRecord = $policy->getPhone();
            $phone = sprintf("%s %s (IMEI %s)", $phoneRecord->getMake(), $phoneRecord->getModel(), $policy->getImei());
            $data = [
                'current' => 'claim-confirm',
                'username' => $user->getName(),
                'claim_type' => $fnol->getTypeString(),
                'policy_date' => $policy->getStart(),
                'claim_form' => $claimForm->createView(),
                'claim_confirm_form' => $claimConfirmForm->createView(),
                'warn_pay' => $policy->fullPremiumToBePaidForClaim(new \DateTime(), Claim::TYPE_THEFT)
            ];
        } else {
            $data = [
                'current' => 'claim',
                'username' => $user->getName(),
                'claim_form' => $claimForm->createView(),
                'warn_pay' => $user->hasPolicyForFullPaymentClaim(new \DateTime())
            ];
        }
        return $this->render('AppBundle:User:claim.html.twig', $data);
    }

    /**
     * @Route("/claim-pay/{policyId}", name="user_claim_pay")
     * @Template
     */
    public function claimPayAction($policyId)
    {
        $policyRepo = $this->getManager()->getRepository(Policy::class);
        $policy = $policyRepo->find($policyId);
        if (!$policy) {
            throw $this->createNotFoundException('Unknown policy');
        }
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        $amount = $policy->getRemainderOfPolicyPrice();
        if ($amount <= 0) {
            return $this->redirectToRoute("user_claim");
        }
        return [
            'amount' => $amount,
            'policy' => $policy,
            'card_provider' => SoSure::PAYMENT_PROVIDER_CHECKOUT
        ];
    }

    /**
     * @Route("/claim/{policyId}", name="user_claim_policy")
     * @Template
     */
    public function claimPolicyAction($policyId)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = $policyRepo->find($policyId);

        if (!$policy) {
            return $this->redirectToRoute('user_claim');
        }

        $claim = $policy->getLatestFnolSubmittedInReviewClaim();

        if ($claim === null) {
            return $this->redirectToRoute('user_claim');
        }

        $this->denyAccessUnlessGranted(ClaimVoter::EDIT, $claim);

        if (in_array($claim->getStatus(), array(Claim::STATUS_SUBMITTED, Claim::STATUS_INREVIEW))) {
            return $this->redirectToRoute('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        }
        if ($claim->getType() == Claim::TYPE_DAMAGE) {
            return $this->redirectToRoute('claimed_damage_policy', ['policyId' => $policy->getId()]);
        }
        if ($claim->getType() == Claim::TYPE_THEFT || $claim->getType() == Claim::TYPE_LOSS) {
            return $this->redirectToRoute('claimed_theftloss_policy', ['policyId' => $policy->getId()]);
        }

        return $this->redirectToRoute('user_claim');
    }

    /**
     * @Route("/claim/submitted/{policyId}", name="claimed_submitted_policy")
     * @Template
     */
    public function claimSubmittedPolicyAction(Request $request, $policyId)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $policyRepo->find($policyId);

        if (!$policy) {
            return $this->redirectToRoute('user_claim');
        }

        $claim = $policy->getLatestFnolSubmittedInReviewClaim();

        if ($claim === null || !in_array($claim->getStatus(), array(Claim::STATUS_SUBMITTED, Claim::STATUS_INREVIEW))) {
            return $this->redirectToRoute('user_claim');
        }

        $this->denyAccessUnlessGranted(ClaimVoter::EDIT, $claim);

        $claimFnolUpdate = new ClaimFnolUpdate();
        $claimFnolUpdate->setClaim($claim);

        $claimUpdateForm = $this->get('form.factory')
            ->createNamedBuilder('claim_update_form', ClaimFnolUpdateType::class, $claimFnolUpdate)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            try {
                if ($request->request->has('claim_update_form')) {
                    $claimUpdateForm->handleRequest($request);
                    if ($claimUpdateForm->isValid()) {
                        /** @var ClaimsService $claimsService */
                        $claimsService = $this->get('app.claims');
                        $claimsService->updateDocuments($claim, $claimFnolUpdate);
                        $this->addFlash(
                            'success',
                            'Your claim has been updated.'
                        );
                        return $this->redirectToRoute('user_claim_policy', ['policyId' => $policy->getId()]);
                    }
                }
            } catch (\Exception $e) {
                $this->get('logger')->error(
                    sprintf('claimSubmittedPolicyAction %s', $policyId),
                    ['exception' => $e]
                );
                $this->addFlash(
                    'error',
                    'Sorry, there was a system error. Our engineers are investigating.'
                );
            }
        }

        // older unresolved davies claims are problematic. but don't give away too much info in case of fraud
        // intentions
        if ($claim->getNotificationDate()) {
            $old = $this->now()->diff($claim->getNotificationDate())->days;
            if ($old > 45) {
                // @codingStandardsIgnoreStart
                $this->addFlash(
                    'warning-raw',
                    'This appears to be an older claim that was never resolved. Please contact '.
                    '<a href="mailto:support@wearesosure.com">support@wearesosure.com</a> for help'
                );
                // @codingStandardsIgnoreEnd
            }
        }

        $data = [
            'claim' => $claim,
            'claim_phone' => $claim->getPhonePolicy() ? $claim->getPhonePolicy()->getPhone()->__toString() : 'Unknown',
            'time' => $this->getClaimResponseTime(),
            'claim_form' => $claimUpdateForm->createView(),
            'is_in_review' => $claim->getStatus() == Claim::STATUS_INREVIEW,
        ];

        return $this->render('AppBundle:User:claimSubmitted.html.twig', $data);
    }

    /**
     * @Route("/claim/damage/{policyId}", name="claimed_damage_policy")
     * @Template
     */
    public function claimDamagePolicyAction(Request $request, $policyId)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $policyRepo->find($policyId);

        if (!$policy) {
            return $this->redirectToRoute('user_claim');
        }

        $claim = $policy->getLatestFnolClaim();

        if ($claim === null) {
            return $this->redirectToRoute('user_claim');
        }
        if ($claim->getStatus() == Claim::STATUS_SUBMITTED) {
            return $this->redirectToRoute('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        }
        if ($claim->getType() != Claim::TYPE_DAMAGE) {
            return $this->redirectToRoute('user_claim_policy', ['policyId' => $policy->getId()]);
        }

        $this->denyAccessUnlessGranted(ClaimVoter::EDIT, $claim);

        $claimFnolDamage = new ClaimFnolDamage();
        $claimFnolDamage->setClaim($claim);

        $claimDamageForm = $this->get('form.factory')
            ->createNamedBuilder('claim_damage_form', ClaimFnolDamageType::class, $claimFnolDamage)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            /** @var ClaimsService $claimsService */
            $claimsService = $this->get('app.claims');
            if ($request->request->has('claim_damage_form')) {
                try {
                    $claimDamageForm->handleRequest($request);
                    if ($claimDamageForm->get('save')->isClicked()) {
                        $claimsService->updateDamageDocuments($claim, $claimFnolDamage);
                        $this->addFlash(
                            'success',
                            'Your claim has been saved.'
                        );
                    } elseif ($claimDamageForm->isValid()) {
                        $claimsService->updateDamageDocuments($claim, $claimFnolDamage, true);
                        return $this->redirectToRoute('user_claim_policy', ['policyId' => $policy->getId()]);
                    }
                } catch (\Exception $e) {
                    $this->get('logger')->error(
                        sprintf('claimDamagePolicyAction %s', $policyId),
                        ['exception' => $e]
                    );
                    $this->addFlash(
                        'error',
                        'Sorry, there was a system error. Our engineers are investigating.'
                    );
                }
            }
        }

        $data = [
            'username' => $user->getName(),
            'claim' => $claim,
            'claim_form' => $claimDamageForm->createView(),
        ];
        return $this->render('AppBundle:User:claimDamage.html.twig', $data);
    }

    /**
     * @Route("/claim/theftloss/{policyId}", name="claimed_theftloss_policy")
     * @Template
     */
    public function claimTheftLossPolicyAction(Request $request, $policyId)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(Policy::class);
        $policy = $policyRepo->find($policyId);

        if (!$policy) {
            return $this->redirectToRoute('user_claim');
        }

        $claim = $policy->getLatestFnolClaim();

        if ($claim === null) {
            return $this->redirectToRoute('user_claim');
        }
        if ($claim->getStatus() == Claim::STATUS_SUBMITTED) {
            return $this->redirectToRoute('claimed_submitted_policy', ['policyId' => $policy->getId()]);
        }
        if (!($claim->getType() == Claim::TYPE_THEFT || $claim->getType() == Claim::TYPE_LOSS)) {
            return $this->redirectToRoute('user_claim_policy', ['policyId' => $policy->getId()]);
        }

        $this->denyAccessUnlessGranted(ClaimVoter::EDIT, $claim);

        $claimFnolTheftLoss = new ClaimFnolTheftLoss();
        $claimFnolTheftLoss->setClaim($claim);

        $claimTheftLossForm = $this->get('form.factory')
            ->createNamedBuilder('claim_theftloss_form', ClaimFnolTheftLossType::class, $claimFnolTheftLoss)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            /** @var ClaimsService $claimsService */
            $claimsService = $this->get('app.claims');
            if ($request->request->has('claim_theftloss_form')) {
                $claimTheftLossForm->handleRequest($request);
                try {
                    if ($claimTheftLossForm->get('save')->isClicked()) {
                        $claimsService->updateTheftLossDocuments($claim, $claimFnolTheftLoss);
                        $this->addFlash(
                            'success',
                            'Your claim has been saved.'
                        );
                    } elseif ($claimTheftLossForm->isValid()) {
                        $claimsService->updateTheftLossDocuments($claim, $claimFnolTheftLoss, true);
                        return $this->redirectToRoute('user_claim_policy', ['policyId' => $policy->getId()]);
                    }
                } catch (\Exception $e) {
                    $this->get('logger')->error(
                        sprintf('claimTheftLossPolicyAction %s', $policyId),
                        ['exception' => $e]
                    );
                    $this->addFlash(
                        'error',
                        'Sorry, there was a system error. Our engineers are investigating.'
                    );
                }
            }
        }

        $data = [
            'username' => $user->getName(),
            'claim' => $claim,
            'claimType' => $claim->getType(),
            'network' => $claim->getNetwork(),
            'claim_form' => $claimTheftLossForm->createView(),
        ];
        if ($claim->getType() == Claim::TYPE_LOSS) {
            return $this->render('AppBundle:User:claimLoss.html.twig', $data);
        }
        if ($claim->getType() == Claim::TYPE_THEFT) {
            return $this->render('AppBundle:User:claimTheft.html.twig', $data);
        }

        throw new \Exception(sprintf('Unknown claim type %s', $claim->getType()));
    }

    /**
     * @Route("/upgrade/policy/{policyId}", name="user_upgrade")
     * @Method({"GET","POST"})
     * @template
     */
    public function upgradePolicyAction(Request $request, $policyId)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $policy */
        $policy = $policyRepo->find($policyId);

        // Check if the policy belongs to the user and is elligible for update
        if ($policy) {
            $this->denyAccessUnlessGranted(PolicyVoter::UPGRADE, $policy);
        }

        //Build the phone selection dropdown
        $phoneSpecs = new PhoneMake();
        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('form_upgrade_phone', PhoneMakeType::class, $phoneSpecs, [
                'action' => $this->generateUrl('user_upgrade', ['policyId' => $policyId])
            ])
            ->getForm();

        //Handle the upgrade quote request
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('form_upgrade_phone')) {
                $formPhone->handleRequest($request);
                /** @var PhoneRepository $phoneRepo */
                $phoneRepo = $dm->getRepository(Phone::class);
                $phoneId = $this->getDataString($request->get('form_upgrade_phone'), 'memory');
                /** @var Phone $phone */
                $phone = $phoneRepo->find($phoneId);

                if (!$phone) {
                    $this->get('logger')->error(sprintf('Phone not found: %s.', $phoneId));
                    $this->addFlash('error', 'Phone not found, please contact support');
                    return $this->redirectToRoute('user_upgrade', ['policyId' => $policyId]);
                } else {
                    // Save the policy to upgrade and the new phone in the session
                    $session = $request->getSession();
                    $session->set('upgrade-phone', $phone->getId());
                    $session->set('upgrade-policy', $policy->getId());

                    return $this->redirectToRoute('user_upgrade_quote');
                }
            }
        }

        $template = 'AppBundle:Upgrade:upgradePhone.html.twig';

        $data = [
            'policy' => $policy,
            'upgrade_quote_form' => $formPhone->createView(),
            'phones' => $this->getPhonesArray()
        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/upgrade/quote", name="user_upgrade_quote")
     * @Method({"GET"})
     * @template
     */
    public function upgradePolicyQuote(Request $request)
    {
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        $session = $request->getSession();

        /** @var HelvetiaPhonePolicy $policy */
        $policy = $policyRepo->find($session->get('upgrade-policy'));
        $newPhone = $phoneRepo->find($session->get('upgrade-phone'));

        if (!$policy) {
            $this->addFlash('warning', 'No policy Found : Please select a phone to upgrade');
            return $this->redirectToRoute('user_policy_list');
        } elseif (!$newPhone) {
            $this->addFlash('warning', 'No new phone found : Please use the dropdowns to search for your new phone');
            return $this->redirectToRoute('user_upgrade', ['policyId' => $policy->getId()]);
        }

        $oldPhone = $policy->getPhone();


        $now = new \DateTime();
        $priceService = $this->get("app.price");

        $stream = PhonePrice::installmentsStream($policy->getPremiumInstallments());
        $futurePayments = $policy->countFutureInvoiceSchedule();
        if ($futurePayments <= 1) {
            $newPhonePremium = $priceService->getPhonePremium($policy, $newPhone, $stream, null, $now);
            $upgradePremium = $policy->getPremiumUpgradeCostYearly($newPhonePremium, $now);
        } else {
            $newPhonePremium = $priceService->getPhonePremium($policy, $newPhone, $stream, null, $now);
            $upgradePremium = $policy->getPremiumUpgradeCostMonthly($newPhonePremium, $now);
        }

        $oldPhonePremium = $priceService->getPhonePolicyPremium($policy, $stream, null, $now);

        $session->set('upgrade-premium', $upgradePremium);
        $session->set('upgrade-stream', $stream);
        $session->set('new-phone-premium', $newPhonePremium);

        $template = 'AppBundle:Upgrade:upgradeQuote.html.twig';

        $data = [
            'policy' => $policy,
            'new_phone_premium' => $newPhonePremium,
            'old_phone_premium' => $oldPhonePremium,
            'upgraded_premium' => $upgradePremium,
            'stream' => $stream,
            'new_phone' => $newPhone,
            'old_phone' => $oldPhone

        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/upgrade/imei", name="user_upgrade_imei")
     * @Method({"GET", "POST"})
     * @template
     */
    public function upgradePolicyImei(Request $request)
    {
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        $session = $request->getSession();
        /** @var HelvetiaPhonePolicy $policy */
        $policy = $policyRepo->find($session->get('upgrade-policy'));
        /** @var Phone $newPhone */
        $newPhone = $phoneRepo->find($session->get('upgrade-phone'));

        if (!$policy) {
            $this->addFlash('warning', 'No policy Found : Please select a phone to upgrade');
            return $this->redirectToRoute('user_policy_list');
        } elseif (!$newPhone) {
            $this->addFlash('warning', 'No new phone found : Please use the dropdowns to search for your new phone');
            return $this->redirectToRoute('user_upgrade', ['policyId' => $policy->getId()]);
        }

        $phoneImei = new PurchaseStepPhone();

        $phoneImei->setPhone($newPhone);

        /** @var Form $purchaseForm */
        $imeiForm = $this->get('form.factory')
            ->createNamedBuilder('form_upgrade_imei', PurchaseStepPhoneType::class, $phoneImei)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('form_upgrade_imei')) {
                $user = $this->getUser();
                $imeiForm->handleRequest($request);
                if ($imeiForm->isValid()) {
                    $allowContinue = true;
                    $imei = $phoneImei->getImei();
                    $serialNumber = $phoneImei->getSerialNumber();
                    $policyService = $this->get('app.policy');
                    try {
                        $checkmend = $policyService->validateUpgradeImei($user, $newPhone, $imei, $serialNumber);
                        $session->set('upgrade-checkmend', $checkmend);
                    } catch (DuplicateImeiException $e) {
                        $this->addFlash(
                            'error',
                            "Sorry, your phone is already in our system. Perhaps it's already insured?"
                        );
                        $allowContinue = false;
                    } catch (LostStolenImeiException $e) {
                        $this->addFlash(
                            'error',
                            "Sorry, it looks this phone is already insured"
                        );
                        $allowContinue = false;
                    } catch (ImeiBlacklistedException $e) {
                        $this->addFlash(
                            'error',
                            "Sorry, we are unable to insure you."
                        );
                        $allowContinue = false;
                    } catch (InvalidImeiException $e) {
                        $this->addFlash(
                            'error',
                            "Looks like the IMEI you provided isn't quite right.  Please check the number again."
                        );
                        $allowContinue = false;
                    } catch (ImeiPhoneMismatchException $e) {
                        // @codingStandardsIgnoreStart
                        $this->addFlash(
                            'error',
                            "Looks like phone model you selected isn't quite right. Please check that you selected the correct model."
                        );
                        // @codingStandardsIgnoreEnd
                        $allowContinue = false;
                    } catch (RateLimitException $e) {
                        $this->addFlash(
                            'error',
                            "Sorry, we are unable to insure you."
                        );
                        $allowContinue = false;
                    }
                    if ($allowContinue) {
                        $session->set('upgrade-imei', $imei);
                        $session->set('upgrade-serialNumber', $serialNumber);
                        return $this->redirectToRoute('user_upgrade_pledge');
                    }
                }
            }
        }

        $template = 'AppBundle:Upgrade:upgradeImei.html.twig';

        $data = [
            'upgrade_imei_form' => $imeiForm->createView(),
            'new_phone' => $newPhone
        ];

        return $this->render($template, $data);


    }

    /**
     * @Route("/upgrade/pledge", name="user_upgrade_pledge")
     * @template
     */
    public function upgradePolicyPledge(Request $request)
    {
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        $session = $request->getSession();

        /** @var HelvetiaPhonePolicy $policy */
        $policy = $policyRepo->find($session->get('upgrade-policy'));
        /** @var Phone $newPhone */
        $newPhone = $phoneRepo->find($session->get('upgrade-phone'));
        $imei = $session->get('upgrade-imei');

        if (!$policy) {
            $this->addFlash('warning', 'No policy Found : Please select a phone to upgrade');
            return $this->redirectToRoute('user_policy_list');
        } elseif (!$newPhone) {
            $this->addFlash('warning', 'No new phone found : Please use the dropdowns to search for your new phone');
            return $this->redirectToRoute('user_upgrade', ['policyId' => $policy->getId()]);
        } elseif (!$imei) {
            $this->addFlash('warning', 'No imei found : Please provide your new phone\'s imei information');
            return $this->redirectToRoute('user_upgrade_imei');
        }

        $validationRequired = $policy->getPolicyTerms()->isPicSureRequired();

        $pledge = new PurchaseStepPledge();

        /** @var FormInterface $pledgeForm */
        $pledgeForm = $this->get('form.factory')
            ->createNamedBuilder('form_upgrade_pledge', PurchaseStepPledgeType::class, $pledge)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('form_upgrade_pledge')) {
                $pledgeForm->handleRequest($request);

                if ($pledgeForm->isValid() && $pledge->areAllAgreed()) {
                    $session->set('upgrade-pledge', true);
                    return new RedirectResponse(
                        $this->generateUrl('user_upgrade_payment')
                    );
                }
            }
        }

        $template = 'AppBundle:Upgrade:upgradePledge.html.twig';

        $data = [
            'upgrade_pledge_form' => $pledgeForm->createView(),
            'policy' => $policy,
            'new_phone' => $newPhone,
            'validation_required' => $validationRequired
        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/upgrade/payment", name="user_upgrade_payment")
     * @template
     */
    public function upgradePolicyPayment(Request $request)
    {
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        $session = $request->getSession();
        /** @var HelvetiaPhonePolicy $policy */
        $policy = $policyRepo->find($session->get('upgrade-policy'));
        $newPhone = $phoneRepo->find($session->get('upgrade-phone'));
        $premium = $session->get('upgrade-premium');
        $stream = $session->get('upgrade-stream');
        $imei = $session->get('upgrade-imei');
        $pledge = $session->get('upgrade-pledge');

        if (!$policy) {
            $this->addFlash('warning', 'No policy Found : Please select a phone to upgrade');
            return $this->redirectToRoute('user_policy_list');
        } elseif (!$newPhone || !$premium) {
            $this->addFlash('warning', 'No new phone found : Please use the dropdowns to search for your new phone');
            return $this->redirectToRoute('user_upgrade', ['policyId' => $policy->getId()]);
        } elseif (!$imei) {
            $this->addFlash('warning', 'No imei found : Please provide your new phone\'s imei information');
            return $this->redirectToRoute('user_upgrade_imei');
        } elseif (!$pledge) {
            $this->addFlash('warning', 'Please complete the pledge  of honesty');
            return $this->redirectToRoute('user_upgrade_pledge');
        }

        $template = 'AppBundle:Upgrade:upgradePayment.html.twig';

        $data = [
            'policy' => $policy,
            'premium' => $premium,
            'stream' => $stream,
            'new_phone' => $newPhone

        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/upgrade/confirmation", name="user_upgrade_confirmation")
     * @template
     */
    public function upgradePolicyConfirmation(Request $request)
    {
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        $session = $request->getSession();
        /** @var HelvetiaPhonePolicy $policy */
        $policy = $policyRepo->find($session->get('upgrade-policy'));
        /** @var Phone $newPhone */
        $newPhone = $phoneRepo->find($session->get('upgrade-phone'));
        $premium = $session->get('new-phone-premium');
        $stream = $session->get('upgrade-stream');
        $imei = $session->get('upgrade-imei');
        $serial  = $session->get('upgrade-serialNumber');
        $pledge = $session->get('upgrade-pledge');

        if (!$policy) {
            $this->addFlash('warning', 'No policy Found : Please select a phone to upgrade');
            return $this->redirectToRoute('user_policy_list');
        } elseif (!$newPhone) {
            $this->addFlash('warning', 'No new phone found : Please use the dropdowns to search for your new phone');
            return $this->redirectToRoute('user_upgrade', ['policyId' => $policy->getId()]);
        } elseif (!$imei) {
            $this->addFlash('warning', 'No imei found : Please provide your new phone\'s imei information');
            return $this->redirectToRoute('user_upgrade_imei');
        } elseif (!$pledge) {
            $this->addFlash('warning', 'Please complete the pledge  of honesty');
            return $this->redirectToRoute('user_upgrade_pledge');
        }

        $upgradeService = $this->get("app.upgrade");
        $upgradeSuccess = true;
        try {
            $upgradeService->upgrade($policy, $newPhone, $imei, $serial, new \DateTime(), $premium);
        } catch (\Exception $e) {
            $upgradeSuccess = false;
            $this->get('logger')->error(sprintf('Error while upgrading the policy %s.', $e));
            $this->addFlash('error', 'Error while upgrading the policy, please contact the support');
            return $this->redirectToRoute('user_home');
        }
        $this->addFlash(
            'success',
            sprintf('Your policy was successfuly upgraded to cover your %s', $newPhone->getModel())
        );

        return new RedirectResponse(
            $this->generateUrl('user_policy_list')
        );
    }

    /**
     * @Route("/fb", name="user_facebook")
     * @Template
     */
    public function fbAction(Request $request)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $facebook = $this->get('app.facebook');
        $facebook->init($this->getUser());
        if ($redirect = $this->ensureFacebookPermission(
            $facebook,
            'user_friends',
            ['user_friends', 'email']
        )) {
            return $redirect;
        }

        $friends = null;
        $session = $request->getSession();
        if ($session) {
            if (!$friends = $session->get('friends')) {
                $friends = $facebook->getAllFriends();
                $session->set('friends', $friends);
            }
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
     * Allows users to pay for the whole of their premium at once, either because they are unpaid or for other reasons.
     * @Route("/remainder/{id}", name="purchase_remainder_policy")
     * @Template
     */
    public function purchaseRemainderPolicyAction($id)
    {
        $policyRepo = $this->getManager()->getRepository(Policy::class);
        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Unknown policy');
        }
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        $amount = $policy->getRemainderOfPolicyPrice();
        $webpay = null;
        return [
            'phone' => $policy->getPhone(),
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
            'amount' => $amount,
            'policy' => $policy,
            'card_provider' => SoSure::PAYMENT_PROVIDER_CHECKOUT
        ];
    }

    /**
     * @Route("/cancel/{id}", name="user_cancel")
     * @Route("/cancel/damaged/{id}", name="user_cancel_damaged")
     * @Template
     */
    public function cancelAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Unable to see policy');
        }
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        if (!$policy->hasViewedCancellationPage()) {
            $policy->setViewedCancellationPage(\DateTime::createFromFormat('U', time()));
            $dm->flush();
        }
        $cancelForm = $this->get('form.factory')
            ->createNamedBuilder('cancel_form', UserCancelType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cancel_form')) {
                $cancelForm->handleRequest($request);
                if ($cancelForm->isValid()) {
                    $reason = $cancelForm->getData()['reason'];
                    $other = $this->conformAlphanumericSpaceDot($cancelForm->getData()['othertxt'], 256);
                    $flash = null;
                    $canCancelCooloff = $policy->canCancel(Policy::CANCELLED_COOLOFF, null, false, false);
                    if ($canCancelCooloff) {
                        $policy->setRequestedCancellation(\DateTime::createFromFormat('U', time()));
                        $policy->setRequestedCancellationReason($reason);
                        if ($other) {
                            $policy->setRequestedCancellationReasonOther($other);
                        }
                        $this->get("app.policy")->cancel($policy, Policy::CANCELLED_COOLOFF);
                        $note = new StandardNote();
                        $note->setDate(new \DateTime());
                        $note->setUser($policy->getUser());
                        $note->setNotes("Policy auto cancelled in cooloff.");
                        $policy->addNotesList($note);
                        $dm->flush();
                        $flash = "You should receive an email confirming that your policy is now cancelled.";
                        $this->get("app.stats")->increment(Stats::AUTO_CANCEL_IN_COOLOFF);
                    } elseif (!$policy->hasRequestedCancellation()) {
                        // @codingStandardsIgnoreStart
                        $flash = "We have passed your request to our policy team. You should receive a cancellation ".
                           "email once that is processed.";
                        $message = "This is a so-sure generated message. Policy: <a href='%s'>%s/%s</a> requested a ".
                            "cancellation via the site. %s was given as the reason. so-sure support team: Please ".
                            "contact the policy holder to get their reason(s) for cancelling before action. ".
                            "Additional comments: %s";
                        // @codingStandardsIgnoreEnd
                        $policy->setRequestedCancellation(\DateTime::createFromFormat('U', time()));
                        $policy->setRequestedCancellationReason($reason);
                        if ($other) {
                            $policy->setRequestedCancellationReasonOther($other);
                        }
                        $dm->flush();
                        $intercom = $this->get('app.intercom');
                        $intercom->queueMessage(
                            $policy->getUser()->getEmail(),
                            sprintf(
                                $message,
                                $this->generateUrl(
                                    'admin_policy',
                                    ['id' => $policy->getId()],
                                    UrlGeneratorInterface::ABSOLUTE_URL
                                ),
                                $policy->getPolicyNumber(),
                                $policy->getId(),
                                $reason,
                                $other
                            )
                        );
                    } else {
                        $this->addFlash(
                            "warning",
                            "Cancellation has already been requested and is currently processing."
                        );
                    }
                    if ($flash) {
                        $this->addFlash("success", $flash);
                        $this->get('app.mixpanel')->queueTrack(
                            MixpanelService::EVENT_REQUEST_CANCEL_POLICY,
                            [
                                'Policy Id' => $policy->getId(),
                                'Reason' => $reason,
                                'Auto Approved' => $canCancelCooloff
                            ]
                        );
                    }
                    return $this->redirectToRoute('user_cancel_requested', ['id' => $id]);
                }
            }
        } else {
            $this->get('app.mixpanel')->queueTrack(
                MixpanelService::EVENT_CANCEL_POLICY_PAGE,
                ['Policy Id' => $policy->getId()]
            );
        }
        if ($request->get('_route') == "user_cancel_damaged") {
            $template = 'AppBundle:User:cancelDamaged.html.twig';
        } else {
            $template = 'AppBundle:User:cancel.html.twig';
        }
        $data = ['policy' => $policy, 'cancel_form' => $cancelForm->createView()];
        return $this->render($template, $data);
    }

    /**
     * @Route("/cancel/{id}/requested", name="user_cancel_requested")
     * @Template
     */
    public function cancelRequestedAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Unable to see policy');
        }
        $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);
        return [
            'policy' => $policy,
        ];
    }

    private function getFacebookFriends($request, $policy)
    {
        $user = $this->getUser();
        if ($user->getFacebookId() === null) {
            return null;
        }

        $friends = null;
        $facebook = $this->get('app.facebook');
        $facebook->init($user);
        if ($this->ensureFacebookPermission(
            $facebook,
            'user_friends',
            ['user_friends', 'email']
        ) == null) {
            $session = $request->getSession();
            if ($session) {
                if (!$friends = $session->get('friends')) {
                    $friends = array();
                    $facebookFriends = $facebook->getAllFriends();
                    foreach ($facebookFriends as $friend) {
                        if (!$policy->isFacebookUserInvited($friend['id']) &&
                            !$policy->isFacebookUserConnected($friend['id'])
                        ) {
                            $friends[] = $friend;
                        }
                    }
                    $session->set('friends', $friends);
                }
            }
        }
        return $friends;
    }

    /**
     * @param FacebookService $fb
     * @param string          $requiredPermission
     * @param array           $allPermissions
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
