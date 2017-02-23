<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Session\Session;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Payment;
use AppBundle\Document\User;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Form\Purchase;
use AppBundle\Document\Form\PurchaseStepPersonalAddress;
use AppBundle\Document\Form\PurchaseStepPersonal;
use AppBundle\Document\Form\PurchaseStepAddress;
use AppBundle\Document\Form\PurchaseStepPhone;
use AppBundle\Document\Form\PurchaseStepPhoneNoPhone;
use AppBundle\Document\CurrencyTrait;

use AppBundle\Form\Type\BasicUserType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\PurchaseStepPersonalAddressType;
use AppBundle\Form\Type\PurchaseStepPersonalType;
use AppBundle\Form\Type\PurchaseStepAddressType;
use AppBundle\Form\Type\PurchaseStepPhoneType;
use AppBundle\Form\Type\PurchaseStepPhoneNoPhoneType;

use AppBundle\Service\MixpanelService;

use AppBundle\Security\UserVoter;

use AppBundle\Exception\InvalidPremiumException;
use AppBundle\Exception\InvalidUserDetailsException;
use AppBundle\Exception\GeoRestrictedException;
use AppBundle\Exception\DuplicateImeiException;
use AppBundle\Exception\LostStolenImeiException;
use AppBundle\Exception\InvalidImeiException;
use AppBundle\Exception\ImeiBlacklistedException;
use AppBundle\Exception\ImeiPhoneMismatchException;
use AppBundle\Exception\RateLimitException;

/**
 * @Route("/purchase")
 */
class PurchaseController extends BaseController
{
    use CurrencyTrait;

    /**
     * @Route("/step-personal", name="purchase_step_personal")
     * @Route("/", name="purchase")
     * @Template
    */
    public function purchaseStepPersonalAddressAction(Request $request)
    {
        $session = $request->getSession();
        $user = $this->getUser();
        if ($user && $user->hasPolicy()) {
            $this->addFlash('error', 'Sorry, but we currently only support 1 policy per email address.');

            return $this->redirectToRoute('user_home');
        }
        /*
        if ($user->getFirstName() && $user->getLastName() && $user->getMobileNumber() && $user->getBirthday()) {
            return $this->redirectToRoute('purchase_step_2');
        }
        */
        if ($user) {
            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);
        }

        $dm = $this->getManager();

