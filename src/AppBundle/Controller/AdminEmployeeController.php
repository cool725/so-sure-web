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
use AppBundle\Classes\SoSure;
use AppBundle\Classes\Salva;
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
use AppBundle\Document\BacsPayment;
use AppBundle\Document\Address;
use AppBundle\Document\Company;
use AppBundle\Document\Charge;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\JudoPayment;
use AppBundle\Document\SoSurePayment;
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
use AppBundle\Document\Form\BillingDay;
use AppBundle\Form\Type\BillingDayType;
use AppBundle\Form\Type\CancelPolicyType;
use AppBundle\Form\Type\BacsType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\ClaimSearchType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\ImeiType;
use AppBundle\Form\Type\NoteType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Form\Type\SmsOptOutType;
use AppBundle\Form\Type\PartialPolicyType;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PhoneSearchType;
use AppBundle\Form\Type\JudoFileType;
use AppBundle\Form\Type\FacebookType;
use AppBundle\Form\Type\BarclaysFileType;
use AppBundle\Form\Type\LloydsFileType;
use AppBundle\Form\Type\ImeiUploadFileType;
use AppBundle\Form\Type\ScreenUploadFileType;
use AppBundle\Form\Type\PendingPolicyCancellationType;
use AppBundle\Form\Type\UserDetailType;
use AppBundle\Form\Type\UserEmailType;
use AppBundle\Form\Type\UserPermissionType;
use AppBundle\Exception\RedirectException;
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
        $imeiForm = $this->get('form.factory')
            ->createNamedBuilder('imei_form', ImeiType::class, $policy)
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
        $bacsPayment = new BacsPayment();
        $bacsPayment->setDate(new \DateTime());
        $bacsPayment->setAmount($policy->getPremium()->getYearlyPremiumPrice());

        $bacsForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_form', BacsType::class, $bacsPayment)
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
        $makeModelForm = $this->get('form.factory')
            ->createNamedBuilder('makemodel_form')
            ->add('serial', TextType::class)
            ->add('check', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cancel_form')) {
                $cancelForm->handleRequest($request);
                if ($cancelForm->isValid()) {
                    if ($policy->canCancel($cancel->getCancellationReason())) {
                        $policyService->cancel(
                            $policy,
                            $cancel->getCancellationReason(),
                            $cancel->getSkipNetworkEmail()
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
                    $policyService->generatePolicySchedule($policy);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Re-generated Policy Schedule'
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
                        $makeModelForm->getData()['serial'],
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
            'fraud' => $checks,
            'policy_route' => 'admin_policy',
            'policy_history' => $this->getSalvaPhonePolicyHistory($policy->getId()),
            'user_history' => $this->getUserHistory($policy->getUser()->getId()),
            'suggested_cancellation_date' => $now->add(new \DateInterval('P30D')),
        ];
    }

    /**
     * @Route("/user/{id}", name="admin_user")
     * @Template
     */
    public function adminUserAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $user = $repo->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User not found');
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
        $makeModelForm = $this->get('form.factory')
            ->createNamedBuilder('makemodel_form')
            ->add('serial', TextType::class)
            ->add('check', SubmitType::class)
            ->getForm();

        $policyData = new SalvaPhonePolicy();
        $policyForm = $this->get('form.factory')
            ->createNamedBuilder('policy_form', PartialPolicyType::class, $policyData)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('reset_form')) {
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

                    // TODO: Ensure address is present
                    $policyService = $this->get('app.policy');
                    $serialNumber = $policyData->getSerialNumber();
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

                    $this->addFlash(
                        'success',
                        'Partial policy was added'
                    );

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
            } elseif ($request->request->has('user_email_form')) {
                $userEmailForm->handleRequest($request);
                if ($userEmailForm->isValid()) {
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
            } elseif ($request->request->has('makemodel_form')) {
                $makeModelForm->handleRequest($request);
                if ($makeModelForm->isValid()) {
                    $imeiValidator = $this->get('app.imei');
                    $phone = new Phone();
                    $imeiValidator->checkSerial(
                        $phone,
                        $makeModelForm->getData()['serial'],
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
                }
            }
        }

        return [
            'user' => $user,
            'reset_form' => $resetForm->createView(),
            'policy_form' => $policyForm->createView(),
            'user_detail_form' => $userDetailForm->createView(),
            'user_email_form' => $userEmailForm->createView(),
            'user_permission_form' => $userPermissionForm->createView(),
            'makemodel_form' => $makeModelForm->createView(),
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
        $qb = $repo->createQueryBuilder()->sort('notificationDate', 'desc');

        $form = $this->createForm(ClaimSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $data = $form->get('status')->getData();
        $qb = $qb->field('status')->in($data);

        $pager = $this->pager($request, $qb);
        $phoneRepo = $dm->getRepository(Phone::class);
        $phones = $phoneRepo->findActive()->getQuery()->execute();

        return [
            'claims' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'phones' => $phones,
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

        return $this->get('app.reporting')->report($start, $end);
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
                ];
            }
            $data[$connection->getSourcePolicy()->getId()]['connections'][] = $connection->getDate() ?
                $connection->getDate()->format('d M Y') :
                '';
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
                            (string) $emailForm->getErrors()
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
                            (string) $emailForm->getErrors()
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
                                'Unable to add user to company. Company is missing',
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
                            (string) $emailForm->getErrors()
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
                            (string) $emailForm->getErrors()
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

        if (!$now) {
            $now = new \DateTime();
        } else {
            $now = new \DateTime($now);
        }
        $date = new \DateTime('2016-09-12');
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
                'count' => $count,
            ];
            $start = $this->startOfDay(clone $date);
            $date = $date->add(new \DateInterval('P7D'));
            $count++;
            $reporting = $this->get('app.reporting');
            $week['period'] = $reporting->report($start, $end, true);
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
                $week[$stat->getName()] += $stat->getValue();
            }
            foreach ([
                Stats::INSTALL_GOOGLE,
                Stats::INSTALL_APPLE,
                Stats::MIXPANEL_TOTAL_SITE_VISITORS,
                Stats::MIXPANEL_QUOTES_UK,
                Stats::MIXPANEL_RECEIVE_PERSONAL_DETAILS,
                Stats::MIXPANEL_CPC_QUOTES_UK,
                Stats::MIXPANEL_CPC_MANUFACTURER_UK,
            ] as $stat) {
                if (!isset($week[$stat])) {
                    $week[$stat] = '-';
                }
            };

            $weeks[] = $week;
        }

        return [
            'weeks' =>  array_reverse(array_slice($weeks, 0 - $numWeeks)),
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
}
