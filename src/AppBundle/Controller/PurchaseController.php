<?php

namespace AppBundle\Controller;

use AppBundle\Classes\NoOp;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Feature;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Form\PurchaseStepPayment;
use AppBundle\Document\Form\PurchaseStepPledge;
use AppBundle\Document\Note\StandardNote;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Postcode;
use AppBundle\Exception\CommissionException;
use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\InvalidFullNameException;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Form\Type\BacsConfirmType;
use AppBundle\Form\Type\BacsType;
use AppBundle\Form\Type\PurchaseStepPaymentType;
use AppBundle\Form\Type\PurchaseStepPledgeType;
use AppBundle\Form\Type\PurchaseStepToCardType;
use AppBundle\Form\Type\PurchaseStepToJudoType;
use AppBundle\Repository\JudoPaymentRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Security\PolicyVoter;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\MailerService;
use AppBundle\Service\PaymentService;
use AppBundle\Service\PriceService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\PostcodeService;
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
use AppBundle\Document\ValidatorTrait;

use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Stats;
use AppBundle\Document\PaymentMethod\JudoPaymentMethod;
use AppBundle\Document\File\ImeiUploadFile;
use AppBundle\Document\Form\PurchaseStepPersonalAddress;
use AppBundle\Document\Form\PurchaseStepPhone;

