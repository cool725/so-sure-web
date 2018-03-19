<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Gedmo\Loggable\Document\LogEntry;
use AppBundle\Classes\ClientUrl;
use AppBundle\Classes\SoSure;
use AppBundle\Classes\Salva;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Address;
use AppBundle\Document\Company;
use AppBundle\Document\Charge;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Reward;
use AppBundle\Document\Invoice;
use AppBundle\Document\SCode;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Stats;
use AppBundle\Document\ImeiTrait;
use AppBundle\Document\Form\AdminMakeModel;
use AppBundle\Document\Form\Roles;
use AppBundle\Document\OptOut\OptOut;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\JudoFile;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\File\LloydsFile;
use AppBundle\Document\File\ImeiUploadFile;
use AppBundle\Document\File\ScreenUploadFile;
use AppBundle\Document\Form\Cancel;
use AppBundle\Document\Form\Imei;
use AppBundle\Document\Form\BillingDay;
use AppBundle\Document\Form\Chargebacks;
use AppBundle\Form\Type\AddressType;
use AppBundle\Form\Type\BillingDayType;
use AppBundle\Form\Type\CancelPolicyType;
use AppBundle\Form\Type\DirectBacsReceiptType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\ClaimSearchType;
use AppBundle\Form\Type\ChargebacksType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\ImeiType;
use AppBundle\Form\Type\NoteType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Form\Type\SmsOptOutType;
use AppBundle\Form\Type\PartialPolicyType;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PhoneSearchType;
use AppBundle\Form\Type\JudoFileType;
use AppBundle\Form\Type\PicSureSearchType;
use AppBundle\Form\Type\FacebookType;
use AppBundle\Form\Type\BarclaysFileType;
use AppBundle\Form\Type\LloydsFileType;
use AppBundle\Form\Type\ImeiUploadFileType;
use AppBundle\Form\Type\ScreenUploadFileType;
use AppBundle\Form\Type\PendingPolicyCancellationType;
use AppBundle\Form\Type\UserDetailType;
use AppBundle\Form\Type\UserEmailType;
use AppBundle\Form\Type\UserPermissionType;
use AppBundle\Form\Type\UserHighRiskType;
use AppBundle\Form\Type\ClaimFlagsType;
use AppBundle\Form\Type\AdminMakeModelType;
use AppBundle\Form\Type\UserRoleType;
use AppBundle\Exception\RedirectException;
use AppBundle\Service\PushService;
use AppBundle\Event\PicsureEvent;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use CensusBundle\Document\Postcode;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_EMPLOYEE')")
 */
class AdminEmployeeController extends BaseController
{
    use DateTrait;
    use CurrencyTrait;
    use ImeiTrait;

    /**
     * @Route("/", name="admin_home")
     * @Template
     */
    public function indexAction()
    {
        return ['randomImei' => self::generateRandomImei()];
    }

