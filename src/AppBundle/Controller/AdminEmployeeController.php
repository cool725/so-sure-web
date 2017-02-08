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
use AppBundle\Document\DateTrait;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\Claim;
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
use AppBundle\Document\Invoice;
use AppBundle\Document\Connection;
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
use AppBundle\Document\Form\Cancel;
use AppBundle\Form\Type\CancelPolicyType;
use AppBundle\Form\Type\ClaimType;
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
use AppBundle\Form\Type\PendingPolicyCancellationType;
use AppBundle\Exception\RedirectException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
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
        $phones = $repo->createQueryBuilder();
        $phones = $phones->field('make')->notEqual('ALL');

        $form = $this->createForm(PhoneSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $data = $form->get('os')->getData();
        $phones = $phones->field('os')->in($data);
        $data = filter_var($form->get('active')->getData(), FILTER_VALIDATE_BOOLEAN);
        $phones = $phones->field('active')->equals($data);
        $rules = $form->get('rules')->getData();
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
            'form' => $form->createView(),
            'pager' => $pager
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
        try {
            $data = $this->searchUsers($request);
        } catch (RedirectException $e) {
            return new RedirectResponse($e->getMessage());
        }
        return array_merge($data, [
            'policy_route' => 'admin_policy'
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
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Policy %s is scheduled to be cancelled', $policy->getPolicyNumber())
                    );

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
                        $imeiService->checkSerial($policy->getPhone(), $serialNumber, $policy->getUser());
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

                        return new RedirectResponse($this->generateUrl('admin_user', ['id' => $id]));
                    }

                    // TODO: run checkmend
                    // TODO: Ensure address is present
                    $policyService = $this->get('app.policy');
                    $newPolicy = $policyService->init(
                        $user,
                        $policyData->getPhone(),
                        $policyData->getImei(),
                        $policyData->getSerialNumber()
                    );

                    $dm->persist($newPolicy);
                    $dm->flush();

                    $this->addFlash(
                        'success',
                        'Partial policy was added'
                    );

                    return new RedirectResponse($this->generateUrl('admin_user', ['id' => $id]));
                }
            }
        }

        return [
            'user' => $user,
            'reset_form' => $resetForm->createView(),
            'policy_form' => $policyForm->createView(),
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
        $pager = $this->pager($request, $qb);
        $phoneRepo = $dm->getRepository(Phone::class);
        $phones = $phoneRepo->findActive()->getQuery()->execute();

        return [
            'claims' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'phones' => $phones,
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

        $dm = $this->getManager();
        $policyRepo = $dm->getRepository(PhonePolicy::class);
        $connectionRepo = $dm->getRepository(Connection::class);
        $invitationRepo = $dm->getRepository(Invitation::class);
        $scheduledPaymentRepo = $dm->getRepository(ScheduledPayment::class);
        $claimsRepo = $dm->getRepository(Claim::class);
        $claims = $claimsRepo->findFNOLClaims($start, $end);
        $claimsTotals = Claim::sumClaims($claims);

        $invalidPolicies = $policyRepo->getActiveInvalidPolicies();
        $invalidPoliciesIds = [];
        foreach ($invalidPolicies as $invalidPolicy) {
            $invalidPoliciesIds[] = new \MongoId($invalidPolicy->getId());
        }
        $scheduledPaymentRepo->setExcludedPolicyIds($invalidPoliciesIds);
        $data['scheduledPayments'] = $scheduledPaymentRepo->getMonthlyValues();

        $excludedPolicyIds = [];
        $excludedPolicies = [];
        foreach ($this->getParameter('report_excluded_policy_ids') as $excludedPolicyId) {
            $excludedPolicyIds[] = new \MongoId($excludedPolicyId);
            $policy = $policyRepo->find($excludedPolicyId);
            if ($policy) {
                $excludedPolicies[] = $policy;
            }
        }

        $policyRepo->setExcludedPolicyIds($excludedPolicyIds);
        $invitationRepo->setExcludedPolicyIds($excludedPolicyIds);
        // Doesn't make sense to exclude as will skew all figures
        // $connectionRepo->setExcludedPolicyIds($excludedPolicyIds);

        $pot = $policyRepo->getPotValues()[0];
        $data['totalPot'] = $pot['potValue'];
        $data['totalPromoPot'] = $pot['promoPotValue'];

        $newDirectPolicies = $policyRepo->findAllNewPolicies(null, $start, $end);
        $data['newDirectPolicies'] = $newDirectPolicies->count();
        $data['newDirectPoliciesPremium'] = Policy::sumYearlyPremiumPrice($newDirectPolicies);
        if ($data['newDirectPolicies'] != 0) {
            $data['newDirectPoliciesAvgPremium'] = $this->toTwoDp(
                $data['newDirectPoliciesPremium'] / $data['newDirectPolicies']
            );
        }

        $totalDirectPolicies = $policyRepo->findAllNewPolicies();
        $data['totalDirectPolicies'] = $totalDirectPolicies->count();
        $data['totalDirectPoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalDirectPolicies);
        if ($data['totalDirectPolicies'] != 0) {
            $data['totalDirectPoliciesAvgPremium'] = $this->toTwoDp(
                $data['totalDirectPoliciesPremium'] / $data['totalDirectPolicies']
            );
        }

        $newInvitationPolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_INVITATION, $start, $end);
        $data['newInvitationPolicies'] = $newInvitationPolicies->count();
        $data['newInvitationPoliciesPremium'] = Policy::sumYearlyPremiumPrice($newInvitationPolicies);
        if ($data['newInvitationPolicies'] != 0) {
            $data['newInvitationPoliciesAvgPremium'] = $this->toTwoDp(
                $data['newInvitationPoliciesPremium'] / $data['newInvitationPolicies']
            );
        }

        $totalInvitationPolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_INVITATION);
        $data['totalInvitationPolicies'] = $totalInvitationPolicies->count();
        $data['totalInvitationPoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalInvitationPolicies);
        if ($data['totalInvitationPolicies'] != 0) {
            $data['totalInvitationPoliciesAvgPremium'] = $this->toTwoDp(
                $data['totalInvitationPoliciesPremium'] / $data['totalInvitationPolicies']
            );
        }

        $newSCodePolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_SCODE, $start, $end);
        $data['newSCodePolicies'] = $newSCodePolicies->count();
        $data['newSCodePoliciesPremium'] = Policy::sumYearlyPremiumPrice($newSCodePolicies);
        if ($data['newSCodePolicies'] != 0) {
            $data['newSCodePoliciesAvgPremium'] = $this->toTwoDp(
                $data['newSCodePoliciesPremium'] / $data['newSCodePolicies']
            );
        }

        $totalSCodePolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_SCODE);
        $data['totalSCodePolicies'] = $totalSCodePolicies->count();
        $data['totalSCodePoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalInvitationPolicies);
        if ($data['totalSCodePolicies'] != 0) {
            $data['totalSCodePoliciesAvgPremium'] = $this->toTwoDp(
                $data['totalSCodePoliciesPremium'] / $data['totalSCodePolicies']
            );
        }

        $endingDataset = [
            'Ending' => null,
            'Unpaid' => Policy::CANCELLED_UNPAID,
            'ActualFraud' => Policy::CANCELLED_ACTUAL_FRAUD,
            'SuspectedFraud' => Policy::CANCELLED_SUSPECTED_FRAUD,
            'UserRequested' => Policy::CANCELLED_USER_REQUESTED,
            'Cooloff' => Policy::CANCELLED_COOLOFF,
            'BadRisk' => Policy::CANCELLED_BADRISK,
            'Dispossession' => Policy::CANCELLED_DISPOSSESSION,
            'Wreckage' => Policy::CANCELLED_WRECKAGE,
        ];
        foreach ($endingDataset as $key => $cancellationReason) {
            $data[sprintf('total%sPolicies', $key)] = $policyRepo->findAllEndingPolicies($cancellationReason);
            $data[sprintf('ending%sPolicies', $key)] = $policyRepo->findAllEndingPolicies(
                $cancellationReason,
                $start,
                $end
            );
        }

        $data['newPolicies'] = $policyRepo->countAllNewPolicies($end, $start);
        $data['newPoliciesPremium'] = $data['newDirectPoliciesPremium'] + $data['newInvitationPoliciesPremium'] +
            $data['newSCodePoliciesPremium'];
        if ($data['newPolicies'] != 0) {
            $data['newPoliciesAvgPremium'] = $this->toTwoDp($data['newPoliciesPremium'] / $data['newPolicies']);
        }

        $data['totalActivePolicies'] = $policyRepo->countAllActivePolicies();
        $data['totalPolicies'] = $policyRepo->countAllNewPolicies();
        $data['totalPoliciesPremium'] = $data['totalDirectPoliciesPremium'] + $data['totalInvitationPoliciesPremium'] +
            $data['totalSCodePoliciesPremium'];
        if ($data['totalPolicies'] != 0) {
            $data['totalPoliciesAvgPremium'] = $this->toTwoDp($data['totalPoliciesPremium'] / $data['totalPolicies']);
        }

        $data['totalActiveMonthlyPolicies'] = $policyRepo->countAllActivePoliciesByInstallments(12);
        $data['totalActiveYearlyPolicies'] = $policyRepo->countAllActivePoliciesByInstallments(1);

        // For reporting, connection numbers should be seen as a 2 way connection
        $newConnections = $connectionRepo->count($start, $end) / 2;
        $totalConnections = $connectionRepo->count() / 2;

        $data['newInvitations'] = $invitationRepo->count(null, $start, $end);
        $data['totalInvitations'] = $invitationRepo->count();

        $data['newDirectInvitations'] = $invitationRepo->count($newDirectPolicies, $start, $end);
        $data['totalDirectInvitations'] = $invitationRepo->count($totalDirectPolicies);

        $data['newInvitationInvitations'] = $invitationRepo->count($newInvitationPolicies, $start, $end);
        $data['totalInvitationInvitations'] = $invitationRepo->count($totalInvitationPolicies);

        $data['newSCodeInvitations'] = $invitationRepo->count($newSCodePolicies, $start, $end);
        $data['totalSCodeInvitations'] = $invitationRepo->count($totalSCodePolicies);

        $data['newAvgInvitations'] = $data['newPolicies'] > 0 ?
            $data['newInvitations'] / $data['newPolicies'] :
            'n/a';
        $data['totalAvgInvitations'] = $data['totalPolicies'] > 0 ?
            $data['totalInvitations'] / $data['totalPolicies'] :
            'n/a';

        $data['newAvgDirectInvitations'] = $data['newDirectPolicies'] > 0 ?
            $data['newDirectInvitations'] / $data['newDirectPolicies'] :
            'n/a';
        $data['totalAvgDirectInvitations'] = $data['totalDirectPolicies'] > 0 ?
            $data['totalDirectInvitations'] / $data['totalDirectPolicies'] :
            'n/a';

        $data['newAvgInvitationInvitations'] = $data['newInvitationPolicies'] > 0 ?
            $data['newInvitationInvitations'] / $data['newInvitationPolicies'] :
            'n/a';
        $data['totalAvgInvitationInvitations'] = $data['totalInvitationPolicies'] > 0 ?
            $data['totalInvitationInvitations'] / $data['totalInvitationPolicies'] :
            'n/a';

        $data['newAvgSCodeInvitations'] = $data['newSCodePolicies'] > 0 ?
            $data['newSCodeInvitations'] / $data['newSCodePolicies'] :
            'n/a';
        $data['totalAvgSCodeInvitations'] = $data['totalSCodePolicies'] > 0 ?
            $data['totalSCodeInvitations'] / $data['totalSCodePolicies'] :
            'n/a';

        $data['policyConnections']['total'] = $data['totalPolicies'] + count($excludedPolicyIds);
        $data['policyConnections'][0] = $data['policyConnections']['total'];
        $data['policyConnections']['10+'] = 0;
        for ($i = 1; $i <= 30; $i++) {
            $data['policyConnections'][$i] = $connectionRepo->countByConnection($i, $start, $end);
            $data['policyConnections'][0] -= $data['policyConnections'][$i];
            if ($i >= 10) {
                $data['policyConnections']['10+'] += $data['policyConnections'][$i];
            }
        }
        $data['totalAvgConnections'] = $totalConnections / $data['policyConnections']['total'];

        $weighted = 0;
        for ($i = 0; $i < 10; $i++) {
            $weighted += $i * $data['policyConnections'][$i];
        }
        $data['totalWeightedAvgConnections'] = $weighted / $data['policyConnections']['total'];
        $data['totalAvgHoursToConnect'] = $connectionRepo->avgHoursToConnect();

        return [
            'start' => $start,
            'end' => $end,
            'data' => $data,
            'total_connections' => $totalConnections,
            'new_connections' => $newConnections,
            'excluded_policies' => $excludedPolicies,
            'claims' => $claimsTotals,
        ];
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
        $repo = $dm->getRepository(Connection::class);
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
     * @Template
     */
    public function kpiAction()
    {
        $dm = $this->getManager();
        $statsRepo = $dm->getRepository(Stats::class);
        
        $date = new \DateTime('2016-09-12');
        $now = new \DateTime('+7 days');
        $count = 1;
        while ($date < $now) {
            $week = [
                'date' => clone $date,
                'count' => $count,
            ];
            $start = clone $date;
            $date = $date->add(new \DateInterval('P7D'));
            $count++;

            $stats = $statsRepo->getStatsByRange($start, $date);
            foreach ($stats as $stat) {
                if (!isset($week[$stat->getName()])) {
                    $week[$stat->getName()] = 0;
                }
                $week[$stat->getName()] += $stat->getValue();
            }
            if (!isset($week[Stats::INSTALL_GOOGLE])) {
                $week[Stats::INSTALL_GOOGLE] = 0;
            }
            if (!isset($week[Stats::INSTALL_APPLE])) {
                $week[Stats::INSTALL_APPLE] = 0;
            }

            $weeks[] = $week;
        }

        return [
            'weeks' => $weeks,
        ];
    }
}