        $purchase = new PurchaseStepPersonalAddress();
        if ($user) {
            $purchase->populateFromUser($user);
        } elseif ($session->get('email')) {
            $purchase->setEmail($session->get('email'));
        }
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPersonalAddressType::class, $purchase)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);
                if ($purchaseForm->isValid()) {
                    $userRepo = $dm->getRepository(User::class);
                    $userExists = $userRepo->existsAnotherUser(
                        $user,
                        $purchase->getEmail(),
                        null,
                        $purchase->getMobileNumber()
                    );
                    if ($userExists) {
                        $existingUser = $userRepo->findOneBy(['emailCanonical' => strtolower($purchase->getEmail())]);
                        // If the user didn't start a policy at all
                        // and all of their details match
                        // then just let the user proceeed as if they entered data for the first time
                        // however, make sure to clear out the address just in case to prevent
                        // data disclosure
                        if ($existingUser && count($existingUser->getPolicies()) == 0 &&
                            $purchase->matchesUser($existingUser)) {
                            $existingUser->clearBillingAddress();
                            $user = $existingUser;
                        } else {
                            $this->get('logger')->warning(sprintf(
                                '%s received a already have account error and was taken to the login page',
                                $purchase->getEmail()
                            ));
                            // @codingStandardsIgnoreStart
                            $err = 'It looks like you already have an account.  Please try logging in with your details';
                            // @codingStandardsIgnoreEnd
                            $this->addFlash('error', $err);

                            return $this->redirectToRoute('fos_user_security_login');
                        }
                    }

                    $newUser = false;
                    if (!$user) {
                        $userManager = $this->get('fos_user.user_manager');
                        $user = $userManager->createUser();
                        $user->setEnabled(true);
                        $newUser = true;
                    }
                    $purchase->populateUser($user);
                    if ($newUser) {
                        $dm->persist($user);
                    }
                    if (!$user->getIdentityLog()) {
                        $user->setIdentityLog($this->getIdentityLog($request));
                    }
                    $dm->flush();

                    if (!$user->hasValidDetails()) {
                        $this->get('logger')->error(sprintf(
                            'Invalid purchase user details %s',
                            json_encode($purchase->toApiArray())
                        ));
                        throw new \InvalidArgumentException(sprintf(
                            'User is missing details such as name, email address, or birthday (User: %s)',
                            $user->getId()
                        ));
                    }
                    if (!$user->hasValidBillingDetails()) {
                        $this->get('logger')->error(sprintf(
                            'Invalid purchase user billing details %s',
                            json_encode($purchase->toApiArray())
                        ));
                        throw new \InvalidArgumentException(sprintf(
                            'User is missing billing details (User: %s)',
                            $user->getId()
                        ));
                    }
                    // Register before login, so we still have old session id before login changes it
                    if ($newUser) {
                        $this->get('app.mixpanel')->register($user);
                    }

                    // TODO: Check if user is already logged in?
                    $this->get('fos_user.security.login_manager')->loginUser(
                        $this->getParameter('fos_user.firewall_name'),
                        $user
                    );

                    // Trigger login event
                    $token = $this->get('security.token_storage')->getToken();
                    $event = new InteractiveLoginEvent($request, $token);
                    $this->get("event_dispatcher")->dispatch("security.interactive_login", $event);

                    // Track after login, so we populate user
                    // Regardless of existing user or new user, track receive details (so funnel works)
                    $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_RECEIVE_DETAILS);

                    return $this->redirectToRoute('purchase_step_policy');
                }
            }
        }

        $data = array(
            'purchase_form' => $purchaseForm->createView(),
            'step' => 1,
            'phone' => $this->getSessionQuotePhone($request),
            'is_postback' => 'POST' === $request->getMethod(),
            'quote_url' => $session ? $session->get('quote_url') : null,
        );

        return $data;
    }

    /**
     * @Route("/step-policy", name="purchase_step_policy")
     * @Template
    */
    public function purchaseStepPhoneAction(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('purchase');
        } elseif ($user->hasPolicy()) {
            $this->addFlash('error', 'Sorry, but we currently only support 1 policy per email address.');

            return $this->redirectToRoute('user_home');
        }
        $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);
        if (!$user->hasValidBillingDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();

        $phone = $this->getSessionQuotePhone($request);

        $purchase = new PurchaseStepPhone();
        $purchase->setUser($user);

        $purchaseNoPhone = new PurchaseStepPhoneNoPhone();
        $purchase->setUser($user);

        $policy = $user->getUnInitPolicy();
        if ($policy) {
            if (!$phone && $policy->getPhone()) {
                $phone = $policy->getPhone();
            }
            $purchase->setImei($policy->getImei());
            $purchase->setSerialNumber($policy->getSerialNumber());
        }

        if ($phone) {
            $purchase->setPhone($phone);
        }

        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPhoneType::class, $purchase)
            ->getForm();
        $purchaseNoPhoneForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_no_phone_form', PurchaseStepPhoneNoPhoneType::class, $purchaseNoPhone)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);
                if ($purchaseForm->isValid()) {
                    if ($policy) {
                        // TODO: How can we preserve imei & make/model check results across policies
                        // If any policy data has changed, delete/re-create
                        if ($policy->getImei() != $purchase->getImei() ||
                            $policy->getSerialNumber() != $purchase->getSerialNumber() ||
                            $policy->getPhone()->getId() != $purchase->getPhone()->getId()) {
                            $dm->remove($policy);
                            $dm->flush();
                            $policy = null;
                        }
                    }

                    if (!$policy) {
                        try {
                            $policyService = $this->get('app.policy');
                            $policyService->setWarnMakeModelMismatch(false);
                            $policy = $policyService->init(
                                $user,
                                $purchase->getPhone(),
                                $purchase->getImei(),
                                $purchase->getSerialNumber(),
                                $this->getIdentityLogWeb($request)
                            );
                            $dm->persist($policy);
                        } catch (InvalidPremiumException $e) {
                            // Nothing the user can do, so rethow
                            throw $e;
                        } catch (InvalidUserDetailsException $e) {
                            $this->addFlash(
                                'error',
                                "Please check all your details.  It looks like we're missing something."
                            );
                            $allowPayment = false;
                        } catch (GeoRestrictedException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you. It looks like you're outside the UK."
                            );
                            throw $this->createNotFoundException('Unable to see policy');
                        } catch (DuplicateImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, it looks this phone is already insured"
                            );
                            $allowPayment = false;
                        } catch (LostStolenImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, it looks this phone is already insured"
                            );
                            $allowPayment = false;
                        } catch (ImeiBlacklistedException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        } catch (InvalidImeiException $e) {
                            $this->addFlash(
                                'error',
                                "Looks like the IMEI you provided isn't quite right.  Please check the number again."
                            );
                            $allowPayment = false;
                        } catch (ImeiPhoneMismatchException $e) {
                            // @codingStandardsIgnoreStart
                            $this->addFlash(
                                'error',
                                "Looks like phone model you selected isn't quite right. Please check that you selected the correct model."
                            );
                            // @codingStandardsIgnoreEnd
                            $allowPayment = false;
                        } catch (RateLimitException $e) {
                            $this->addFlash(
                                'error',
                                "Sorry, we are unable to insure you."
                            );
                            $allowPayment = false;
                        }
                    }
                    $dm->flush();
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_POLICY_READY, [
                        'Device Insured' => $purchase->getPhone()->__toString(),
                        'OS' => $purchase->getPhone()->getOs(),
                        'Final Monthly Cost' => $purchase->getPhone()->getCurrentPhonePrice()->getMonthlyPremiumPrice()
                    ]);

                    if ($this->areEqualToTwoDp(
                        $purchase->getAmount(),
                        $purchase->getPhone()->getCurrentPhonePrice()->getMonthlyPremiumPrice()
                    )) {
                        return $this->redirectToRoute('purchase_step_review_monthly');
                    } elseif ($this->areEqualToTwoDp(
                        $purchase->getAmount(),
                        $purchase->getPhone()->getCurrentPhonePrice()->getYearlyPremiumPrice()
                    )) {
                        return $this->redirectToRoute('purchase_step_review_yearly');
                    } else {
                        $this->addFlash(
                            'error',
                            "Please select the monthly or yearly option."
                        );
                    }
                }
            }
        }

        $data = array(
            'phone' => $phone,
            'purchase_form' => $purchaseForm->createView(),
            'purchase_no_phone_form' => $purchaseNoPhoneForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 2,
            'modal_type' => $phone ? 'purchase-change' : 'purchase-select',
        );

        return $data;
    }
    
    /**
     * @Route("/step-review/monthly", name="purchase_step_review_monthly")
     * @Route("/step-review/yearly", name="purchase_step_review_yearly")
     * @Template
    */
    public function purchaseStepReviewAction(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('purchase');
        } elseif ($user->hasPolicy()) {
            $this->addFlash('error', 'Sorry, but we currently only support 1 policy per email address.');

            return $this->redirectToRoute('user_home');
        }
        $policy = $user->getUnInitPolicy();
        if (!$policy) {
            return $this->redirectToRoute('purchase_step_policy');
        }

        $routeName = $request->get('_route');
        if ($routeName == "purchase_step_review_monthly") {
            $amount = $policy->getPremium()->getMonthlyPremiumPrice();
            $period = 'month';
        } elseif ($routeName == "purchase_step_review_yearly") {
            $amount = $policy->getPremium()->getYearlyPremiumPrice();
            $period = 'year';
        } else {
            throw new \Exception('Unknown route');
        }

        $webpay = $this->get('app.judopay')->webpay(
            $policy,
            $amount,
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        $data = [
            'phone' => $policy->getPhone(),
            'policy_key' => $this->getParameter('policy_key'),
            'webpay_action' => $webpay['post_url'],
            'webpay_reference' => $webpay['payment']->getReference(),
            'step' => 3,
            'is_postback' => 'POST' === $request->getMethod(),
            'amount' => $amount,
            'period' => $period,
        ];
        
        return $data;
    }

    /**
     * @Route("/cc/success", name="purchase_judopay_receive_success")
     * @Route("/cc/success/", name="purchase_judopay_receive_success_slash")
     * @Method({"POST"})
     */
    public function purchaseJudoPayReceiveSuccessAction(Request $request)
    {
        $this->get('logger')->info(sprintf(
            'Judo Web Success ReceiptId: %s Ref: %s',
            $request->get('ReceiptId'),
            $request->get('Reference')
        ));
        $user = $this->getUser();
        $dm = $this->getManager();
        $repo = $dm->getRepository(Payment::class);
        $payment = $repo->findOneBy(['reference' => $request->get('Reference')]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }

        if ($payment->getUser()->getId() != $this->getUser()->getId()) {
            throw new AccessDeniedException('Unknown user');
        }

        $judo = $this->get('app.judopay');
        $policy = $payment->getPolicy();

        // If there's no policy on the payment, then it's a card update
        if (!$policy) {
            $judo->updatePaymentMethod(
                $user,
                $request->get('ReceiptId'),
                null,
                $request->get('CardToken'),
                null
            );

            $this->addFlash(
                'success',
                sprintf('Your card has been updated')
            );

            return $this->redirectToRoute('user_card_details');
        } else {
            $judo->add(
                $policy,
                $request->get('ReceiptId'),
                null,
                $request->get('CardToken'),
                Payment::SOURCE_WEB,
                JudoPaymentMethod::DEVICE_DNA_NOT_PRESENT
            );
            if ($policy->isInitialPayment()) {
                return $this->redirectToRoute('user_welcome');
            } else {
                // unpaid policy - outstanding payment
                $this->addFlash(
                    'success',
                    sprintf(
                        'Thanks for your payment of £%0.2f',
                        $policy->getLastSuccessfulPaymentCredit()->getAmount()
                    )
                );

                return $this->redirectToRoute('user_home');
            }
        }

        // shouldn't occur
        return $this->redirectToRoute('user_home');
    }

    /**
     * @Route("/cc/fail", name="purchase_judopay_receive_fail")
     * @Route("/cc/fail/", name="purchase_judopay_receive_fail_slash")
     */
    public function purchaseJudoPayFailAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Payment::class);
        $reference = $request->get('Reference');
        if (!$reference) {
            $initPolicies = $this->getUser()->getInitPolicies();
            if (count($initPolicies) > 0) {
                $this->addFlash('warning', 'You seem to have a policy that you started creating, but is unpaid.');
                return $this->redirectToRoute('purchase_step_policy');
            }

            throw new \Exception('Unable to locate reference');
        }

        $payment = $repo->findOneBy(['reference' => $reference]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }

        if ($payment->getUser()->getId() != $this->getUser()->getId()) {
            throw new AccessDeniedException('Unknown user');
        }

        $this->addFlash('error', 'There was a problem processing your payment. You can try again.');
        $user = $this->getUser();
        if (!$user->hasActivePolicy()) {
            return $this->redirectToRoute('purchase_step_policy');
        } elseif ($user->hasUnpaidPolicy()) {
            return $this->redirectToRoute('user_unpaid_policy');
        } else {
            // would expect 1 of the 2 above - default back to user home just in case
            return $this->redirectToRoute('user_home');
        }
    }
}