use AppBundle\Form\Type\ImeiUploadFileType;
use AppBundle\Form\Type\BasicUserType;
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
    use ValidatorTrait;

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-personal", name="purchase_step_personal")
     * @Route("", name="purchase")
     * @Route("/", name="purchase_slash")
     * @Template
    */
    public function purchaseStepPersonalAddressAction(Request $request)
    {
        $session = $request->getSession();
        $user = $this->getUser();

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

        // TEMP - As using skip add extra event
        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_QUOTE_PAGE_PURCHASE);

        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPersonalAddressType::class, $purchase)
            ->getForm();

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
                        $err = 'It looks like you already have an account. Please try logging in with your details';
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

                        if ($session && $session->get('oauth2Flow') == 'starling') {
                            $user->setLeadSource(Lead::LEAD_SOURCE_AFFILIATE);
                            $user->setLeadSourceDetails('starling');
                        }
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
                            $this->generateUrl('purchase_step_phone_id', [
                                'id' => $user->getPartialPolicies()[0]->getId()
                            ])
                        );
                    } else {
                        return $this->redirectToRoute('purchase_step_phone');
                    }
                }
            }
        }

        $priceService = $this->get('app.price');

        // Aggregators - Get session if coming back
        $validationRequired = $this->get('session')->get('aggregator');

        // In-store
        $instore = $this->get('session')->get('store');

        /** @var \Symfony\Component\Security\Csrf\CsrfTokenManagerInterface $csrf */
        $csrf = $this->get('security.csrf.token_manager');

        $template = 'AppBundle:Purchase:purchaseStepPersonalAddress.html.twig';

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
            'postcode' => 'comma',
            'prices' => $phone ? $priceService->userPhonePriceStreams($user, $phone, new \DateTime()) : null,
            // 'funnel_exp' => $homepageFunnelExp,
            'instore' => $instore,
            'validation_required' => $validationRequired,
        );

        return $this->render($template, $data);
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-phone", name="purchase_step_phone")
     * @Route("/step-phone/{id}", name="purchase_step_phone_id")
     * @Template
     */
    public function purchaseStepPhoneAction(Request $request, $id = null)
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('purchase', [], 301);
        } elseif (!$user->canPurchasePolicy()) {
            $this->addFlash(
                'error',
                "Sorry, but you've reached the maximum number of allowed policies. Contact us for more details."
            );

            return $this->redirectToRoute('user_home');
        }

        if (!$user->hasValidBillingDetails() || !$user->hasValidDetails()) {
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

        if ($policy) {
            $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);
        } else {
            $this->denyAccessUnlessGranted(UserVoter::ADD_POLICY, $user);
        }

        if ($policy) {
            if (!$phone && $policy->getPhone()) {
                $phone = $policy->getPhone();
                $this->setSessionQuotePhone($request, $phone);
            }
            $purchase->setImei($policy->getImei());
            $purchase->setSerialNumber($policy->getSerialNumber());
            $purchase->setPolicy($policy);
        }

        if ($phone) {
            $purchase->setPhone($phone);
        }

        /** @var Form $purchaseForm */
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPhoneType::class, $purchase)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);

                // as we may recreate the form, make sure to get everything we need from the form first
                $purchaseFormValid = $purchaseForm->isValid();

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

                    $allowContinue = true;
                    if (!$policy) {
                        try {
                            $policyService = $this->get('app.policy');
                            $policyService->setWarnMakeModelMismatch(false);
                            $policy = $policyService->init(
                                $user,
                                $purchase->getPhone(),
                                $purchase->getImei(),
                                $purchase->getSerialNumber(),
                                $this->getIdentityLogWeb($request),
                                null,
                                null,
                                $this->get('session')->get('aggregator') ? true : false
                            );
                            $dm->persist($policy);

                            if ($purchase->getFile()) {
                                $imeiUploadFile = new ImeiUploadFile();
                                $policy->setPhoneVerified(true);
                                $imeiUploadFile->setFile($purchase->getFile());
                                $imeiUploadFile->setPolicy($policy);
                                $imeiUploadFile->setBucket(SoSure::S3_BUCKET_POLICY);
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
                            $allowContinue = false;
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
                                    $this->generateUrl('purchase_step_phone_id', [
                                        'id' => $partialPolicy->getId()
                                    ])
                                );
                            } else {
                                $this->addFlash(
                                    'error',
                                    "Sorry, your phone is already in our system. Perhaps it's already insured?"
                                );
                            }
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
                    }
                    $dm->flush();
                    if ($allowContinue) {
                        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_POLICY_READY, [
                            'Device Insured' => $purchase->getPhone()->__toString(),
                            'OS' => $purchase->getPhone()->getOs(),
                            'Policy Id' => $policy->getId(),
                        ]);

                        return new RedirectResponse(
                            $this->generateUrl('purchase_step_pledge_id', [
                                'id' => $policy->getId()
                            ])
                        );
                    }
                } else {
                    $this->addFlash('error', sprintf(
                        'Sorry, there seems to be an error. Please check below for further details.'
                    ));
                }
            }
        }

        // Aggregators - Get session if coming back
        $validationRequired = $this->get('session')->get('aggregator');

        // In-store
        $instore = $this->get('session')->get('store');

        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');
        $template = 'AppBundle:Purchase:purchaseStepPhone.html.twig';

        $priceService = $this->get('app.price');
        $data = array(
            'policy' => $policy,
            'phone' => $phone,
            'purchase_form' => $purchaseForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 2,
            'policy_key' => $this->getParameter('policy_key'),
            'phones' => $phone ? $phoneRepo->findBy(
                ['active' => true, 'make' => $phone->getMake(), 'model' => $phone->getModel()],
                ['memory' => 'asc']
            ) : null,
            'prices' => $priceService->userPhonePriceStreams($user, $phone, new \DateTime()),
            'instore' => $instore,
            'validation_required' => $validationRequired,
        );

        return $this->render($template, $data);
    }


    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-payment/{id}/{freq}", name="purchase_step_payment_bacs_id")
     */
    public function purchaseStepPaymentBacsAction(Request $request, $id, $freq)
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

        if (!$user->hasValidBillingDetails() || !$user->hasValidDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);

        $checkoutFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_CHECKOUT);
        $cardProvider = SoSure::PAYMENT_PROVIDER_CHECKOUT;

        /** @var PhonePolicy $policy */
        $policy = $policyRepo->find($id);
        if (!$policy) {
            return $this->redirectToRoute('purchase_step_personal');
        }
        $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);
        $amount = null;
        $bacs = new Bacs();
        $bacs->setValidateName($user->getLastName());
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

        /** @var Form $toCardForm */
        $toCardForm = null;
        if ($checkoutFeature) {
            $toCardForm =  $this->get("form.factory")
                ->createNamedBuilder('to_card_form', PurchaseStepToCardType::class)
                ->getForm();
        }

        /** @var FormInterface $bacsForm */
        $bacsForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_form', BacsType::class, $bacs)
            // ->setValidationName('asdasdasd')
            ->getForm();
        /** @var FormInterface $bacsConfirmForm */
        $bacsConfirmForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_confirm_form', BacsConfirmType::class, $bacsConfirm)
            ->getForm();

        $template = null;
        $webpay = null;
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
                    $identityLog = $this->getIdentityLogWeb($request);
                    $paymentService->confirmBacs(
                        $policy,
                        $bacsConfirm->transformBacsPaymentMethod($identityLog)
                    );
                    $policyService->create(
                        $policy,
                        null,
                        true,
                        null,
                        $identityLog,
                        $bacsConfirm->getCalculatedBillingDate()
                    );

                    $this->addFlash(
                        'success',
                        'Your direct debit is now scheduled. You will receive an email confirmation shortly.'
                    );

                    return $this->redirectToRoute('user_welcome_policy_id', ['id' => $policy->getId()]);
                }
            } elseif ($request->request->has('to_card_form')) {
                $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_TEST, [
                    'Test Name' => 'Bacs to Card',
                    'Payment Provider' => $cardProvider,
                ]);
                if ($checkoutFeature) {
                    // TODO
                    NoOp::ignore([]);
                }
            }
        }

        if (!$template) {
            $template = 'AppBundle:Purchase:purchaseStepPaymentBacs.html.twig';
        }

        $phone = $policy->getPhone();
        $billingDate = $this->addDays($this->startOfMonth(new \DateTime()), $bacs->getBillingDate());

        $data = array(
            'phone' => $phone,
            'phones' => $phone ? $phoneRepo->findBy(
                ['active' => true, 'make' => $phone->getMake(), 'model' => $phone->getModel()],
                ['memory' => 'asc']
            ): null,
            'policy' => $policy,
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 4,
            'bacs_form' => $bacsForm->createView(),
            'bacs_confirm_form' => $bacsConfirmForm->createView(),
            'bacs' => $bacs,
            'amount' => $amount,
        );

        if ($webpay) {
            $data['webpay_action'] = $webpay ? $webpay['post_url'] : null;
            $data['webpay_reference'] = $webpay ? $webpay['payment']->getReference() : null;
        }

        if ($toCardForm) {
            $data['to_card_form'] = $toCardForm->createView();
            $data['card_provider'] = $cardProvider;
        }


        return $this->render($template, $data);
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-pledge/{id}", name="purchase_step_pledge_id")
     * @Template
     */
    public function purchaseStepPledgeAction(Request $request, $id)
    {
        /** @var User $user */
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

        if (!$user->hasValidBillingDetails() || !$user->hasValidDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);

        $phone = $this->getSessionQuotePhone($request);

        $purchase = new PurchaseStepPledge();
        $purchase->setUser($user);
        /** @var PhonePolicy $policy */
        $policy = $policyRepo->find($id);
        $purchase->setPolicy($policy);

        if (!$policy) {
            return $this->redirectToRoute('purchase_step_phone');
        }

        $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

        if ($policy && !$phone && $policy->getPhone()) {
            $phone = $policy->getPhone();
            $this->setSessionQuotePhone($request, $phone);
        }

        /** @var Form $purchaseForm */
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPledgeType::class, $purchase)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);

                if ($purchaseForm->isValid() && $purchase->areAllAgreed()) {
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_COMPLETE_PLEDGE, [
                        'Device Insured' => $phone ? $phone->__toString() : null,
                        'OS' => $phone ? $phone->getOs() : null,
                        'Policy Id' => $policy->getId(),
                    ]);
                    return new RedirectResponse(
                        $this->generateUrl('purchase_step_payment_id', [
                            'id' => $policy->getId()
                        ])
                    );
                }
            }
        }

        $priceService = $this->get('app.price');

        $validationRequired = $this->get('session')->get('aggregator');

        // In-store
        $instore = $this->get('session')->get('store');

        $template = 'AppBundle:Purchase:purchaseStepPledge.html.twig';

        $data = array(
            'policy' => $policy,
            'phone' => $policy->getPhone(),
            'purchase_form' => $purchaseForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 3,
            'policy_key' => $this->getParameter('policy_key'),
            'phones' => $phone ? $phoneRepo->findBy(
                ['active' => true, 'make' => $phone->getMake(), 'model' => $phone->getModel()],
                ['memory' => 'asc']
            ) : null,
            'prices' => $priceService->userPhonePriceStreams($user, $phone, new \DateTime()),
            'instore' => $instore,
            'validation_required' => $validationRequired,
        );

        return $this->render($template, $data);
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/step-payment/{id}", name="purchase_step_payment_id")
     * @Template
    */
    public function purchaseStepPaymentAction(Request $request, $id)
    {
        /** @var User $user */
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

        if (!$user->hasValidBillingDetails() || !$user->hasValidDetails()) {
            return $this->redirectToRoute('purchase_step_personal');
        }

        $dm = $this->getManager();
        /** @var PhoneRepository $phoneRepo */
        $phoneRepo = $dm->getRepository(Phone::class);
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);

        $phone = $this->getSessionQuotePhone($request);
        $priceService = $this->get('app.price');

        $purchase = new PurchaseStepPayment();
        $purchase->setUser($user);
        /** @var PhonePolicy $policy */
        $policy = $policyRepo->find($id);
        $purchase->setPolicy($policy);
        foreach ($priceService->userPhonePriceStreams($user, $phone, new \DateTime()) as $price) {
            $purchase->addPrice($price);
        }

        if (!$policy) {
            return $this->redirectToRoute('purchase_step_phone');
        }

        $this->denyAccessUnlessGranted(PolicyVoter::EDIT, $policy);

        if ($policy && !$phone && $policy->getPhone()) {
            $phone = $policy->getPhone();
            $this->setSessionQuotePhone($request, $phone);
        }

        // Default to monthly payment
        if ('GET' === $request->getMethod()) {
            $price = $policy->getPhone()->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
            /** @var PostcodeService $postcodeService */
            $postcodeService = $this->get('app.postcode');
            if ($price && $user->allowedMonthlyPayments($postcodeService)) {
                $purchase->setAmount($price->getMonthlyPremiumPrice($user->getAdditionalPremium()));
            } elseif ($price && $user->allowedYearlyPayments()) {
                $purchase->setAmount($price->getYearlyPremiumPrice($user->getAdditionalPremium()));
            }
        }

        //$purchase->setAgreed(true);
        $purchase->setNew(true);

        /** @var Form $purchaseForm */
        $purchaseForm = $this->get('form.factory')
            ->createNamedBuilder('purchase_form', PurchaseStepPaymentType::class, $purchase)
            ->getForm();
        $webpay = null;
        $allowPayment = true;

        $paymentProvider = null;
        $bacsFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_BACS);
        $checkoutFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_CHECKOUT);
        /** @var Form $toCardForm */
        $toCardForm = null;
        if ($checkoutFeature) {
            $toCardForm =  $this->get("form.factory")
                ->createNamedBuilder('to_card_form', PurchaseStepToCardType::class)
                ->getForm();
        }        // bacs overrides any type of card payment
        if ($bacsFeature) {
            $paymentProvider = SoSure::PAYMENT_PROVIDER_BACS;
        } elseif ($checkoutFeature) {
            $paymentProvider = SoSure::PAYMENT_PROVIDER_CHECKOUT;
        } else {
            $this->get('logger')->error('No payment methods available!');
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, "Payment method not defined", 403);
        }

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('purchase_form')) {
                $purchaseForm->handleRequest($request);

                // as we may recreate the form, make sure to get everything we need from the form first
                $purchaseFormValid = $purchaseForm->isValid();

                if ($purchaseFormValid) {
                    if ($allowPayment) {
                        $currentPrice = $policy->getPhone()->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
                        $monthly = null;
                        $yearly = null;
                        if ($currentPrice) {
                            $monthly = $this->areEqualToTwoDp(
                                $purchase->getAmount(),
                                $currentPrice->getMonthlyPremiumPrice()
                            );
                            $yearly = $this->areEqualToTwoDp(
                                $purchase->getAmount(),
                                $currentPrice->getYearlyPremiumPrice()
                            );
                        }

                        if ($monthly || $yearly) {
                            if ($paymentProvider == SoSure::PAYMENT_PROVIDER_BACS) {
                                return new RedirectResponse(
                                    $this->generateUrl('purchase_step_payment_bacs_id', [
                                        'id' => $policy->getId(),
                                        'freq' => $monthly ? Policy::PLAN_MONTHLY : Policy::PLAN_YEARLY,
                                    ])
                                );
                            } elseif ($paymentProvider == SoSure::PAYMENT_PROVIDER_CHECKOUT) {
                                // TODO
                                NoOp::ignore([]);
                                /*
                                $webpay = $this->get('app.checkout')->webpay(
                                    $policy,
                                    $purchase->getAmount(),
                                    $request->getClientIp(),
                                    $request->headers->get('User-Agent'),
                                    JudopayService::WEB_TYPE_STANDARD
                                );
                                */
                            }
                        } else {
                            $this->addFlash(
                                'error',
                                "Please select the monthly or yearly option."
                            );
                        }
                    }
                }
            } elseif ($request->request->has('to_card_form')) {
                if ($checkoutFeature) {
                    // TODO
                    NoOp::ignore([]);
                }
            }
        }

        // Aggregators - Get session if coming back
        $validationRequired = $this->get('session')->get('aggregator');

        // In-store
        $instore = $this->get('session')->get('store');

        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');
        $template = 'AppBundle:Purchase:purchaseStepPayment.html.twig';

        $now = \DateTime::createFromFormat('U', time());
        $billingDate = $this->adjustDayForBilling($now);

        $data = array(
            'policy' => $policy,
            'phone' => $policy->getPhone(),
            'purchase_form' => $purchaseForm->createView(),
            'is_postback' => 'POST' === $request->getMethod(),
            'step' => 4,
            'webpay_action' => $webpay ? $webpay['post_url'] : null,
            'webpay_reference' => $webpay ? $webpay['payment']->getReference() : null,
            'policy_key' => $this->getParameter('policy_key'),
            'phones' => $phone ? $phoneRepo->findBy(
                ['active' => true, 'make' => $phone->getMake(), 'model' => $phone->getModel()],
                ['memory' => 'asc']
            ) : null,
            'billing_date' => $billingDate,
            'payment_provider' => $paymentProvider,
            'prices' => $priceService->userPhonePriceStreams($user, $policy->getPhone(), new \DateTime()),
            'instore' => $instore,
            'validation_required' => $validationRequired,
        );

        if ($toCardForm) {
            $data['to_card_form'] = $toCardForm->createView();
            $data['card_provider'] = $paymentProvider;
        }
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
        $this->get('logger')->alert('JudoPay used!');
        $this->get('logger')->alert(sprintf(
            'Payment successful against JudoPay for user %s',
            $this->getUser()->getId()
        ));
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

        /** @var Payment $lastCredit */
        $lastCredit = $policy->getLastSuccessfulUserPaymentCredit();

        // @codingStandardsIgnoreStart
        $body = sprintf(
            'Remainder (likely) payment of £%0.2f was received . Policy %s (Total payments received £%0.2f of £%0.2f).',
            $lastCredit ? $lastCredit->getAmount() : 0,
            $policy->getPolicyNumber(),
            $policy->getPremiumPaid(),
            $policy->getPremium()->getYearlyPremiumPrice()
        );
        // @codingStandardsIgnoreEnd

        /** @var MailerService $mailer */
        $mailer = $this->get('app.mailer');
        $mailer->send(
            'Remainder Payment received',
            'dylan@so-sure.com',
            $body,
            null,
            null,
            'tech+ops@so-sure.com'
        );
    }

    /**
     * @Route("/cc/fail", name="purchase_judopay_receive_fail")
     * @Route("/cc/fail/", name="purchase_judopay_receive_fail_slash")
     */
    public function purchaseJudoPayFailAction(Request $request)
    {
        $this->get('logger')->alert('JudoPay used!');
        $this->get('logger')->alert(sprintf(
            'Failed payment attempt with JudoPay for user %s',
            $this->getUser()->getId()
        ));
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
        $existingLead = $leadRepo->findOneBy(['emailCanonical' => mb_strtolower($email)]);
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
     * Deprecated route redirecting to authenticated version under user controller.
     * @Route("/cancel/{id}", name="purchase_cancel")
     * @Route("/cancel/damaged/{id}", name="purchase_cancel_damaged")
     */
    public function cancelAction(Request $request, $id)
    {
        $route = $request->get('_route');
        if ($route == 'purchase_cancel') {
            return $this->redirectToRoute('user_cancel', ['id' => $id]);
        } elseif ($route == 'purchase_cancel_damaged') {
            return $this->redirectToRoute('user_cancel_damaged', ['id' => $id]);
        }
        throw new \Exception("non route");
    }

    /**
     * Deprecated route, but still in old emails.
     * @Route("/cancel/{id}/requested", name="purchase_cancel_requested")
     */
    public function cancelRequestedAction($id)
    {
        return $this->redirectToRoute('user_cancel_requested', ['id' => $id]);
    }

    /**
     * @Route("/checkout/{id}", name="purchase_checkout")
     * @Route("/checkout/{id}/update", name="purchase_checkout_update")
     * @Route("/checkout/{id}/remainder", name="purchase_checkout_remainder")
     * @Route("/checkout/{id}/unpaid", name="purchase_checkout_unpaid")
     * @Route("/checkout/{id}/claim", name="purchase_checkout_claim")
     * @Method({"POST"})
     */
    public function checkoutAction(Request $request, $id)
    {
        $logger = $this->get('logger');
        $type = null;
        // In-store
        $instore = $this->get('session')->get('store');
        $successMessage = 'Success! Your payment has been successfully completed';
        $errorMessage = 'Oh no! There was a problem with your payment. Please check your card
        details are correct and try again or get in touch if you continue to have issues';
        $redirectSuccess = $this->generateUrl('user_welcome', ['id' => $id]);
        $redirectFailure = $this->generateUrl('purchase_step_payment_id', ['id' => $id]);
        if ($request->get('_route') == 'purchase_checkout_update') {
            $successMessage = 'Success! Your card details have been successfully updated';
            $errorMessage = 'Sorry, we were unable to update your card details. Please try again or
            get in touch if you continue to have issues.';
            $redirectSuccess = $this->generateUrl('user_payment_details_policy', ['policyId' => $id]);
            $redirectFailure = $this->generateUrl('user_payment_details_policy', ['policyId' => $id]);
        } elseif ($request->get('_route') == 'purchase_checkout_remainder') {
            $successMessage = 'Success! Your payment has been successfully completed';
            $errorMessage = 'Oh no! There was a problem with your payment. Please check your card
            details are correct and try again or get in touch if you continue to have issues';
            $redirectSuccess = $this->generateUrl('purchase_remainder_policy', ['id' => $id]);
            $redirectFailure = $this->generateUrl('purchase_remainder_policy', ['id' => $id]);
        } elseif ($request->get('_route') == 'purchase_checkout_unpaid') {
            $successMessage = 'Success! Your payment has been successfully completed';
            $errorMessage = 'Oh no! There was a problem with your payment. Please check your card
            details are correct and try again or get in touch if you continue to have issues';
            $redirectSuccess = $this->generateUrl('user_unpaid_policy');
            $redirectFailure = $this->generateUrl('user_unpaid_policy');
        } elseif ($request->get('_route') == 'purchase_checkout_claim') {
            $successMessage = 'Success! Your payment has been successfully completed';
            $errorMessage = 'Oh no! There was a problem with your payment. Please check your card
            details are correct and try again or get in touch if you continue to have issues';
            $redirectSuccess = $this->generateUrl('user_claim');
            $redirectFailure = $this->generateUrl('user_claim_pay', ['policyId' => $id]);
        }
        $token = null;
        $pennies = null;
        $publicKey = null;
        $cardToken = null;
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(Policy::class);
            $policy = $repo->find($id);
            if (!$policy) {
                $logger->info(sprintf('Missing policy'));
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_NOT_FOUND, "Policy not found");
            }

            $token = $request->get("token");
            $pennies = $request->get("pennies");
            $freq = $request->get('premium');
            if ($request->get('_route') == 'purchase_checkout') {
                $priceService = $this->get('app.price');
                $additionalPremium = $policy->getUser()->getAdditionalPremium();
                if ($freq == Policy::PLAN_MONTHLY) {
                    $policy->setPremiumInstallments(12);
                    $priceService->policySetPhonePremium(
                        $policy,
                        PhonePrice::STREAM_MONTHLY,
                        $additionalPremium,
                        new \DateTime()
                    );
                } elseif ($freq == Policy::PLAN_YEARLY) {
                    $policy->setPremiumInstallments(1);
                    $priceService->policySetPhonePremium(
                        $policy,
                        PhonePrice::STREAM_YEARLY,
                        $additionalPremium,
                        new \DateTime()
                    );
                } else {
                    throw new NotFoundHttpException(sprintf('Unknown frequency %s', $freq));
                }
            }
            $csrf = $request->get("csrf");
            $publicKey = $request->get("cko-public-key");
            $cardToken = $request->get("cko-card-token");
            if ($token && $pennies && $csrf) {
                $type = 'modal';
            } elseif ($publicKey && $cardToken) {
                $type = 'redirect';
                $token = $cardToken;
            }

            $this->denyAccessUnlessGranted(PolicyVoter::VIEW, $policy);

            if (!$type) {
                $logger->info(sprintf('Missing params'));
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "Token parameter missing.");
            } elseif ($csrf && !$this->isCsrfTokenValid("checkout", $csrf)) {
                $logger->info(sprintf('Failed csrf'));
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "Invalid CSRF token.");
            } elseif ($type == 'redirect' && $publicKey != $this->getParameter('checkout_apipublic')) {
                $logger->info(sprintf('Failed public key'));
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, "Invalid public key.");
            }

            $amount = null;
            if (mb_strlen($pennies) > 0) {
                $amount = $this->convertFromPennies($pennies);
                // Update card details with 0.01 is a hack for checkout.js implementation
                if ($request->get('_route') == 'purchase_checkout_update' &&
                    $this->areEqualToTwoDp(0.01, $amount)) {
                    $amount = null;
                }
            }
            $logger->info(sprintf('Token: %s / Pennies; %s', $token, $pennies));
            /** @var CheckoutService $checkout */
            $checkout = $this->get('app.checkout');

            if ($request->get('_route') == 'purchase_checkout') {
                $checkout->pay(
                    $policy,
                    $token,
                    $amount,
                    Payment::SOURCE_WEB,
                    null,
                    $this->getIdentityLogWeb($request)
                );
            } else {
                $checkout->updatePaymentMethod(
                    $policy,
                    $token,
                    $amount
                );
            }

            $this->addFlash('success', $successMessage);

            if ($type == 'redirect') {
                return new RedirectResponse($redirectSuccess);
            } else {
                return $this->getSuccessJsonResponse($successMessage);
            }
        } catch (PaymentDeclinedException $e) {
            $logger->error(ApiErrorCode::errorMessage("checkoutAction", ApiErrorCode::EX_PAYMENT_DECLINED, sprintf(
                "Payment declined for policy '%s'",
                $policy->getId()
            )));
            $this->addFlash('error', $errorMessage);
            if ($type == 'redirect') {
                return new RedirectResponse($redirectFailure);
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_POLICY_PAYMENT_DECLINED, 'Failed card');
            }
        } catch (AccessDeniedException $e) {
            $logger->error(ApiErrorCode::errorMessage("checkoutAction", ApiErrorCode::EX_ACCESS_DENIED, sprintf(
                "Access Denied for policy '%s'",
                $policy->getId()
            )));
            $this->addFlash('error', $errorMessage);
            if ($type == 'redirect') {
                return new RedirectResponse($redirectFailure);
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_ACCESS_DENIED, 'Access denied');
            }
        } catch (CommissionException $e) {
            $message = "";
            if ($pennies === null) {
                $message = sprintf("Commission Exception for policy %s on payment without amount", $policy->getId());
            } else {
                $message = sprintf(
                    "Commission Exception for policy %s on payment of %d pennies",
                    $policy->getId(),
                    $pennies
                );
            }
            $logger->error(ApiErrorCode::errorMessage("checkoutAction", ApiErrorCode::EX_COMMISSION, $message));
            if ($type == 'redirect') {
                return new RedirectResponse($redirectSuccess);
            } else {
                return $this->getSuccessJsonResponse($successMessage);
            }
        } catch (\Exception $e) {
            $logger->error(ApiErrorCode::errorMessage("checkoutAction", ApiErrorCode::EX_UNKNOWN, sprintf(
                "Unknown Exception for policy '%s' with message '%s'",
                $policy->getId(),
                $e->getMessage()
            )));
            $this->addFlash('error', $errorMessage);
            if ($type == 'redirect') {
                return new RedirectResponse($redirectFailure);
            } else {
                return $this->getErrorJsonResponse(ApiErrorCode::ERROR_UNKNOWN, 'Unknown Error');
            }
        }
    }
}
