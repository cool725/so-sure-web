<?php

namespace AppBundle\Controller;

use AppBundle\Document\Feature;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\InvalidFullNameException;
use AppBundle\Form\Type\BacsConfirmType;
use AppBundle\Form\Type\BacsType;
use AppBundle\Repository\JudoPaymentRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Security\PolicyVoter;
use AppBundle\Service\PaymentService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\RequestService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\Form\Button;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\SubmitButton;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Session\Session;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

use AppBundle\Classes\ApiErrorCode;

use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Stats;
use AppBundle\Document\JudoPaymentMethod;
use AppBundle\Document\File\ImeiUploadFile;
use AppBundle\Document\Form\Purchase;
use AppBundle\Document\Form\PurchaseStepPersonalAddress;
use AppBundle\Document\Form\PurchaseStepPersonal;
use AppBundle\Document\Form\PurchaseStepAddress;
use AppBundle\Document\Form\PurchaseStepPhone;

use AppBundle\Form\Type\ImeiUploadFileType;
use AppBundle\Form\Type\BasicUserType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\PurchaseStepPersonalAddressType;
use AppBundle\Form\Type\PurchaseStepPersonalAddressDropdownType;
use AppBundle\Form\Type\PurchaseStepPersonalType;
use AppBundle\Form\Type\PurchaseStepAddressType;
use AppBundle\Form\Type\PurchaseStepPhoneType;
use AppBundle\Form\Type\UserCancelType;

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
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = $this->getSessionQuotePhone($request);

        $purchase = new PurchaseStepPersonalAddress();
        if ($user) {
            $purchase->populateFromUser($user);
        } elseif ($session && $session->get('email')) {
            $purchase->setEmail($session->get('email'));
        }

        // DOB Test
        $dobExp = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_DOB,
            ['single', 'dropdowns']
        );

        // DOB Sixpack Test
        /** @var Form $purchaseForm */
        if ($dobExp == 'dropdowns') {
            $purchaseForm = $this->get('form.factory')
                ->createNamedBuilder('purchase_form', PurchaseStepPersonalAddressDropdownType::class, $purchase)
                ->getForm();
        } else {
            $purchaseForm = $this->get('form.factory')
                ->createNamedBuilder('purchase_form', PurchaseStepPersonalAddressType::class, $purchase)
                ->getForm();
        }

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);
                if ($purchaseForm->isValid()) {
                    /** @var FOSUBUserProvider $userService */
                    $userService = $this->get('app.user');
                    if (!$userService->resolveDuplicateUsers(
                        $user,
                        $purchase->getEmail(),
                        $purchase->getMobileNumber(),
                        null
                    )) {
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

                    $newUser = false;
                    if (!$user) {
                        $userManager = $this->get('fos_user.user_manager');
                        /** @var User $user */
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

                    if ($user->hasPartialPolicy()) {
                        return new RedirectResponse(
                            $this->generateUrl('purchase_step_policy_id', [
                                'id' => $user->getPartialPolicies()[0]->getId()
                            ])
                        );
                    } else {
                        return $this->redirectToRoute('purchase_step_policy');
                    }
                }
            }
        }

        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
        $csrf = $this->get('security.csrf.token_manager');

        $moneyBackGuarantee = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_MONEY_BACK_GUARANTEE,
            ['no-money-back-guarantee', 'money-back-guarantee']
        );

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
            // 'postcode' => $this->sixpack($request, SixpackService::EXPERIMENT_POSTCODE, ['comma', 'split', 'type']),
            'postcode' => 'comma',
            'showDropdown' => $dobExp,
            'moneyBackGuarantee' => $moneyBackGuarantee,
        );

        return $this->render('AppBundle:Purchase:purchaseStepPersonalAddress.html.twig', $data);
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-policy", name="purchase_step_policy")
     * @Route("/step-policy/{id}", name="purchase_step_policy_id")
     * @Template
    */
    public function purchaseStepPhoneReviewAction(Request $request, $id = null)
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('purchase');
        } elseif (!$user->canPurchasePolicy()) {
            $this->addFlash(
                'error',
                "Sorry, but you've reached the maximum number of allowed policies. Contact us for more details."
            );

            return $this->redirectToRoute('user_home');
        }

        $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);
        if (!$user->hasValidBillingDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);

        $phone = $this->getSessionQuotePhone($request);

        $purchase = new PurchaseStepPhone();
        $purchase->setUser($user);
        /** @var PhonePolicy $policy */
        $policy = null;
        if ($id) {
            $policy = $policyRepo->find($id);
        }

        if (!$policy && $user->hasPartialPolicy()) {
            $policy = $user->getPartialPolicies()[0];
        }

        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_DOB);

        if ($policy) {
            if (!$phone && $policy->getPhone()) {
                $phone = $policy->getPhone();
            }
            $purchase->setImei($policy->getImei());
            $purchase->setSerialNumber($policy->getSerialNumber());
            $purchase->setPolicy($policy);
        }

        if ($phone) {
            $purchase->setPhone($phone);
            // Default to monthly payment
            if ('GET' === $request->getMethod()) {
                $price = $phone->getCurrentPhonePrice();
                if ($user->allowedMonthlyPayments()) {
                    $purchase->setAmount($price->getMonthlyPremiumPrice($user->getAdditionalPremium()));
                } elseif ($user->allowedYearlyPayments()) {
                    $purchase->setAmount($price->getYearlyPremiumPrice($user->getAdditionalPremium()));
                }
            }
        }

        $purchase->setAgreed(true);
        $purchase->setNew(true);

        /** @var Form $purchaseForm */
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPhoneType::class, $purchase)
            ->getForm();
        $webpay = null;
        $allowPayment = true;

        $paymentProviderTest = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_PURCHASE_FLOW_BACS,
            ['judo', 'bacs'],
            SixpackService::LOG_MIXPANEL_CONVERSION,
            null,
            0.1
        );
        $bacsFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_BACS);
        // For now, only allow 1 policy with bacs
        if ($bacsFeature && count($user->getValidPolicies(true)) >= 1) {
            $bacsFeature = false;
        }
        if (!$bacsFeature) {
            $paymentProviderTest = 'judo';
        }

        //$this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_POSTCODE);
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);

                // as we may recreate the form, make sure to get everything we need from the form first
                $purchaseFormValid = $purchaseForm->isValid();
                $purchaseFormExistingClicked = false;
                if ($purchaseForm->has('existing')) {
                    /** @var SubmitButton $existingButton */
                    $existingButton = $purchaseForm->get('existing');
                    $purchaseFormExistingClicked = $existingButton->isClicked();
                }

                // If there's a file upload, the form submit event bind should have already run the ocr
                // and data object has the imei/serial
                // however, we need to re-create the form so the fields will display the updated data
                if ($filename = $purchase->getFile()) {
                    $purchaseForm = $this->get('form.factory')
                        ->createNamedBuilder('purchase_form', PurchaseStepPhoneType::class, $purchase)
                        ->getForm();
                }

                if ($purchaseFormValid) {
                    if ($policy) {
                        // TODO: How can we preserve imei & make/model check results across policies
                        // If any policy data has changed, create new one
                        if ($policy->getImei() != $purchase->getImei() ||
                            $policy->getSerialNumber() != $purchase->getSerialNumber() ||
                            $policy->getPhone()->getId() != $purchase->getPhone()->getId()) {
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

                            if ($purchase->getFile()) {
                                $imeiUploadFile = new ImeiUploadFile();
                                $policy->setPhoneVerified(true);
                                $imeiUploadFile->setFile($purchase->getFile());
                                $imeiUploadFile->setPolicy($policy);
                                $imeiUploadFile->setBucket('policy.so-sure.com');
                                $imeiUploadFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');
                                $policy->addPolicyFile($imeiUploadFile);
                            }
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
                            /** @var Policy $partialPolicy */
                            $partialPolicy = $policyRepo->findOneBy(['imei' => $purchase->getImei()]);
                            if ($partialPolicy && !$partialPolicy->getStatus() &&
                                $partialPolicy->getUser()->getId() == $user->getId()) {
                                $this->addFlash(
                                    'error',
                                    "Sorry, you weren't in quite the right place. Please try again here."
                                );
                                return new RedirectResponse(
                                    $this->generateUrl('purchase_step_policy_id', [
                                        'id' => $partialPolicy->getId()
                                    ])
                                );
                            } else {
                                $this->addFlash(
                                    'error',
                                    "Sorry, your phone is already in our system. Perhaps it's already insured?"
                                );
                            }
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

                            if ($paymentProviderTest == 'bacs') {
                                return new RedirectResponse(
                                    $this->generateUrl('purchase_step_payment_id', [
                                        'id' => $policy->getId(),
                                        'freq' => $monthly ? Policy::PLAN_MONTHLY : Policy::PLAN_YEARLY,
                                    ])
                                );
                            } else {
                                // There was an odd case of next not being detected as clicked
                                // perhaps a brower issue with multiple buttons
                                // just in case, assume judo pay if we don't detect existing
                                if ($purchaseFormExistingClicked) {
                                    // TODO: Try/catch
                                    if ($this->get('app.judopay')->existing(
                                        $policy,
                                        $purchase->getAmount()
                                    )) {
                                        $purchase->setAgreed(true);
                                        return $this->redirectToRoute(
                                            'user_welcome_policy_id',
                                            ['id' => $policy->getId()]
                                        );
                                    } else {
                                        // @codingStandardsIgnoreStart
                                        $this->addFlash(
                                            'warning',
                                            "Sorry, there was a problem with your existing payment method. Try again, or use the Pay with new card option."
                                        );
                                        // @codingStandardsIgnoreEnd
                                    }
                                } else {
                                    $webpay = $this->get('app.judopay')->webpay(
                                        $policy,
                                        $purchase->getAmount(),
                                        $request->getClientIp(),
                                        $request->headers->get('User-Agent'),
                                        JudopayService::WEB_TYPE_STANDARD
                                    );
                                    $purchase->setAgreed(true);
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

        $moneyBackGuarantee = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_MONEY_BACK_GUARANTEE,
            ['no-money-back-guarantee', 'money-back-guarantee']
        );

        $exp = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_STEP_3,
            ['step-3-payment-old', 'step-3-payment-new']
        );

        $template = 'AppBundle:Purchase:purchaseStepPhoneReview.html.twig';

        if ($exp == 'step-3-payment-new') {
            $template = 'AppBundle:Purchase:purchaseStepPhoneReviewNew.html.twig';
        }

        $now = new \DateTime();
        $billingDate = $this->adjustDayForBilling($now);

        $data = array(
            'policy' => $policy,
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
            'payment_provider' => $paymentProviderTest,
            'moneyBackGuarantee' => $moneyBackGuarantee,
        );

        return $this->render($template, $data);
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-payment/{id}/{freq}", name="purchase_step_payment_id")
     */
    public function purchaseStepPaymentAction(Request $request, $id, $freq)
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('purchase');
        } elseif (!$user->canPurchasePolicy()) {
            $this->addFlash(
                'error',
                "Sorry, but you've reached the maximum number of allowed policies. Contact us for more details."
            );

            return $this->redirectToRoute('user_home');
        }

        if (!$user->hasValidBillingDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);

        /** @var PhonePolicy $policy */
        $policy = $policyRepo->find($id);
        if (!$policy) {
            return $this->redirectToRoute('purchase_step_personal');
        }
        $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);
        $amount = null;
        $bacs = new Bacs();
        $bacsConfirm = new Bacs();
        if ($freq == Policy::PLAN_MONTHLY) {
            $policy->setPremiumInstallments(12);
            $this->getManager()->flush();
            $amount = $policy->getPremium()->getMonthlyPremiumPrice();
        } elseif ($freq == Policy::PLAN_YEARLY) {
            $policy->setPremiumInstallments(1);
            $this->getManager()->flush();
            $amount = $policy->getPremium()->getYearlyPremiumPrice();
            $bacs->setAnnual(true);
            $bacsConfirm->setAnnual(true);
        } else {
            throw new NotFoundHttpException(sprintf('Unknown frequency %s', $freq));
        }

        /** @var PaymentService $paymentService */
        $paymentService = $this->get('app.payment');
        /** @var PolicyService $policyService */
        $policyService = $this->get('app.policy');
        /** @var FormInterface $bacsForm */
        $bacsForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_form', BacsType::class, $bacs)
            ->getForm();
        /** @var FormInterface $bacsConfirmForm */
        $bacsConfirmForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_confirm_form', BacsConfirmType::class, $bacsConfirm)
            ->getForm();

        $webpay = $this->get('app.judopay')->webpay(
            $policy,
            $amount,
            $request->getClientIp(),
            $request->headers->get('User-Agent'),
            JudopayService::WEB_TYPE_STANDARD
        );

        $template = null;
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('bacs_form')) {
                $bacsForm->handleRequest($request);
                if ($bacsForm->isValid()) {
                    if (!$bacs->isValid()) {
                        $this->addFlash('error', 'Sorry, but this bank account is not valid');
                    } else {
                        $paymentService->generateBacsReference($bacs, $user);
                        $bacsConfirm = clone $bacs;
                        $bacsConfirmForm = $this->get('form.factory')
                            ->createNamedBuilder('bacs_confirm_form', BacsConfirmType::class, $bacsConfirm)
                            ->getForm();
                        $template = 'AppBundle:Purchase:purchaseStepPaymentBacsConfirm.html.twig';
                    }
                }
            } elseif ($request->request->has('bacs_confirm_form')) {
                $bacsConfirmForm->handleRequest($request);
                /** @var SubmitButton $backButton */
                $backButton = $bacsConfirmForm->get('back');
                if ($backButton->isClicked()) {
                    $bacs = clone $bacsConfirm;
                    $bacsForm = $this->get('form.factory')
                        ->createNamedBuilder('bacs_form', BacsType::class, $bacs)
                        ->getForm();
                    $template = 'AppBundle:Purchase:purchaseStepPaymentBacs.html.twig';
                } elseif ($bacsConfirmForm->isValid()) {
                    $policyService->create($policy, null, true);
                    $paymentService->confirmBacs(
                        $policy,
                        $bacsConfirm->transformBacsPaymentMethod($this->getIdentityLogWeb($request))
                    );

                    $this->addFlash(
                        'success',
                        'Your direct debit is now setup. You will receive an email confirmation shortly.'
                    );

                    return $this->redirectToRoute('user_welcome', ['id' => $policy->getId()]);
                }
            }
        }

        if (!$template) {
            $template = 'AppBundle:Purchase:purchaseStepPaymentBacs.html.twig';
        }

        $data = array(
            'policy' => $policy,
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 3,
            'bacs_form' => $bacsForm->createView(),
            'bacs_confirm_form' => $bacsConfirmForm->createView(),
            'bacs' => $bacs,
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
        );


        return $this->render($template, $data);
    }

        /**
     * @Route("/sample-policy-terms", name="sample_policy_terms")
     * @Template()
     */
    public function samplePolicyTermsAction()
    {
        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3policy_fs');
        $environment = $this->getParameter('kernel.environment');
        $file = 'sample-policy-terms.pdf';

        if (!$filesystem->has($file)) {
            throw $this->createNotFoundException(sprintf('URL not found %s', $file));
        }

        $this->get('app.mixpanel')->queueTrack(
            MixpanelService::EVENT_TEST,
            ['Test Name' => 'pdf-terms-download']
        );

        $mimetype = $filesystem->getMimetype($file);
        return StreamedResponse::create(
            function () use ($file, $filesystem) {
                $stream = $filesystem->readStream($file);
                echo stream_get_contents($stream);
                flush();
            },
            200,
            array('Content-Type' => $mimetype)
        );
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
        /** @var PaymentRepository $repo */
        $repo = $dm->getRepository(Payment::class);
        /** @var JudoPayment $payment */
        $payment = $repo->findOneBy(['reference' => $request->get('Reference')]);
        if (!$payment) {
            throw new \Exception('Unable to locate payment');
        }
        $policy = $payment->getPolicy();

        // Payment should have the webtype so no need to query judopay
        $webType = $payment->getWebType();
        if (!$webType) {
            // Just in case payment record doesn't have, see if judo has in metadata
            $webType = $judo->getTransactionWebType($request->get('ReceiptId'));
        }
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

        if (in_array($webType, [
            JudopayService::WEB_TYPE_REMAINDER,
            JudopayService::WEB_TYPE_STANDARD,
            JudopayService::WEB_TYPE_UNPAID,
        ])) {
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
                $this->get('logger')->warning(
                    'Duplicate re-use of judo receipt. Possible refresh issue, so ignoring and continuing',
                    ['exception' => $e]
                );
            }

            if ($webType == JudopayService::WEB_TYPE_REMAINDER) {
                $this->notifyRemainderRecevied($policy);

                return $this->getRouteForPostCC($policy, $webType);
            } elseif ($policy->isInitialPayment()) {
                return $this->getRouteForPostCC($policy, $webType);
            } elseif ($policy->getLastSuccessfulUserPaymentCredit()) {
                // unpaid policy - outstanding payment
                $this->addFlash(
                    'success',
                    sprintf(
                        'Thanks for your payment of £%0.2f',
                        $policy->getLastSuccessfulUserPaymentCredit()->getAmount()
                    )
                );
                return $this->getRouteForPostCC($policy, $webType);
            } else {
                // should never occur - but assume success
                return $this->getRouteForPostCC($policy, $webType);
            }
        } elseif ($webType == JudopayService::WEB_TYPE_CARD_DETAILS) {
            $payment->setReceipt($request->get('ReceiptId'));
            $dm->flush();

            $judo->updatePaymentMethod(
                $payment->getUser(),
                $request->get('ReceiptId'),
                null,
                $request->get('CardToken'),
                null,
                $policy
            );

            $this->addFlash(
                'success',
                sprintf('Your card has been updated')
            );

            return $this->getRouteForPostCC($policy, $webType);
        }

        return $this->getRouteForPostCC($policy, $webType);
    }

    private function getRouteForPostCC($policy, $webType)
    {
        if ($webType == JudopayService::WEB_TYPE_CARD_DETAILS) {
            if ($policy) {
                return $this->redirectToRoute('user_payment_details_policy', ['policyId' => $policy->getId()]);
            } else {
                return $this->redirectToRoute('user_payment_details');
            }
        } elseif ($webType == JudopayService::WEB_TYPE_REMAINDER) {
            return $this->redirectToRoute('purchase_remainder_policy', ['id' => $policy->getId()]);
        } elseif (in_array($webType, [
            JudopayService::WEB_TYPE_STANDARD,
            JudopayService::WEB_TYPE_UNPAID,
        ])) {
            if ($policy->isInitialPayment()) {
                return $this->redirectToRoute('user_welcome_policy_id', ['id' => $policy->getId()]);
            } else {
                return $this->redirectToRoute('user_home');
            }
        }

        return $this->redirectToRoute('user_home');
    }

    private function notifyRemainderRecevied(Policy $policy)
    {
        $this->get('app.stats')->increment(Stats::KPI_CANCELLED_AND_PAYMENT_PAID);
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
            ->setCc('tech+ops@so-sure.com')
            ->setBody($body, 'text/html');
        $this->get('mailer')->send($message);
    }

    /**
     * @Route("/cc/fail", name="purchase_judopay_receive_fail")
     * @Route("/cc/fail/", name="purchase_judopay_receive_fail_slash")
     */
    public function purchaseJudoPayFailAction(Request $request)
    {
        $msg = sprintf(
            'Judo Web Failure ReceiptId: %s Ref: %s',
            $request->get('ReceiptId'),
            $request->get('Reference')
        );
        $this->get('logger')->info($msg);
        $user = $this->getUser();
        $dm = $this->getManager();

        /** @var JudopayService $judo */
        $judo = $this->get('app.judopay');

        /** @var JudoPaymentRepository $repo */
        $repo = $dm->getRepository(JudoPayment::class);

        /** @var JudoPayment $payment */
        $payment = $repo->findOneBy(['reference' => $request->get('Reference')]);
        if (!$payment) {
            throw new \Exception(sprintf('Unable to locate payment. Details: %s', $msg));
        }
        $policy = $payment->getPolicy();

        $webType = $payment->getWebType();
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

        if (!$payment->hasSuccess()) {
            $payment->setSuccess(false);
        }
        $dm->flush();

        $this->addFlash(
            'error',
            sprintf('Your payment was cancelled or declined. Please try again.')
        );

        return $this->getRouteForPostCC($policy, $webType);
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
        $existingLead = $leadRepo->findOneBy(['email' => mb_strtolower($email)]);
        $existingUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);

        if (!$existingLead && !$existingUser) {
            $lead = new Lead();
            $lead->setSource($source);
            $lead->setEmail($email);
            $lead->setName($name);

            // Having some validation exceptions for Lead Names - check if its going to fail
            // validation and remove name if its not working. Hopefully the name will be updated later on
            // on invalid email format return error as we cannot open lead
            try {
                $this->validateObject($lead);
            } catch (InvalidFullNameException $e) {
                $lead->setName(null);
            } catch (InvalidEmailException $e) {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid email format', 200);
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
            ->createNamedBuilder('cancel_form', UserCancelType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cancel_form')) {
                $cancelForm->handleRequest($request);
                if ($cancelForm->isValid()) {
                    $reason = $cancelForm->getData()['reason'];
                    $other = $cancelForm->getData()['othertxt'];

                    // @codingStandardsIgnoreStart
                    $body = sprintf(
                        "This is a so-sure generated message. Policy: <a href='%s'>%s/%s</a> requested a cancellation via the site as phone was damaged (%s) prior to purchase. so-sure support team: Please verify policy id match in system and directly cancel policy immediately without DPA validation. Additional comments: %s",
                        $this->generateUrl(
                            'admin_policy',
                            ['id' => $policy->getId()],
                            UrlGeneratorInterface::ABSOLUTE_URL
                        ),
                        $policy->getPolicyNumber(),
                        $policy->getId(),
                        $reason,
                        $other
                    );
                    // @codingStandardsIgnoreEnd

                    if (!$policy->hasRequestedCancellation()) {
                        $policy->setRequestedCancellation(new \DateTime());
                        $policy->setRequestedCancellationReason($reason);
                        $dm->flush();
                        $intercom = $this->get('app.intercom');
                        $intercom->queueMessage($policy->getUser()->getEmail(), $body);
                    }

                    $message = \Swift_Message::newInstance()
                        ->setSubject(sprintf('Requested Policy Cancellation'))
                        ->setFrom('info@so-sure.com')
                        ->setTo('bcc@wearesosure.com')
                        ->setBody($body, 'text/html');

                    $this->get('mailer')->send($message);

                    $this->get('app.mixpanel')->queueTrack(
                        MixpanelService::EVENT_REQUEST_CANCEL_POLICY,
                        ['Policy Id' => $policy->getId(), 'Reason' => $reason]
                    );

                    $this->get('app.sixpack')->convertByClientId(
                        $policy->getUser()->getId(),
                        SixpackService::EXPERIMENT_NEW_WELCOME_MODAL
                    );

                    // @codingStandardsIgnoreStart
                    $this->addFlash(
                        'success',
                        'We have passed your request to our policy team. You should receive a cancellation email once that is processed.'
                    );
                    // @codingStandardsIgnoreEnd
                    return $this->redirectToRoute('homepage');
                }
            }
        } else {
            $this->get('app.mixpanel')->queueTrack(
                MixpanelService::EVENT_CANCEL_POLICY_PAGE,
                ['Policy Id' => $policy->getId()]
            );
        }

        $template = 'AppBundle:Purchase:cancel.html.twig';
        $data = [
            'policy' => $policy,
            'cancel_form' => $cancelForm->createView(),
        ];

        return $this->render($template, $data);
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

        $amount = $policy->getRemainderOfPolicyPrice();
        $webpay = null;

        if ($amount > 0) {
            $webpay = $this->get('app.judopay')->webpay(
                $policy,
                $amount,
                $request->getClientIp(),
                $request->headers->get('User-Agent'),
                JudopayService::WEB_TYPE_REMAINDER
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
}
