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
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use AppBundle\Classes\ApiErrorCode;

use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Payment;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\Form\Purchase;
use AppBundle\Document\Form\PurchaseStepPersonalAddress;
use AppBundle\Document\Form\PurchaseStepPersonal;
use AppBundle\Document\Form\PurchaseStepAddress;
use AppBundle\Document\Form\PurchaseStepPhone;
use AppBundle\Document\Form\PurchaseStepPhoneNoPhone;

use AppBundle\Form\Type\BasicUserType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\PurchaseStepPersonalAddressType;
use AppBundle\Form\Type\PurchaseStepPersonalType;
use AppBundle\Form\Type\PurchaseStepAddressType;
use AppBundle\Form\Type\PurchaseStepPhoneType;
use AppBundle\Form\Type\PurchaseStepPhoneNoPhoneType;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\JudopayService;

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
use AppBundle\Exception\ProcessedException;
use AppBundle\Exception\ValidationException;

/**
 * @Route("/purchase")
 */
class PurchaseController extends BaseController
{
    use CurrencyTrait;
    use DateTrait;

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-personal", name="purchase_step_personal")
     * @Route("/", name="purchase")
     * @Template
    */
    public function purchaseStepPersonalAddressAction(Request $request)
    {
        $session = $request->getSession();
        $user = $this->getUser();
        /* TODO: Consider if we want warning that you're purchasing additional policy
        if ($user && $user->hasPolicy()) {
            $this->addFlash('error', 'Sorry, but we currently only support 1 policy per email address.');
        }
        */
        /*
        if ($user->getFirstName() && $user->getLastName() && $user->getMobileNumber() && $user->getBirthday()) {
            return $this->redirectToRoute('purchase_step_2');
        }
        */
        if ($user) {
            $this->denyAccessUnlessGranted(UserVoter::EDIT, $user);
        }

        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = $this->getSessionQuotePhone($request);

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
                            $this->get('app.mixpanel')->queueTrack(
                                MixpanelService::EVENT_TEST,
                                ['Test Name' => 'Purchase Login Redirect']
                            );
                            $this->get('logger')->info(sprintf(
                                '%s received an already have account error and was taken to the login page',
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
                    $data = null;
                    if ($user->getFacebookId()) {
                        $data = [];
                        $data['Facebook'] = true;
                    }
                    $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_RECEIVE_DETAILS, $data);

                    if ($phone) {
                        return $this->redirectToRoute('purchase_step_policy');
                    } else {
                        return $this->redirectToRoute('purchase_step_phone_no_phone');
                    }
                }
            }
        }

        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
        $csrf = $this->get('security.csrf.token_manager');

        $data = array(
            'purchase_form' => $purchaseForm->createView(),
            'step' => 1,
            'phone' => $phone,
            'is_postback' => 'POST' === $request->getMethod(),
            'quote_url' => $session ? $session->get('quote_url') : null,
            'lead_csrf' => $csrf->refreshToken('lead'),
            'phones' => $phone ? $phoneRepo->findBy(
                ['active' => true, 'make' => $phone->getMake(), 'model' => $phone->getModel()],
                ['memory' => 'asc']
            ) : null,
        );

        return $this->render('AppBundle:Purchase:purchaseStepPersonalAddressNew.html.twig', $data);
    }

    /**
     * @Route("/step-missing-phone", name="purchase_step_phone_no_phone")
     * @Template
    */
    public function purchaseStepPhoneNoPhoneAction(Request $request)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('purchase');
        }
        $phone = $this->getSessionQuotePhone($request);
        if ($phone) {
            return $this->redirectToRoute('purchase_step_policy');
        }
        $purchaseNoPhone = new PurchaseStepPhoneNoPhone();
        $purchaseNoPhone->setUser($user);
        $purchaseNoPhoneForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_no_phone_form', PurchaseStepPhoneNoPhoneType::class, $purchaseNoPhone)
            ->getForm();

        $data = array(
            'phone' => $phone,
            'purchase_no_phone_form' => $purchaseNoPhoneForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 2,
            'phones' => $phone ? $phoneRepo->findBy(
                ['active' => true, 'make' => $phone->getMake(), 'model' => $phone->getModel()],
                ['memory' => 'asc']
            ) : null,
        );

        return $this->render('AppBundle:Purchase:purchaseStepPhoneNoPhoneNew.html.twig', $data);
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-policy", name="purchase_step_policy")
     * @Template
    */
    public function purchaseStepPhoneReviewAction(Request $request)
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('purchase');
        }
        /* TODO: Consider if we want warning that you're purchasing additional policy
        } elseif ($user->hasPolicy()) {
            $this->addFlash('error', 'Sorry, but we currently only support 1 policy per email address.');

            return $this->redirectToRoute('user_home');
        }
        */
        $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);
        if (!$user->hasValidBillingDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);

        $phone = $this->getSessionQuotePhone($request);

        $purchase = new PurchaseStepPhone();
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
            // Default to monthly payment
            if ('GET' === $request->getMethod()) {
                if ($user->allowedMonthlyPayments()) {
                    $purchase->setAmount($phone->getCurrentPhonePrice()->getMonthlyPremiumPrice());
                } elseif ($user->allowedYearlyPayments()) {
                    $purchase->setAmount($phone->getCurrentPhonePrice()->getYearlyPremiumPrice());
                }
            }
        }

        $purchase->setAgreed(true);
        $purchase->setNew(true);

        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPhoneType::class, $purchase)
            ->getForm();
        $webpay = null;
        $allowPayment = true;

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

                    if ($allowPayment) {
                        $monthly = $this->areEqualToTwoDp(
                            $purchase->getAmount(),
                            $purchase->getPhone()->getCurrentPhonePrice()->getMonthlyPremiumPrice()
                        );
                        $yearly = $this->areEqualToTwoDp(
                            $purchase->getAmount(),
                            $purchase->getPhone()->getCurrentPhonePrice()->getYearlyPremiumPrice()
                        );
    
                        if ($monthly || $yearly) {
                            $price = $purchase->getPhone()->getCurrentPhonePrice();
                            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_POLICY_READY, [
                                'Device Insured' => $purchase->getPhone()->__toString(),
                                'OS' => $purchase->getPhone()->getOs(),
                                'Final Monthly Cost' => $price->getMonthlyPremiumPrice(),
                                'Policy Id' => $policy->getId(),
                            ]);
                            if ($purchaseForm->get('next')->isClicked()) {
                                $webpay = $this->get('app.judopay')->webpay(
                                    $policy,
                                    $purchase->getAmount(),
                                    $request->getClientIp(),
                                    $request->headers->get('User-Agent'),
                                    JudopayService::WEB_TYPE_STANDARD
                                );
                                $purchase->setAgreed(true);
                            } elseif ($purchaseForm->get('existing')->isClicked()) {
                                // TODO: Try/catch
                                if ($this->get('app.judopay')->existing(
                                    $policy,
                                    $purchase->getAmount()
                                )) {
                                    $purchase->setAgreed(true);
                                    return $this->redirectToRoute('user_welcome');
                                } else {
                                    // @codingStandardsIgnoreStart
                                    $this->addFlash(
                                        'warning',
                                        "Sorry, there was a problem with your existing payment method. Try again, or use the Pay with new card option."
                                    );
                                    // @codingStandardsIgnoreEnd
                                }
                            }
                        } else {
                            $this->addFlash(
                                'error',
                                "Please select the monthly or yearly option."
                            );
                        }
                    }
                }
            }
        }

        $now = new \DateTime();
        $billingDate = $this->adjustDayForBilling($now);

        $data = array(
            'phone' => $phone,
            'purchase_form' => $purchaseForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 2,
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
            'policy_key' => $this->getParameter('policy_key'),
            'phones' => $phone ? $phoneRepo->findBy(
                ['active' => true, 'make' => $phone->getMake(), 'model' => $phone->getModel()],
                ['memory' => 'asc']
            ) : null,
            'billing_date' => $billingDate,
        );

        return $this->render('AppBundle:Purchase:purchaseStepPhoneReviewNew.html.twig', $data);
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
        $judo = $this->get('app.judopay');
        $repo = $dm->getRepository(Payment::class);
        $payment = $repo->findOneBy(['reference' => $request->get('Reference')]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }
        $policy = $payment->getPolicy();

        $webType = $judo->getTransactionWebType($request->get('ReceiptId'));
        // Metadata should be present, but if not, use older logic to guess at what type to use
        if (!$webType) {
            if (!$user) {
                // If there's not a user, it may be a payment for the remainder of the policy - go ahead and credit
                $webType = JudopayService::WEB_TYPE_REMAINDER;
            } elseif (!$policy) {
                $webType = JudopayService::WEB_TYPE_CARD_DETAILS;
            } else {
                $webType = JudopayService::WEB_TYPE_STANDARD;                
            }

            $this->get('logger')->warning(sprintf(
                'Unable to find web_type metadata for receipt %s. Falling back to %s',
                $request->get('ReceiptId'),
                $webType
            ));
        }

        if (in_array($webType, [JudopayService::WEB_TYPE_REMAINDER, JudopayService::WEB_TYPE_STANDARD])) {
            try {
                $judo->add(
                    $policy,
                    $request->get('ReceiptId'),
                    null,
                    $request->get('CardToken'),
                    Payment::SOURCE_WEB,
                    JudoPaymentMethod::DEVICE_DNA_NOT_PRESENT
                );
            } catch (ProcessedException $e) {
                if (!$policy->isValidPolicy($policy->getPolicyPrefix($this->getParameter('kernel.environment')))) {
                    throw $e;
                }
                $this->get('logger')->warning(sprintf(
                    'Duplicate re-use of judo receipt. Possible refresh issue, so ignoring and continuing',
                    ['exception' => $e]
                ));
            }

            if ($webType == JudopayService::WEB_TYPE_REMAINDER) {
                $this->notifyRemainderRecevied($policy);

                return $this->redirectToRoute('purchase_remainder_policy', ['id' => $policy->getId()]);
            } elseif ($policy->isInitialPayment()) {
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
        } elseif ($webType == JudopayService::WEB_TYPE_CARD_DETAILS) {
            $judo->updatePaymentMethod(
                $payment->getUser(),
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
        }

        // shouldn't occur
        return $this->redirectToRoute('user_home');
    }

    private function notifyRemainderRecevied(Policy $policy)
    {
        $body = sprintf(
            'Remainder (likely) payment was received. Policy %s (Total payments received £%0.2f of £%0.2f).',
            $policy->getPolicyNumber(),
            $policy->getPremiumPaid(),
            $policy->getPremium()->getYearlyPremiumPrice()
        );
        $message = \Swift_Message::newInstance()
            ->setSubject('Remainder Payment received')
            ->setFrom('tech@so-sure.com')
            ->setTo('dylan@so-sure.com')
            ->setCc('patrick@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);
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
            $unInitPolicies = $this->getUser()->getUnInitPolicies();
            if (count($unInitPolicies) > 0) {
                $this->addFlash('warning', 'You seem to have a policy that you started creating, but is unpaid.');
                return $this->redirectToRoute('purchase_step_policy');
            }

            throw new \Exception('Unable to locate reference');
        }

        $payment = $repo->findOneBy(['reference' => $reference]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }
        $policy = $payment->getPolicy();

        // If there's not a user, it may be a payment for the remainder of the policy
        if (!$this->getUser()) {
            return $this->redirectToRoute('purchase_remainder_policy', ['id' => $policy->getId()]);
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

    /**
     * @Route("/lead/{source}", name="lead")
     */
    public function leadAction(Request $request, $source)
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->validateFields(
            $data,
            ['email', 'name', 'csrf']
        )) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        if (!$this->isCsrfTokenValid('lead', $data['csrf'])) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid csrf', 422);
        }

        $email = $this->getDataString($data, 'email');
        $name = $this->getDataString($data, 'name');

        $dm = $this->getManager();
        $userRepo = $dm->getRepository(User::class);
        $leadRepo = $dm->getRepository(Lead::class);
        $existingLead = $leadRepo->findOneBy(['email' => strtolower($email)]);
        $existingUser = $userRepo->findOneBy(['emailCanonical' => strtolower($email)]);

        if (!$existingLead && !$existingUser) {
            $lead = new Lead();
            $lead->setSource($source);
            $lead->setEmail($email);
            $lead->setName($name);

            // Having some validation exceptions for Lead Names - check if its going to fail
            // validation and remove name if its not working. Hopefully the name will be updated later on
            try {
                $this->validateObject($lead);
            } catch (ValidationException $e) {
                $lead->setName(null);
            }

            $dm->persist($lead);
            $dm->flush();
        }

        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
    }

    /**
     * @Route("/cancel/{id}", name="purchase_cancel")
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

        if (!$policy->hasViewedCancellationPage()) {
            $policy->setViewedCancellationPage(new \DateTime());
            $dm->flush();
        }
        $cancelForm = $this->get('form.factory')
            ->createNamedBuilder('cancel_form')
            ->add('cancel', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cancel_form')) {
                $cancelForm->handleRequest($request);
                if ($cancelForm->isValid()) {
                    if (!$policy->hasRequestedCancellation()) {
                        $policy->setRequestedCancellation(new \DateTime());
                        $dm->flush();
                    }
                    $body = sprintf(
                        'Requested cancellation for policy %s/%s',
                        $policy->getPolicyNumber(),
                        $policy->getId()
                    );
                    $message = \Swift_Message::newInstance()
                        ->setSubject(sprintf('Requested Policy Cancellation'))
                        ->setFrom('info@so-sure.com')
                        ->setTo('support@wearesosure.com')
                        ->setBody($body, 'text/html');
                    $this->get('mailer')->send($message);
                    $this->get('app.mixpanel')->queueTrack(
                        MixpanelService::EVENT_REQUEST_CANCEL_POLICY,
                        ['Policy Id' => $policy->getId()]
                    );
                    // @codingStandardsIgnoreStart
                    $this->addFlash(
                        'success',
                        'We have passed your request to our policy team. You should receive a cancellation email once that is processed.'
                    );
                    // @codingStandardsIgnoreEnd
                }
            }
        } else {
            $this->get('app.mixpanel')->queueTrack(
                MixpanelService::EVENT_CANCEL_POLICY_PAGE,
                ['Policy Id' => $policy->getId()]
            );
        }

        return [
            'policy' => $policy,
            'cancel_form' => $cancelForm->createView(),
        ];
    }

    /**
     * @Route("/remainder/{id}", name="purchase_remainder_policy")
     * @Template
     */
    public function purchaseRemainderPolicyAction(Request $request, $id)
    {
        $policyRepo = $this->getManager()->getRepository(Policy::class);
        $policy = $policyRepo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Unknown policy');
        }

        $totalPaid = $policy->getTotalSuccessfulPayments();
        $yearlyPremium = $policy->getPremium()->getYearlyPremiumPrice();
        $amount = $this->toTwoDp($yearlyPremium - $totalPaid);

        $webpay = $this->get('app.judopay')->webpay(
            $policy,
            $amount,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
            JudopayService::WEB_TYPE_REMAINDER
        );

        $data = [
            'phone' => $policy->getPhone(),
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
            'amount' => $amount,
            'policy' => $policy,
        ];

        return $data;
    }
}