    /**
     * @Route("/phones", name="admin_phones")
     * @Template
     */
    public function phonesAction(Request $request)
    {
        $expectedClaimFrequency = $this->getParameter('expected_claim_frequency');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $makes = $repo->findActiveMakes();
        $phones = $repo->createQueryBuilder();
        $phones = $phones->field('make')->notEqual('ALL');

        $searchForm = $this->get('form.factory')
            ->createNamedBuilder('email_form', PhoneSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $newPhoneForm = $this->get('form.factory')
            ->createNamedBuilder('new_phone_form')
            ->add('os', ChoiceType::class, [
                'required' => true,
                'choices' => Phone::$osTypes,
            ])
            ->add('make', TextType::class)
            ->add('model', TextType::class)
            ->add('add', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('new_phone_form')) {
                $newPhoneForm->handleRequest($request);
                if ($newPhoneForm->isValid()) {
                    $data = $newPhoneForm->getData();
                    $phone = new Phone();
                    $phone->setMake($data['make']);
                    $phone->setModel($data['model']);
                    $phone->setOs($data['os']);
                    $phone->setActive(false);
                    $dm->persist($phone);
                    $dm->flush();
                    $this->addFlash('success', sprintf(
                        'Added phone. %s',
                        $phone
                    ));

                    return new RedirectResponse($this->generateUrl('admin_phones'));
                }
            }
        }

        $searchForm->handleRequest($request);
        $data = $searchForm->get('os')->getData();

        $phones = $phones->field('os')->in($data);
        $data = filter_var($searchForm->get('active')->getData(), FILTER_VALIDATE_BOOLEAN);
        $phones = $phones->field('active')->equals($data);
        $rules = $searchForm->get('rules')->getData();
        if ($rules == 'missing') {
            $phones = $phones->field('suggestedReplacement')->exists(false);
            $phones = $phones->field('replacementPrice')->lte(0);
        } elseif ($rules == 'retired') {
            $retired = new \DateTime();
            $retired->sub(new \DateInterval(sprintf('P%dM', Phone::MONTHS_RETIREMENT + 1)));
            $phones = $phones->field('releaseDate')->lte($retired);
        } elseif ($rules == 'loss') {
            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                if ($phone->policyProfit($expectedClaimFrequency) < 0) {
                    $phoneIds[] = $phone->getId();
                }
            }
            $phones->field('id')->in($phoneIds);
        } elseif ($rules == 'price') {
            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                if (abs($phone->policyProfit($expectedClaimFrequency)) > 30) {
                    $phoneIds[] = $phone->getId();
                }
            }
            $phones->field('id')->in($phoneIds);
        } elseif ($rules == 'brightstar') {
            $replacementPhones = clone $phones;
            $phones = $phones->field('replacementPrice')->lte(0);
            $phones = $phones->field('initialPrice')->gte(300);
            $year = new \DateTime();
            $year->sub(new \DateInterval('P1Y'));
            $phones = $phones->field('releaseDate')->gte($year);

            $phoneIds = [];
            foreach ($phones->getQuery()->execute() as $phone) {
                $phoneIds[] = $phone->getId();
            }
            foreach ($replacementPhones->getQuery()->execute() as $phone) {
                if ($phone->getSuggestedReplacement() &&
                    $phone->getSuggestedReplacement()->getMemory() < $phone->getMemory()) {
                    $phoneIds[] = $phone->getId();
                }
            }

            $phones = $replacementPhones->field('id')->in($phoneIds);
        } elseif ($rules == 'replacement') {
            $phones = $phones->field('suggestedReplacement')->exists(true);
        }
        $phones = $phones->sort('make', 'asc');
        $phones = $phones->sort('model', 'asc');
        $phones = $phones->sort('memory', 'asc');
        $pager = $this->pager($request, $phones);

        return [
            'phones' => $pager->getCurrentPageResults(),
            'form' => $searchForm->createView(),
            'pager' => $pager,
            'new_phone' => $newPhoneForm->createView(),
            'makes' => $makes,
        ];
    }

    /**
     * @Route("/policies", name="admin_policies")
     * @Template("AppBundle::Claims/claimsPolicies.html.twig")
     */
    public function adminPoliciesAction(Request $request)
    {
        try {
            $data = $this->searchPolicies($request);
        } catch (RedirectException $e) {
            return new RedirectResponse($e->getMessage());
        }
        return array_merge($data, [
            'policy_route' => 'admin_policy'
        ]);
    }

    /**
     * @Route("/users", name="admin_users")
     * @Template()
     */
    public function adminUsersAction(Request $request)
    {
        $emailForm = $this->get('form.factory')
            ->createNamedBuilder('email_form')
            ->add('email', EmailType::class)
            ->add('create', SubmitType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('email_form')) {
                $emailForm->handleRequest($request);
                if ($emailForm->isValid()) {
                    $email = $this->getDataString($emailForm->getData(), 'email');
                    $dm = $this->getManager();
                    $userManager = $this->get('fos_user.user_manager');
                    $user = $userManager->createUser();
                    $user->setEnabled(true);
                    $user->setEmail($email);
                    $dm->persist($user);
                    $dm->flush();
                    $this->addFlash('success', sprintf(
                        'Created User. %s',
                        $email
                    ));
                }
            }
        }

        try {
            $data = $this->searchUsers($request);
        } catch (RedirectException $e) {
            return new RedirectResponse($e->getMessage());
        }
        return array_merge($data, [
            'policy_route' => 'admin_policy',
            'email_form' => $emailForm->createView(),
        ]);
    }

    /**
     * @Route("/optout", name="admin_optout")
     * @Template
     */
    public function adminOptOutAction(Request $request)
    {
        $dm = $this->getManager();

        $emailOptOut = new EmailOptOut();
        $smsOptOut = new SmsOptOut();

        $emailForm = $this->get('form.factory')
            ->createNamedBuilder('email_form', EmailOptOutType::class, $emailOptOut)
            ->getForm();
        $smsForm = $this->get('form.factory')
            ->createNamedBuilder('sms_form', SmsOptOutType::class, $smsOptOut)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('email_form')) {
                $emailForm->handleRequest($request);
                if ($emailForm->isValid()) {
                    $dm->persist($emailOptOut);
                    $dm->flush();

                    return new RedirectResponse($this->generateUrl('admin_optout'));
                } else {
                    $this->addFlash('error', sprintf(
                        'Unable to add optout. %s',
                        (string) $emailForm->getErrors()
                    ));
                }
            } elseif ($request->request->has('sms_form')) {
                $smsForm->handleRequest($request);
                if ($smsForm->isValid()) {
                    $dm->persist($smsOptOut);
                    $dm->flush();

                    return new RedirectResponse($this->generateUrl('admin_optout'));
                } else {
                    $this->addFlash('error', sprintf(
                        'Unable to add optout. %s',
                        (string) $smsForm->getErrors()
                    ));
                }
            }
        }
        $repo = $dm->getRepository(OptOut::class);
        $oupouts = $repo->findAll();

        return [
            'optouts' => $oupouts,
            'email_form' => $emailForm->createView(),
            'sms_form' => $smsForm->createView(),
        ];
    }

    /**
     * @Route("/policy/{id}", name="admin_policy")
     * @Template("AppBundle::Admin/claimsPolicy.html.twig")
     */
    public function claimsPolicyAction(Request $request, $id)
    {
        $policyService = $this->get('app.policy');
        $fraudService = $this->get('app.fraud');
        $imeiService = $this->get('app.imei');
        $invitationService = $this->get('app.invitation');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }

        $cancel = new Cancel();
        $cancel->setPolicy($policy);
        $cancelForm = $this->get('form.factory')
            ->createNamedBuilder('cancel_form', CancelPolicyType::class, $cancel)
            ->getForm();
        $pendingCancelForm = $this->get('form.factory')
            ->createNamedBuilder('pending_cancel_form', PendingPolicyCancellationType::class, $policy)
            ->getForm();
        $noteForm = $this->get('form.factory')
            ->createNamedBuilder('note_form', NoteType::class)
            ->getForm();
        $imei = new Imei();
        $imei->setPolicy($policy);
        $imeiForm = $this->get('form.factory')
            ->createNamedBuilder('imei_form', ImeiType::class, $imei)
            ->getForm();
        $facebookForm = $this->get('form.factory')
            ->createNamedBuilder('facebook_form', FacebookType::class, $policy)
            ->getForm();
        $receperioForm = $this->get('form.factory')
            ->createNamedBuilder('receperio_form')->add('rerun', SubmitType::class)
            ->getForm();
        $phoneForm = $this->get('form.factory')
            ->createNamedBuilder('phone_form', PhoneType::class, $policy)
            ->getForm();
        $chargebacks = new Chargebacks();
        $chargebacks->setPolicy($policy);
        $chargebacksForm = $this->get('form.factory')
            ->createNamedBuilder('chargebacks_form', ChargebacksType::class, $chargebacks)
            ->getForm();
        $bacsPayment = new BacsPayment();
        $bacsPayment->setSource(Payment::SOURCE_ADMIN);
        $bacsPayment->setDate(new \DateTime());
        $bacsPayment->setAmount($policy->getPremium()->getYearlyPremiumPrice());

        $bacsForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_form', DirectBacsReceiptType::class, $bacsPayment)
            ->getForm();
        $createForm = $this->get('form.factory')
            ->createNamedBuilder('create_form')
            ->add('create', SubmitType::class)
            ->getForm();
        $connectForm = $this->get('form.factory')
            ->createNamedBuilder('connect_form')
            ->add('email', EmailType::class)
            ->add('connect', SubmitType::class)
            ->getForm();
        $imeiUploadFile = new ImeiUploadFile();
        $imeiUploadForm = $this->get('form.factory')
            ->createNamedBuilder('imei_upload', ImeiUploadFileType::class, $imeiUploadFile)
            ->getForm();
        $screenUploadFile = new ScreenUploadFile();
        $screenUploadForm = $this->get('form.factory')
            ->createNamedBuilder('screen_upload', ScreenUploadFileType::class, $screenUploadFile)
            ->getForm();
        $userTokenForm = $this->get('form.factory')
            ->createNamedBuilder('usertoken_form')
            ->add('regenerate', SubmitType::class)
            ->getForm();
        $billing = new BillingDay();
        $billing->setPolicy($policy);
        $billingForm = $this->get('form.factory')
            ->createNamedBuilder('billing_form', BillingDayType::class, $billing)
            ->getForm();
        $resendEmailForm = $this->get('form.factory')
            ->createNamedBuilder('resend_email_form')->add('resend', SubmitType::class)
            ->getForm();
        $regeneratePolicyScheduleForm = $this->get('form.factory')
            ->createNamedBuilder('regenerate_policy_schedule_form')->add('regenerate', SubmitType::class)
            ->getForm();
        $makeModel = new AdminMakeModel();
        $makeModelForm = $this->get('form.factory')
            ->createNamedBuilder('makemodel_form', AdminMakeModelType::class, $makeModel)
            ->getForm();
        $claim = new Claim();
        $claim->setPolicy($policy);
        $claimFlags = $this->get('form.factory')
            ->createNamedBuilder('claimflags', ClaimFlagsType::class, $claim)
            ->getForm();
        $debtForm = $this->get('form.factory')
            ->createNamedBuilder('debt_form')->add('debt', SubmitType::class)
            ->getForm();
        $picsureForm = $this->get('form.factory')
            ->createNamedBuilder('picsure_form')
            ->add('approve', SubmitType::class)
            ->add('preapprove', SubmitType::class)
            ->getForm();
        $swapPaymentPlanForm = $this->get('form.factory')
            ->createNamedBuilder('swap_payment_plan_form')->add('swap', SubmitType::class)
            ->getForm();
        $payPolicyForm = $this->get('form.factory')
            ->createNamedBuilder('pay_policy_form')
            ->add('monthly', SubmitType::class)
            ->add('yearly', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cancel_form')) {
                $cancelForm->handleRequest($request);
                if ($cancelForm->isValid()) {
                    if ($policy->canCancel($cancel->getCancellationReason())) {
                        $policyService->cancel(
                            $policy,
                            $cancel->getCancellationReason()
                        );
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s was cancelled.', $policy->getPolicyNumber())
                        );
                    } else {
                        $this->addFlash('error', sprintf(
                            'Unable to cancel Policy %s due to %s',
                            $policy->getPolicyNumber(),
                            $cancel->getCancellationReason()
                        ));
                    }

                    return $this->redirectToRoute('admin_policies');
                }
            } elseif ($request->request->has('pending_cancel_form')) {
                $pendingCancelForm->handleRequest($request);
                if ($pendingCancelForm->isValid()) {
                    if ($pendingCancelForm->get('clear')->isClicked()) {
                        $policy->setPendingCancellation(null);
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s is no longer scheduled to be cancelled', $policy->getPolicyNumber())
                        );
                    } else {
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s is scheduled to be cancelled', $policy->getPolicyNumber())
                        );
                    }
                    $dm->flush();

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('imei_form')) {
                $imeiForm->handleRequest($request);
                if ($imeiForm->isValid()) {
                    $policy->adjustImei($imei->getImei(), false);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Policy %s imei updated.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('phone_form')) {
                $phoneForm->handleRequest($request);
                if ($phoneForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Policy %s phone updated.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('note_form')) {
                $noteForm->handleRequest($request);
                if ($noteForm->isValid()) {
                    $policy->addNote(json_encode([
                        'user_id' => $this->getUser()->getId(),
                        'name' => $this->getUser()->getName(),
                        'notes' => $noteForm->getData()['notes']
                    ]));
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Added note to Policy %s.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('facebook_form')) {
                $facebookForm->handleRequest($request);
                if ($facebookForm->isValid()) {
                    $policy->getUser()->resetFacebook();
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Policy %s facebook cleared.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('receperio_form')) {
                $receperioForm->handleRequest($request);
                if ($receperioForm->isValid()) {
                    if ($policy->getImei()) {
                        $imeiService->checkImei($policy->getPhone(), $policy->getImei(), $policy->getUser());
                        $policy->addCheckmendCertData($imeiService->getCertId(), $imeiService->getResponseData());

                        // clear out the cache - if we're re-checking it likely
                        // means that recipero has updated their data
                        $imeiService->clearMakeModelCheckCache($policy->getSerialNumber());
                        $imeiService->clearMakeModelCheckCache($policy->getImei());

                        $serialNumber = $policy->getSerialNumber();
                        if (!$serialNumber) {
                            $serialNumber= $policy->getImei();
                        }
                        $imeiService->checkSerial(
                            $policy->getPhone(),
                            $serialNumber,
                            $policy->getImei(),
                            $policy->getUser()
                        );
                        $policy->addCheckmendSerialData($imeiService->getResponseData());
                        $dm->flush();
                        $this->addFlash(
                            'warning',
                            '(Re)ran Receperio Checkes. Check results below.'
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Unable to run receperio checks (no imei number)'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('resend_email_form')) {
                $resendEmailForm->handleRequest($request);
                if ($resendEmailForm->isValid()) {
                    $policyService->resendPolicyEmail($policy);
                    $this->addFlash(
                        'success',
                        'Resent the policy email.'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('bacs_form')) {
                $bacsForm->handleRequest($request);
                if ($bacsForm->isValid()) {
                    if ($this->areEqualToTwoDp(
                        $bacsPayment->getAmount(),
                        $policy->getPremium()->getMonthlyPremiumPrice()
                    )) {
                        $bacsPayment->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                    } elseif ($this->areEqualToTwoDp(
                        $bacsPayment->getAmount(),
                        $policy->getPremium()->getYearlyPremiumPrice()
                    )) {
                        $bacsPayment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
                    } else {
                        $this->get('logger')->warning(sprintf(
                            'Unable to determine commission on bacs payment for policy %s',
                            $policy->getId()
                        ));
                    }

                    $policy->addPayment($bacsPayment);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Added Payment'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('create_form')) {
                $createForm->handleRequest($request);
                if ($createForm->isValid()) {
                    $policyService->create($policy, null, true);
                    $this->addFlash(
                        'success',
                        'Created Policy'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('connect_form')) {
                $connectForm->handleRequest($request);
                if ($connectForm->isValid()) {
                    $invitation = $invitationService->inviteByEmail(
                        $policy,
                        $connectForm->getData()['email'],
                        null,
                        true
                    );
                    $invitationService->accept(
                        $invitation,
                        $invitation->getInvitee()->getFirstPolicy(),
                        null,
                        true
                    );
                    $this->addFlash(
                        'success',
                        'Connected Users'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('imei_upload')) {
                $imeiUploadForm->handleRequest($request);
                if ($imeiUploadForm->isSubmitted() && $imeiUploadForm->isValid()) {
                    $dm = $this->getManager();
                    // we're assuming that a manaual check is done to verify
                    $policy->setPhoneVerified(true);
                    $imeiUploadFile->setPolicy($policy);
                    $imeiUploadFile->setBucket('policy.so-sure.com');
                    $imeiUploadFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $policy->addPolicyFile($imeiUploadFile);
                    $dm->persist($imeiUploadFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('screen_upload')) {
                $screenUploadForm->handleRequest($request);
                if ($screenUploadForm->isSubmitted() && $screenUploadForm->isValid()) {
                    $dm = $this->getManager();
                    // we're assuming that a manaual check is done to verify
                    $policy->setScreenVerified(true);
                    $screenUploadFile->setPolicy($policy);
                    $screenUploadFile->setBucket('policy.so-sure.com');
                    $screenUploadFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $policy->addPolicyFile($screenUploadFile);
                    $dm->persist($screenUploadFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('usertoken_form')) {
                $userTokenForm->handleRequest($request);
                if ($userTokenForm->isSubmitted() && $userTokenForm->isValid()) {
                    $policy->getUser()->resetToken();
                    $dm = $this->getManager();
                    $dm->flush();

                    $identity = $this->get('app.cognito.identity');
                    if ($identity->deleteLastestMobileToken($policy->getUser())) {
                        $this->addFlash(
                            'success',
                            'Reset user token & deleted cognito credentials'
                        );
                    } else {
                        $this->addFlash(
                            'success',
                            'Reset user token. No cognito credentials present'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('billing_form')) {
                $billingForm->handleRequest($request);
                if ($billingForm->isValid()) {
                    $policyService->adjustScheduledPayments($policy);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated billing date'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('regenerate_policy_schedule_form')) {
                $regeneratePolicyScheduleForm->handleRequest($request);
                if ($regeneratePolicyScheduleForm->isValid()) {
                    $policyService->generatePolicyTerms($policy);
                    $policyService->generatePolicySchedule($policy);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Re-generated Policy Terms & Schedule'
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('makemodel_form')) {
                $makeModelForm->handleRequest($request);
                if ($makeModelForm->isValid()) {
                    $imeiValidator = $this->get('app.imei');
                    $phone = new Phone();
                    $imeiValidator->checkSerial(
                        $phone,
                        $makeModel->getSerialNumberOrImei(),
                        null,
                        $policy->getUser(),
                        null,
                        false
                    );
                    $this->addFlash(
                        'success',
                        sprintf('%s', json_encode($imeiValidator->getResponseData()))
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                } else {
                    $this->addFlash('error', 'Unable to run make/model check');
                }
            } elseif ($request->request->has('chargebacks_form')) {
                $chargebacksForm->handleRequest($request);
                if ($chargebacksForm->isValid()) {
                    if ($chargeback = $chargebacks->getChargeback()) {
                        // To appear for the correct account month, should be when we assign
                        // the chargeback to the policy
                        $chargeback->setDate(new \DateTime());
                        $policy->addPayment($chargeback);
                        $dm->flush();
                        $this->addFlash(
                            'success',
                            sprintf('Added chargeback %s to policy', $chargeback->getReference())
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Unknown chargeback'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('debt_form')) {
                $debtForm->handleRequest($request);
                if ($debtForm->isValid()) {
                    $policy->setDebtCollector(Policy::DEBT_COLLECTOR_WISE);
                    $dm->flush();
                    $email = null;
                    $customerSubject = null;

                    if ($policy->getDebtCollector() == Policy::DEBT_COLLECTOR_WISE) {
                        $email = 'debts@awise.demon.co.uk';
                        $customerSubject = 'Wise has now been authorised to chase your debt to so-sure';
                    }

                    if ($email) {
                        $mailer = $this->get('app.mailer');
                        $mailer->sendTemplate(
                            'Debt Collection Request',
                            $email,
                            'AppBundle:Email:policy/debtCollection.html.twig',
                            ['policy' => $policy],
                            'AppBundle:Email:policy/debtCollection.txt.twig',
                            ['policy' => $policy],
                            null,
                            'bcc@so-sure.com'
                        );

                        $mailer->sendTemplate(
                            $customerSubject,
                            $policy->getUser()->getEmail(),
                            'AppBundle:Email:policy/debtCollectionCustomer.html.twig',
                            ['policy' => $policy],
                            'AppBundle:Email:policy/debtCollectionCustomer.txt.twig',
                            ['policy' => $policy],
                            null,
                            'bcc@so-sure.com'
                        );

                        $this->addFlash(
                            'success',
                            sprintf('Emailed debt collector and set flag on policy')
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('picsure_form')) {
                $picsureForm->handleRequest($request);
                if ($picsureForm->isValid()) {
                    if ($policy->getPolicyTerms()->isPicSureEnabled() && !$policy->isPicSureValidated()) {
                        if ($picsureForm->get('approve')->isClicked()) {
                            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
                        } elseif ($picsureForm->get('preapprove')->isClicked()) {
                            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_PREAPPROVED);
                        } else {
                            throw new \Exception('Unknown button click');
                        }
                        $policy->setPicSureApprovedDate(new \DateTime());
                        $dm->flush();
                        $this->addFlash(
                            'success',
                            sprintf('Set pic-sure to %s', $policy->getPicSureStatus())
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Policy is not a pic-sure policy or policy is already pic-sure (pre)approved'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('swap_payment_plan_form')) {
                $swapPaymentPlanForm->handleRequest($request);
                if ($swapPaymentPlanForm->isValid()) {
                    $policyService->swapPaymentPlan($policy);
                    // @codingStandardsIgnoreStart
                    $this->addFlash(
                        'success',
                        'Payment Plan has been swapped. For now, please manually adjust final scheduled payment to current date.'
                    );
                    // @codingStandardsIgnoreEnd
                }
            } elseif ($request->request->has('pay_policy_form')) {
                $payPolicyForm->handleRequest($request);
                if ($payPolicyForm->isValid()) {
                    $date = new \DateTime();
                    $phone = $policy->getPhone();
                    if ($payPolicyForm->get('monthly')->isClicked()) {
                        $amount = $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(null, $date);
                    } elseif ($payPolicyForm->get('yearly')->isClicked()) {
                        $amount = $phone->getCurrentPhonePrice()->getYearlyPremiumPrice(null, $date);
                    } else {
                        throw new \Exception('1 or 12 payments only');
                    }

                    $judopay = $this->get('app.judopay');
                    $details = $judopay->runTokenPayment(
                        $policy->getUser(),
                        $amount,
                        $date->getTimestamp(),
                        $policy->getId()
                    );
                    $judopay->add(
                        $policy,
                        $details['receiptId'],
                        $details['consumer']['consumerToken'],
                        $details['cardDetails']['cardToken'],
                        Payment::SOURCE_TOKEN,
                        $policy->getUser()->getPaymentMethod()->getDeviceDna(),
                        $date
                    );
                    $this->addFlash(
                        'success',
                        'Policy is paid for'
                    );
                }
            }
        }
        $checks = $fraudService->runChecks($policy);
        $now = new \DateTime();

        return [
            'policy' => $policy,
            'cancel_form' => $cancelForm->createView(),
            'pending_cancel_form' => $pendingCancelForm->createView(),
            'note_form' => $noteForm->createView(),
            'imei_form' => $imeiForm->createView(),
            'phone_form' => $phoneForm->createView(),
            'formClaimFlags' => $claimFlags->createView(),
            'facebook_form' => $facebookForm->createView(),
            'receperio_form' => $receperioForm->createView(),
            'bacs_form' => $bacsForm->createView(),
            'create_form' => $createForm->createView(),
            'connect_form' => $connectForm->createView(),
            'imei_upload_form' => $imeiUploadForm->createView(),
            'screen_upload_form' => $screenUploadForm->createView(),
            'usertoken_form' => $userTokenForm->createView(),
            'billing_form' => $billingForm->createView(),
            'resend_email_form' => $resendEmailForm->createView(),
            'regenerate_policy_schedule_form' => $regeneratePolicyScheduleForm->createView(),
            'makemodel_form' => $makeModelForm->createView(),
            'chargebacks_form' => $chargebacksForm->createView(),
            'debt_form' => $debtForm->createView(),
            'picsure_form' => $picsureForm->createView(),
            'swap_payment_plan_form' => $swapPaymentPlanForm->createView(),
            'pay_policy_form' => $payPolicyForm->createView(),
            'fraud' => $checks,
            'policy_route' => 'admin_policy',
            'policy_history' => $this->getSalvaPhonePolicyHistory($policy->getId()),
            'user_history' => $this->getUserHistory($policy->getUser()->getId()),
            'suggested_cancellation_date' => $now->add(new \DateInterval('P30D')),
            'claim_types' => Claim::$claimTypes,
            'phones' => $dm->getRepository(Phone::class)->findActive()->getQuery()->execute(),
        ];
    }

    /**
     * @Route("/user/{id}", name="admin_user")
     * @Template("AppBundle::Claims/user.html.twig")
     */
    public function adminUserAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->find($id);

        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }
        $censusDM = $this->getCensusManager();
        $postcodeRepo = $censusDM->getRepository(PostCode::class);
        $postcode = null;
        $census = null;
        $income = null;
        if ($user->getBillingAddress()) {
            $search = $this->get('census.search');
            $postcode = $search->getPostcode($user->getBillingAddress()->getPostcode());
            $census = $search->findNearest($user->getBillingAddress()->getPostcode());
            $income = $search->findIncome($user->getBillingAddress()->getPostcode());
        }

        $resetForm = $this->get('form.factory')
            ->createNamedBuilder('reset_form')
            ->add('reset', SubmitType::class)
            ->getForm();
        $userDetailForm = $this->get('form.factory')
            ->createNamedBuilder('user_detail_form', UserDetailType::class, $user)
            ->getForm();
        $userEmailForm = $this->get('form.factory')
            ->createNamedBuilder('user_email_form', UserEmailType::class, $user)
            ->getForm();
        $userPermissionForm = $this->get('form.factory')
            ->createNamedBuilder('user_permission_form', UserPermissionType::class, $user)
            ->getForm();
        $userHighRiskForm = $this->get('form.factory')
            ->createNamedBuilder('user_high_risk_form', UserHighRiskType::class, $user)
            ->getForm();
        $makeModel = new AdminMakeModel();
        $makeModelForm = $this->get('form.factory')
            ->createNamedBuilder('makemodel_form', AdminMakeModelType::class, $makeModel)
            ->getForm();
        $address = $user->getBillingAddress();
        $userAddressForm = $this->get('form.factory')
            ->createNamedBuilder('user_address_form', AddressType::class, $address)
            ->getForm();
        $policyData = new SalvaPhonePolicy();
        $policyForm = $this->get('form.factory')
            ->createNamedBuilder('policy_form', PartialPolicyType::class, $policyData)
            ->getForm();
        $sanctionsForm = $this->get('form.factory')
            ->createNamedBuilder('sanctions_form')
            ->add('confirm', SubmitType::class)
            ->getForm();
        $role = new Roles();
        $role->setRoles($user->getRoles());
        $roleForm = $this->get('form.factory')
            ->createNamedBuilder('user_role_form', UserRoleType::class, $role)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('user_role_form')) {
                $roleForm->handleRequest($request);
                if ($roleForm->isValid()) {
                    $newRoles = $role->getRoles();
                    $user->setRoles($newRoles);
                    $this->get('fos_user.user_manager')->updateUser($user);
                    $this->addFlash(
                        'success',
                        'Role(s) updated'
                    );
                    return new RedirectResponse($this->generateUrl('admin_user', ['id' => $id]));
                }
            } elseif ($request->request->has('reset_form')) {
                $resetForm->handleRequest($request);
                if ($resetForm->isValid()) {
                    if (null === $user->getConfirmationToken()) {
                        /** @var $tokenGenerator \FOS\UserBundle\Util\TokenGeneratorInterface */
                        $tokenGenerator = $this->get('fos_user.util.token_generator');
                        $user->setConfirmationToken($tokenGenerator->generateToken());
                    }

                    $this->container->get('fos_user.mailer')->sendResettingEmailMessage($user);
                    $user->setPasswordRequestedAt(new \DateTime());
                    $this->get('fos_user.user_manager')->updateUser($user);

                    $this->addFlash(
                        'success',
                        'Reset email was sent.'
                    );

                    return new RedirectResponse($this->generateUrl('admin_user', ['id' => $id]));
                }
            } elseif ($request->request->has('policy_form')) {
                $policyForm->handleRequest($request);
                if ($policyForm->isValid()) {
                    $imeiValidator = $this->get('app.imei');
                    if (!$imeiValidator->isImei($policyData->getImei()) ||
                        $imeiValidator->isLostImei($policyData->getImei()) ||
                        $imeiValidator->isDuplicatePolicyImei($policyData->getImei())) {
                        $this->addFlash(
                            'error',
                            'Imei is invalid, lost, or duplicate'
                        );

                        return $this->redirectToRoute('admin_user', ['id' => $id]);
                    }

                    if (!$user->hasValidDetails() || !$user->hasValidBillingDetails()) {
                            $this->addFlash(
                                'error',
                                'User is missing details (mobile/address/etc)'
                            );

                            return $this->redirectToRoute('admin_user', ['id' => $id]);
                    }

                    $policyService = $this->get('app.policy');
                    $serialNumber = $policyData->getSerialNumber();

                    $missingSerialNumber = false;
                    if ($policyData->getPhone()->isApple() && !$this->isAppleSerialNumber($serialNumber)) {
                        $missingSerialNumber = true;

                        # Admin's can create without serial number if necessary
                        if (!$this->getUser()->hasRole('ROLE_ADMIN')) {
                            $this->addFlash(
                                'error',
                                'Missing Serial Number - unable to create policy'
                            );

                            return $this->redirectToRoute('admin_user', ['id' => $id]);
                        }
                    }

                    // For phones without a serial number, run check on imei
                    if (!$serialNumber) {
                        $serialNumber = $policyData->getImei();
                    }

                    $newPolicy = $policyService->init(
                        $user,
                        $policyData->getPhone(),
                        $policyData->getImei(),
                        $serialNumber
                    );

                    $dm->persist($newPolicy);
                    $dm->flush();

                    if ($missingSerialNumber) {
                        $this->addFlash(
                            'warning',
                            'Created Partial Policy - Missing Expected Serial Number'
                        );
                    } else {
                        $this->addFlash(
                            'success',
                            'Created Partial Policy'
                        );
                    }

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_detail_form')) {
                $userDetailForm->handleRequest($request);
                if ($userDetailForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Update User'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_address_form')) {
                $userAddressForm->handleRequest($request);
                if ($userAddressForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated User Address'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_email_form')) {
                $userEmailForm->handleRequest($request);
                if ($userEmailForm->isValid()) {
                    $userRepo = $this->getManager()->getRepository(User::class);
                    $existingUser = $userRepo->findOneBy(['emailCanonical' => strtolower($user->getEmail())]);
                    if ($existingUser) {
                        // @codingStandardsIgnoreStart
                        $this->addFlash(
                            'error',
                            'Sorry, but that email already exists in our system. Please contact us to resolve this issue.'
                        );
                        // @codingStandardsIgnoreEnd

                        return $this->redirectToRoute('admin_user', ['id' => $id]);
                    }

                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Changed User Email'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_permission_form')) {
                $userPermissionForm->handleRequest($request);
                if ($userPermissionForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated User Permissions'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('user_high_risk_form')) {
                $userHighRiskForm->handleRequest($request);
                if ($userHighRiskForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated User High Risk'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('makemodel_form')) {
                $makeModelForm->handleRequest($request);
                if ($makeModelForm->isValid()) {
                    $imeiValidator = $this->get('app.imei');
                    $phone = new Phone();
                    $imeiValidator->checkSerial(
                        $phone,
                        $makeModel->getSerialNumberOrImei(),
                        null,
                        $user,
                        null,
                        false
                    );
                    $this->addFlash(
                        'success',
                        sprintf('%s', json_encode($imeiValidator->getResponseData()))
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                } else {
                    $this->addFlash('error', 'Unable to run make/model check');
                }
            } elseif ($request->request->has('sanctions_form')) {
                $sanctionsForm->handleRequest($request);
                if ($sanctionsForm->isValid()) {
                    foreach ($user->getSanctionsMatches() as $match) {
                        $match->setManuallyVerified(true);
                    }
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Verified Sanctions'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            }
        }
        
        return [
            'user' => $user,
            'reset_form' => $resetForm->createView(),
            'policy_form' => $policyForm->createView(),
            'role_form' => $roleForm->createView(),
            'user_detail_form' => $userDetailForm->createView(),
            'user_email_form' => $userEmailForm->createView(),
            'user_address_form' => $userAddressForm->createView(),
            'user_permission_form' => $userPermissionForm->createView(),
            'user_high_risk_form' => $userHighRiskForm->createView(),
            'makemodel_form' => $makeModelForm->createView(),
            'sanctions_form' => $sanctionsForm->createView(),
            'postcode' => $postcode,
            'census' => $census,
            'income' => $income,
            'policy_route' => 'admin_policy',
        ];
    }

    /**
     * @Route("/claims", name="admin_claims")
     * @Template("AppBundle::Admin/claims.html.twig")
     */
    public function adminClaimsAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $qb = $repo->createQueryBuilder();

        $form = $this->createForm(ClaimSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $status = $form->get('status')->getData();
        $claimNumber = $form->get('number')->getData();
        $claimId = $form->get('id')->getData();
        $qb = $qb->field('status')->in($status);
        if (strlen($claimNumber) > 0) {
            $qb = $qb->field('number')->equals(new MongoRegex(sprintf("/.*%s.*/i", $claimNumber)));
        }
        if (strlen($claimId) > 0) {
            $qb = $qb->field('id')->equals(new \MongoId($claimId));
        }
        $qb = $qb->sort('replacementReceivedDate', 'desc')
                ->sort('approvedDate', 'desc')
                ->sort('lossDate', 'desc')
                ->sort('notificationDate', 'desc');
        $pager = $this->pager($request, $qb);
        return [
            'claims' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'phones' => $dm->getRepository(Phone::class)->findActive()->getQuery()->execute(),
            'claim_types' => Claim::$claimTypes,
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/scheduled-payments", name="admin_scheduled_payments")
     * @Route("/scheduled-payments/{year}/{month}", name="admin_scheduled_payments_date")
     * @Template
     */
    public function adminScheduledPaymentsAction($year = null, $month = null)
    {
        $now = new \DateTime();
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = new \DateTime(sprintf('%d-%d-01', $year, $month));
        $end = $this->endOfMonth($date);

        $dm = $this->getManager();
        $scheduledPaymentRepo = $dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $scheduledPaymentRepo->findMonthlyScheduled($date);
        $total = 0;
        foreach ($scheduledPayments as $scheduledPayment) {
            if (in_array(
                $scheduledPayment->getStatus(),
                [ScheduledPayment::STATUS_SCHEDULED, ScheduledPayment::STATUS_SUCCESS]
            )) {
                $total += $scheduledPayment->getAmount();
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'end' => $end,
            'scheduledPayments' => $scheduledPayments,
            'total' => $total,
        ];
    }

    /**
     * @Route("/reports", name="admin_reports")
     * @Template
     */
    public function adminReportsAction(Request $request)
    {
        $data = [];
        $start = $request->get('start');
        $end = $request->get('end');
        if (!$start) {
            $start = new \DateTime();
            $start->sub(new \DateInterval('P7D'));
        } else {
            $start = new \DateTime($start, new \DateTimeZone(SoSure::TIMEZONE));
        }
        if (!$end) {
            $end = new \DateTime();
        } else {
            $end = new \DateTime($end, new \DateTimeZone(SoSure::TIMEZONE));
        }

        $reporting = $this->get('app.reporting');
        $report = $reporting->report($start, $end);
        $report['data'] = array_merge($report['data'], $reporting->connectionReport());

        return $report;
    }

    /**
     * @Route("/pl", name="admin_quarterly_pl")
     * @Route("/pl/{year}/{month}", name="admin_quarterly_pl_date")
     * @Template
     */
    public function adminQuarterlyPLAction(Request $request, $year = null, $month = null)
    {
        if ($request->get('_route') == "admin_quarterly_pl") {
            $now = new \DateTime();
            $now = $now->sub(new \DateInterval('P1Y'));
            return new RedirectResponse($this->generateUrl('admin_quarterly_pl_date', [
                'year' => $now->format('Y'),
                'month' => $now->format('m'),
            ]));
        }
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $year, $month),
            new \DateTimeZone(SoSure::TIMEZONE)
        );

        $data = [];

        $reporting = $this->get('app.reporting');
        $report = $reporting->getQuarterlyPL($date);

        return ['data' => $report];
    }

    /**
     * @Route("/pl/print/{year}/{month}", name="admin_quarterly_pl_print")
     */
    public function adminAccountsPrintAction($year, $month)
    {
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $year, $month),
            new \DateTimeZone(SoSure::TIMEZONE)
        );

        $templating = $this->get('templating');
        $snappyPdf = $this->get('knp_snappy.pdf');
        $snappyPdf->setOption('orientation', 'Portrait');
        $snappyPdf->setOption('page-size', 'A4');
        $reporting = $this->get('app.reporting');
        $report = $reporting->getQuarterlyPL($date);
        $html = $templating->render('AppBundle:Pdf:adminQuarterlyPL.html.twig', [
            'data' => $report,
        ]);

        return new Response(
            $snappyPdf->getOutputFromHtml($html),
            200,
            array(
                'Content-Type'          => 'application/pdf',
                'Content-Disposition'   => sprintf('attachment; filename="so-sure-pl-%d-%d.pdf"', $year, $month)
            )
        );
    }

    /**
     * @Route("/connections", name="admin_connections")
     * @Template
     */
    public function connectionsAction()
    {
        return [
            'data' => $this->getConnectionData(),
        ];
    }

    /**
     * @Route("/connections/print", name="admin_connections_print")
     * @Template
     */
    public function connectionsPrintAction()
    {
        $response = new StreamedResponse();
        $response->setCallback(function () {
            $handle = fopen('php://output', 'w+');

            // Add the header of the CSV file
            fputcsv($handle, [
                'Policy Number',
                'Policy Inception Date',
                'Number of Connections',
                'Connection Date 1',
                'Connection Date 2',
                'Connection Date 3',
                'Connection Date 4',
                'Connection Date 5',
                'Connection Date 6',
                'Connection Date 7',
                'Connection Date 8',
            ]);
            foreach ($this->getConnectionData() as $policy) {
                $line = array_merge([
                    $policy['number'],
                    $policy['date'],
                    $policy['connection_count'],
                ], $policy['connections']);
                fputcsv(
                    $handle, // The file pointer
                    $line
                );
            }

            fclose($handle);
        });

        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="so-sure-connections.csv"');

        return $response;
    }

    /**
     * @Route("/imei", name="admin_imei")
     * @Template
     */
    public function imeiAction(Request $request)
    {
        $dm = $this->getManager();
        $logRepo = $dm->getRepository(LogEntry::class);
        $chargeRepo = $dm->getRepository(Charge::class);

        $form = $this->createFormBuilder()
            ->add('imei', TextType::class, array(
                'label' => "IMEI",
            ))
            ->add('search', SubmitType::class, array(
                'label' => "Search",
            ))
            ->getForm();
        $history = null;
        $charges = null;

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $imei = $form->getData()['imei'];
            $history = $logRepo->findBy([
                'data.imei' => $imei
            ]);
            $charges = $chargeRepo->findBy(['details' => $imei]);
        }

        return [
            'history' => $history,
            'charges' => $charges,
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/detected-imei", name="admin_detected_imei")
     * @Template
     */
    public function detectedImeiAction()
    {
        $redis = $this->get('snc_redis.default');
        /*
                $redis->lpush('DETECTED-IMEI', json_encode([
                    'detected_imei' => 'a123',
                    'suggested_imei' => 'a456',
                    'bucket' => 'a',
                    'key' => 'key', 
                ]));
        */
        $imeis = [];
        if ($imei = $redis->lpop('DETECTED-IMEI')) {
            $imeis[] = json_decode($imei, true);
            $redis->lpush('DETECTED-IMEI', $imei);
        }
        return [
            'imeis' => $imeis,
        ];
    }

    private function getConnectionData()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(StandardConnection::class);
        $connections = $repo->findAll();
        $data = [];
        foreach ($connections as $connection) {
            if (!isset($data[$connection->getSourcePolicy()->getId()])) {
                $data[$connection->getSourcePolicy()->getId()] = [
                    'id' => $connection->getSourcePolicy()->getId(),
                    'date' => $connection->getSourcePolicy()->getStart() ?
                        $connection->getSourcePolicy()->getStart()->format('d M Y') :
                        '',
                    'number' => $connection->getSourcePolicy()->getPolicyNumber(),
                    'connections' => [],
                    'connections_details' => [],
                    'isCancelled' => $connection->getSourcePolicy()->isCancelled(),
                ];
            }
            $data[$connection->getSourcePolicy()->getId()]['connections'][] = $connection->getDate() ?
                $connection->getDate()->format('d M Y') :
                '';
            $data[$connection->getSourcePolicy()->getId()]['connections_details'][] = [
                'date' => $connection->getDate() ? $connection->getDate()->format('d M Y') : '',
                'value' => $connection->getValue(),
            ];
        }

        usort($data, function ($a, $b) {
            return $a['date'] >= $b['date'];
        });

        foreach ($data as $key => $policy) {
            $data[$key]['connection_count'] = count($policy['connections']);
            $data[$key]['connections'] = array_slice($policy['connections'], 0, 8);
        }

        return $data;
    }

    /**
     * @Route("/rewards", name="admin_rewards")
     * @Template
     */
    public function rewardsAction(Request $request)
    {
        $connectForm = $this->get('form.factory')
            ->createNamedBuilder('connectForm')
            ->add('email', EmailType::class)
            ->add('amount', TextType::class)
            ->add('rewardId', HiddenType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $rewardForm = $this->get('form.factory')
            ->createNamedBuilder('rewardForm')
            ->add('firstName', TextType::class)
            ->add('lastName', TextType::class)
            ->add('code', TextType::class)
            ->add('email', EmailType::class)
            ->add('defaultValue', TextType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $dm = $this->getManager();
        $rewardRepo = $dm->getRepository(Reward::class);
        $userRepo = $dm->getRepository(User::class);
        $rewards = $rewardRepo->findAll();

        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('connectForm')) {
                    $connectForm->handleRequest($request);
                    if ($connectForm->isValid()) {
                        if ($sourceUser = $userRepo->findOneBy([
                                'emailCanonical' => strtolower($connectForm->getData()['email'])
                            ])) {
                            $reward = $rewardRepo->find($connectForm->getData()['rewardId']);
                            $invitationService = $this->get('app.invitation');
                            foreach ($sourceUser->getValidPolicies() as $policy) {
                                $invitationService->addReward(
                                    $policy,
                                    $reward,
                                    $this->toTwoDp($connectForm->getData()['amount'])
                                );
                            }
                            $this->addFlash('success', sprintf(
                                'Added reward connection'
                            ));

                            return new RedirectResponse($this->generateUrl('admin_rewards'));
                        } else {
                            throw new \InvalidArgumentException(sprintf(
                                'Unable to add reward bonus. %s does not exist as a user',
                                $connectForm->getData()['email']
                            ));
                        }
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add reward connection. %s',
                            (string) $connectForm->getErrors()
                        ));
                    }
                } elseif ($request->request->has('rewardForm')) {
                    $rewardForm->handleRequest($request);
                    if ($rewardForm->isValid()) {
                        $userManager = $this->get('fos_user.user_manager');
                        $user = $userManager->createUser();
                        $user->setEnabled(true);
                        $user->setEmail($this->getDataString($rewardForm->getData(), 'email'));
                        $user->setFirstName($this->getDataString($rewardForm->getData(), 'firstName'));
                        $user->setLastName($this->getDataString($rewardForm->getData(), 'lastName'));
                        $reward = new Reward();
                        $reward->setUser($user);
                        $reward->setDefaultValue($this->getDataString($rewardForm->getData(), 'defaultValue'));
                        $dm->persist($user);
                        $dm->persist($reward);

                        $code = $this->getDataString($rewardForm->getData(), 'code');
                        if (strlen($code) > 0) {
                            $scode = new SCode();
                            $scode->setCode($code);
                            $scode->setReward($reward);
                            $scode->setType(SCode::TYPE_REWARD);
                            $dm->persist($scode);
                        }
                        $dm->flush();
                        $this->addFlash('success', sprintf(
                            'Added reward'
                        ));

                        return new RedirectResponse($this->generateUrl('admin_rewards'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add reward. %s',
                            (string) $rewardForm->getErrors()
                        ));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return [
            'rewards' => $rewards,
            'connectForm' => $connectForm->createView(),
            'rewardForm' => $rewardForm->createView(),
        ];
    }

    /**
     * @Route("/company", name="admin_company")
     * @Template
     */
    public function companyAction(Request $request)
    {
        $belongForm = $this->get('form.factory')
            ->createNamedBuilder('belongForm')
            ->add('email', EmailType::class)
            ->add('companyId', HiddenType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $companyForm = $this->get('form.factory')
            ->createNamedBuilder('companyForm')
            ->add('name', TextType::class)
            ->add('address1', TextType::class)
            ->add('address2', TextType::class, ['required' => false])
            ->add('address3', TextType::class, ['required' => false])
            ->add('city', TextType::class)
            ->add('postcode', TextType::class)
            ->add('next', SubmitType::class)
            ->getForm();

        $dm = $this->getManager();
        $companyRepo = $dm->getRepository(Company::class);
        $userRepo = $dm->getRepository(User::class);
        $companies = $companyRepo->findAll();

        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('belongForm')) {
                    $belongForm->handleRequest($request);
                    if ($belongForm->isValid()) {
                        $user = $userRepo->findOneBy([
                            'emailCanonical' => strtolower($belongForm->getData()['email'])
                        ]);
                        if (!$user) {
                            $userManager = $this->get('fos_user.user_manager');
                            $user = $userManager->createUser();
                            $user->setEnabled(true);
                            $user->setEmail($this->getDataString($belongForm->getData(), 'email'));
                            $dm->persist($user);
                        }
                        $company = $companyRepo->find($belongForm->getData()['companyId']);
                        if (!$company) {
                            throw new \InvalidArgumentException(sprintf(
                                'Unable to add user (%s) to company. Company is missing',
                                $belongForm->getData()['email']
                            ));
                        }
                        $company->addUser($user);
                        if (!$user->getBillingAddress()) {
                            $user->setBillingAddress($company->getAddress());
                        }
                        $dm->flush();
                        $this->addFlash('success', sprintf(
                            'Added %s to %s',
                            $user->getName(),
                            $company->getName()
                        ));

                        return new RedirectResponse($this->generateUrl('admin_company'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add user to company. %s',
                            (string) $belongForm->getErrors()
                        ));
                    }
                } elseif ($request->request->has('companyForm')) {
                    $companyForm->handleRequest($request);
                    if ($companyForm->isValid()) {
                        $company = new Company();
                        $company->setName($this->getDataString($companyForm->getData(), 'name'));
                        $address = new Address();
                        $address->setLine1($this->getDataString($companyForm->getData(), 'address1'));
                        $address->setLine2($this->getDataString($companyForm->getData(), 'address2'));
                        $address->setLine3($this->getDataString($companyForm->getData(), 'address3'));
                        $address->setCity($this->getDataString($companyForm->getData(), 'city'));
                        $address->setPostcode($this->getDataString($companyForm->getData(), 'postcode'));
                        $company->setAddress($address);
                        $dm->persist($company);
                        $dm->flush();
                        $this->addFlash('success', sprintf(
                            'Added company'
                        ));

                        return new RedirectResponse($this->generateUrl('admin_company'));
                    } else {
                        throw new \InvalidArgumentException(sprintf(
                            'Unable to add company. %s',
                            (string) $companyForm->getErrors()
                        ));
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return [
            'companies' => $companies,
            'belongForm' => $belongForm->createView(),
            'companyForm' => $companyForm->createView(),
        ];
    }

    /**
     * @Route("/policy-breakdown", name="admin_policy_breakdown")
     * @Template
     */
    public function breakdownAction()
    {
        $policyService = $this->get('app.policy');
        return [
            'data' => $policyService->getBreakdownData(),
        ];
    }

    /**
     * @Route("/policy-breakdown/print", name="admin_policy_breakdown_print")
     * @Template
     */
    public function breakdownPrintAction()
    {
        $policyService = $this->get('app.policy');
        $now = new \DateTime();

        return new Response(
            $policyService->getBreakdownPdf(),
            200,
            array(
                'Content-Type'          => 'application/pdf',
                'Content-Disposition'   =>
                    sprintf('attachment; filename="so-sure-policy-breakdown-%s.pdf"', $now->format('Y-m-d'))
            )
        );
    }

    /**
     * @Route("/kpi", name="admin_kpi")
     * @Route("/kpi/{now}", name="admin_kpi_date")
     * @Template
     */
    public function kpiAction($now = null)
    {
        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(PhonePolicy::class);
        $statsRepo = $dm->getRepository(Stats::class);
        $numWeeks = 4;
        $weeks = [];

        if (!$now) {
            $now = new \DateTime();
        } else {
            $now = new \DateTime($now);
        }
        $date = new \DateTime('2016-09-12 00:00');
        // TODO: Be smarter with start date, but this at least drops number of queries down significantly
        while ($date < $now) {
            $end = clone $date;
            $end->add(new \DateInterval('P6D'));
            $end = $this->endOfDay($end);
            $date = $date->add(new \DateInterval('P7D'));
        }
        $date = $date->sub(new \DateInterval(sprintf('P%dD', $numWeeks * 7)));

        $count = 1;
        while ($date < $now) {
            $end = clone $date;
            $end->add(new \DateInterval('P6D'));
            $end = $this->endOfDay($end);
            $week = [
                'start_date' => clone $date,
                'end_date' => $end,
                'end_date_disp' => (clone $end)->sub(new \DateInterval('PT1S')),
                'count' => $count,
            ];
            $start = $this->startOfDay(clone $date);
            $date = $date->add(new \DateInterval('P7D'));
            $count++;
            $reporting = $this->get('app.reporting');
            $week['period'] = $reporting->report($start, $end, true);
            $totalStart = clone $end;
            $totalStart = $totalStart->sub(new \DateInterval('P1Y'));
            $week['total'] = $reporting->report(new \DateTime(SoSure::POLICY_START), $end, true);
            $week['sumPolicies'] = $reporting->sumTotalPoliciesPerWeek($end);

            $approved = $week['total']['approvedClaims'][Claim::STATUS_APPROVED] +
                $week['total']['approvedClaims'][Claim::STATUS_SETTLED];
            if ($week['sumPolicies'] != 0) {
                $week['freq-claims'] = 52 * $approved / $week['sumPolicies'];
            } else {
                $week['freq-claims'] = 'N/A';
            }
            $week['total-policies'] = $policyRepo->countAllActivePolicies($date);
            $stats = $statsRepo->getStatsByRange($start, $date);
            foreach ($stats as $stat) {
                if (!isset($week[$stat->getName()])) {
                    $week[$stat->getName()] = 0;
                }
                if (!$stat->isAbsolute()) {
                    $week[$stat->getName()] += $stat->getValue();
                } else {
                    $week[$stat->getName()] = $stat->getValue();
                }
            }
            foreach (Stats::$allStats as $stat) {
                if (!isset($week[$stat])) {
                    $week[$stat] = '-';
                }
            };

            $weeks[] = $week;
        }

        $adjustedWeeks = array_slice($weeks, 0 - $numWeeks);
        $reversedAdjustedWeeks = array_reverse($adjustedWeeks);
        $prevPageDate = $reversedAdjustedWeeks[0]['end_date'];
        $prevPageDate->add(new \DateInterval('P'.($numWeeks).'W'));

        return [
            'weeks' => $reversedAdjustedWeeks,
            'next_page' => $this->generateUrl('admin_kpi_date', [
                'now' => $adjustedWeeks[0]['start_date']->format('y-m-d')
            ]),
            'previous_page' => $this->generateUrl('admin_kpi_date', [
                'now' => $prevPageDate->format('y-m-d')
            ]),
            'now' => $now,
        ];
    }

    /**
     * @Route("/phone/{id}/higlight", name="admin_phone_highlight")
     * @Method({"POST"})
     */
    public function phoneHighlightAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            if ($phone->isHighlight()) {
                $phone->setHighlight(false);
                $message = 'Phone is no longer highlighted';
            } else {
                $phone->setHighlight(true);
                $message = 'Phone is now highlighted';
            }
            $dm->flush();
            $this->addFlash(
                'notice',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/{id}/newhighdemand", name="admin_phone_newhighdemand")
     * @Method({"POST"})
     */
    public function phoneNewHighDemandAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            if ($phone->isNewHighDemand()) {
                $phone->setNewHighDemand(false);
                $message = 'Phone is no longer set to new high demand';
            } else {
                $phone->setNewHighDemand(true);
                $message = 'Phone is now set to new high demand';
            }
            $dm->flush();
            $this->addFlash(
                'notice',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/checkpremium/{price}", name="admin_phone_check_premium_price")
     * @Method({"POST"})
     */
    public function phoneCheckPremium(Request $request, $price)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('access_token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $phone = new Phone();
        $phone->setInitialPrice($price);
        try {
            $response['calculatedPremium'] = $phone->getSalvaBinderMonthlyPremium();
        } catch (\Exception $e) {
            $this->get('logger')->error(
                sprintf("Error in call to getSalvaBinderMonthlyPremium."),
                ['exception' => $e]
            );
            $response['calculatedPremium'] = 'no data';
        }
        return new Response(json_encode($response));
    }

    /**
     * @Route("/payments", name="admin_payments")
     * @Route("/payments/{year}/{month}", name="admin_payments_date")
     * @Template
     */
    public function paymentsAction($year = null, $month = null)
    {
        $now = new \DateTime();
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));
        $reporting = $this->get('app.reporting');
        $data = $reporting->payments($date);

        return [
            'data' => $data,
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * @Route("/picsure", name="admin_picsure")
     * @Route("/picsure/{id}/approve", name="admin_picsure_approve")
     * @Route("/picsure/{id}/reject", name="admin_picsure_reject")
     * @Route("/picsure/{id}/invalid", name="admin_picsure_invalid")
     * @Template
     */
    public function picsureAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        $policy = null;
        if ($id) {
            $policy = $repo->find($id);
        }
        $picSureSearchForm = $this->get('form.factory')
            ->createNamedBuilder('search_form', PicSureSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $picSureSearchForm->handleRequest($request);

        if ($request->get('_route') == "admin_picsure_approve") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED);
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'pic-sure is successfully validated',
                $policy->getUser()->getEmail(),
                'AppBundle:Email:picsure/accepted.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:picsure/accepted.txt.twig',
                ['policy' => $policy]
            );

            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Your pic-sure image has been approved and your phone is now validated.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            $this->get('event_dispatcher')->dispatch(
                PicsureEvent::EVENT_APPROVED,
                new PicsureEvent($picsureFiles[0])
            );

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        } elseif ($request->get('_route') == "admin_picsure_reject") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED);
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'pic-sure failed to validate your phone',
                $policy->getUser()->getEmail(),
                'AppBundle:Email:picsure/rejected.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:picsure/rejected.txt.twig',
                ['policy' => $policy]
            );
            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Your pic-sure image has been rejected. If you phone was damaged prior to your policy purchase, then it is crimial fraud to claim on our policy. Please contact us if you have purchased this policy by mistake.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            $this->get('event_dispatcher')->dispatch(
                PicsureEvent::EVENT_REJECTED,
                new PicsureEvent($picsureFiles[0])
            );

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        } elseif ($request->get('_route') == "admin_picsure_invalid") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID);
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'Sorry, we need another pic-sure',
                $policy->getUser()->getEmail(),
                'AppBundle:Email:picsure/invalid.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:picsure/invalid.txt.twig',
                ['policy' => $policy]
            );
            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Sorry, but we were not able to see your phone clearly enough to determine if the phone is undamaged. Please try uploading your pic-sure photo again making sure that the phone is clearly visible in the photo.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            $this->get('event_dispatcher')->dispatch(
                PicsureEvent::EVENT_INVALID,
                new PicsureEvent($picsureFiles[0])
            );

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        }

        $status = $request->get('status');
        $data = $picSureSearchForm->get('status')->getData();
        $qb = $repo->createQueryBuilder()
            ->field('picSureStatus')->equals($data)
            ->sort('picSureApprovedDate', 'desc')
            ->sort('created', 'desc');
        $pager = $this->pager($request, $qb);
        return [
            'policies' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'status' => $data,
            'picsure_search_form' => $picSureSearchForm->createView(),
        ];
    }

    /**
     * @Route("/picsure/image/{file}", name="admin_picsure_image", requirements={"file"=".*"})
     * @Template()
     */
    public function picsureImageAction($file)
    {
        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3policy_fs');
        $environment = $this->getParameter('kernel.environment');
        $file = str_replace(sprintf('%s/', $environment), '', $file);

        if (!$filesystem->has($file)) {
            throw $this->createNotFoundException(sprintf('URL not found %s', $file));
        }

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
}
