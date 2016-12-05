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
use AppBundle\Document\Connection;
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
use AppBundle\Form\Type\YearMonthType;
use AppBundle\Form\Type\JudoFileType;
use AppBundle\Form\Type\FacebookType;
use AppBundle\Form\Type\BarclaysFileType;
use AppBundle\Form\Type\LloydsFileType;
use AppBundle\Form\Type\PendingPolicyCancellationType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;

/**
 * @Route("/admin")
 */
class AdminController extends BaseController
{
    use DateTrait;
    use CurrencyTrait;

    /**
     * @Route("/", name="admin_home")
     * @Template
     */
    public function indexAction()
    {
        return [];
    }

    /**
     * @Route("/phones", name="admin_phones")
     * @Template
     */
    public function phonesAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
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
            'token' => $csrf->generateCsrfToken('default'),
            'form' => $form->createView(),
            'pager' => $pager
        ];
    }

    /**
     * @Route("/phone", name="admin_phone_add")
     * @Method({"POST"})
     */
    public function phoneAddAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $devices = explode("|", $request->get('devices'));
        $devices = array_filter(array_map('trim', $devices));
        $phone = new Phone();
        $phone->setMake($request->get('make'));
        $phone->setModel($request->get('model'));
        $phone->setDevices($devices);
        $phone->setMemory($request->get('memory'));
        $phone->getCurrentPhonePrice()->setGwp($request->get('gwp'));
        $dm->persist($phone);
        $dm->flush();
        $this->addFlash(
            'notice',
            'Your changes were saved!'
        );

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }
    
    /**
     * @Route("/phone/{id}/price", name="admin_phone_price")
     * @Method({"POST"})
     */
    public function phonePriceAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            $gwp = $request->get('gwp');
            $now = new \DateTime();
            $from = new \DateTime($request->get('from'), new \DateTimeZone(SoSure::TIMEZONE));
            if ($from < $now) {
                $this->addFlash('error', sprintf(
                    'New Price From Date must be in the future'
                ));

                return new RedirectResponse($this->generateUrl('admin_phones'));
            }

            $to = null;
            if ($request->get('to')) {
                $to = new \DateTime($request->get('to'), new \DateTimeZone(SoSure::TIMEZONE));
                $now = new \DateTime();
                if ($to < $now) {
                    $this->addFlash('error', sprintf(
                        'New Price To Date must be in the future'
                    ));

                    return new RedirectResponse($this->generateUrl('admin_phones'));
                }
            }

            if ($gwp < $phone->getSalvaMiniumumBinderMonthlyPremium()) {
                $this->addFlash('error', sprintf(
                    '£%.2f is less than allowed min binder £%.2f',
                    $gwp,
                    $phone->getSalvaMiniumumBinderMonthlyPremium()
                ));

                return new RedirectResponse($this->generateUrl('admin_phones'));
            }
            if ($to && $to < $from) {
                $this->addFlash('error', sprintf(
                    '%s must be after %s',
                    $from->format(\DateTime::ATOM),
                    $to->format(\DateTime::ATOM)
                ));

                return new RedirectResponse($this->generateUrl('admin_phones'));
            }

            if (!$phone->getCurrentPhonePrice()->getValidTo()) {
                if ($phone->getCurrentPhonePrice()->getValidFrom() > $from) {
                    $this->addFlash('error', sprintf(
                        '%s must be after current pricing start date %s',
                        $from->format(\DateTime::ATOM),
                        $phone->getCurrentPhonePrice()->getValidFrom()->format(\DateTime::ATOM)
                    ));

                    return new RedirectResponse($this->generateUrl('admin_phones'));
                }
                $phone->getCurrentPhonePrice()->setValidTo($from);
            }
            $price = new PhonePrice();
            $price->setGwp($request->get('gwp'));
            $price->setValidFrom($from);
            if ($request->get('to')) {
                $price->setValidTo($to);
            }
            $phone->addPhonePrice($price);

            $dm->flush();
            $this->addFlash(
                'notice',
                'Your changes were saved!'
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/{id}/active", name="admin_phone_active")
     * @Method({"POST"})
     */
    public function phoneActiveAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            if ($phone->getActive()) {
                $phone->setActive(false);
                $message = 'Phone is no longer active';
            } else {
                $phone->setActive(true);
                $message = 'Phone is now active';
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
     * @Route("/phone/{id}", name="admin_phone_delete")
     * @Method({"DELETE"})
     */
    public function phoneDeleteAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            $dm->remove($phone);
            $dm->flush();
            $this->addFlash(
                'notice',
                'Phone deleted!'
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/users", name="admin_users")
     * @Template("AppBundle::Claims/claimsUsers.html.twig")
     */
    public function adminUsersAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);

        $users = $repo->createQueryBuilder();
        $form = $this->createForm(UserSearchType::class, null, ['method' => 'GET']);
        $form->handleRequest($request);
        $sosure = $form->get('sosure')->getData();
        if ($sosure) {
            $imeiService = $this->get('app.imei');
            if ($imeiService->isImei($sosure)) {
                return new RedirectResponse($this->generateUrl('admin_users', ['imei' => $sosure]));
            } else {
                return new RedirectResponse($this->generateUrl('admin_users', ['facebookId' => $sosure]));
            }
        }
        $includeInvalidPolicies = $form->get('invalid')->getData();

        $this->formToMongoSearch($form, $users, 'email', 'email');
        $this->formToMongoSearch($form, $users, 'lastname', 'lastName');
        $this->formToMongoSearch($form, $users, 'mobile', 'mobileNumber');
        $this->formToMongoSearch($form, $users, 'postcode', 'billingAddress.postcode');
        $this->formToMongoSearch($form, $users, 'facebookId', 'facebookId');

        $policyRepo = $dm->getRepository(Policy::class);
        $policiesQb = $policyRepo->createQueryBuilder();
        if ($policies = $this->formToMongoSearch($form, $policiesQb, 'policy', 'policyNumber', true)) {
            $userIds = [];
            foreach ($policies as $policy) {
                $userIds[] = $policy->getUser()->getId();
            }
            $users->field('id')->in($userIds);
        }
        $policiesQb = $policyRepo->createQueryBuilder();
        if ($policies = $this->formToMongoSearch($form, $policiesQb, 'status', 'status', true)) {
            $userIds = [];
            foreach ($policies as $policy) {
                $userIds[] = $policy->getUser()->getId();
            }
            $users->field('id')->in($userIds);
        }
        if ($policies = $this->formToMongoSearch($form, $policiesQb, 'imei', 'imei', true)) {
            $userIds = [];
            foreach ($policies as $policy) {
                $userIds[] = $policy->getUser()->getId();
            }
            $users->field('id')->in($userIds);
        }
        $pager = $this->pager($request, $users);

        return [
            'users' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'pager' => $pager,
            'form' => $form->createView(),
            'policy_route' => 'admin_policy',
            'include_invalid_policies' => $includeInvalidPolicies,
        ];
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
     * @Route("/admin-users", name="admin_admin_users")
     * @Template
     */
    public function adminAdminUsersAction()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);

        $users = $repo->findUsersInRole('ROLE_ADMIN');

        return [
            'users' => $users,
        ];
    }

    /**
     * @Route("/admin-rate-limits", name="admin_rate_limits")
     * @Template
     */
    public function adminRateLimitsAction()
    {
        $rateLimit = $this->get('app.ratelimit');

        return [
            'rateLimits' => $rateLimit->show('all')
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

        $newDirectPolicies = $policyRepo->findAllActivePolicies(null, $start, $end);
        $data['newDirectPolicies'] = $newDirectPolicies->count();
        $data['newDirectPoliciesPremium'] = Policy::sumYearlyPremiumPrice($newDirectPolicies);
        if ($data['newDirectPolicies'] != 0) {
            $data['newDirectPoliciesAvgPremium'] = $this->toTwoDp(
                $data['newDirectPoliciesPremium'] / $data['newDirectPolicies']
            );
        }

        $totalDirectPolicies = $policyRepo->findAllActivePolicies(null);
        $data['totalDirectPolicies'] = $totalDirectPolicies->count();
        $data['totalDirectPoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalDirectPolicies);
        if ($data['totalDirectPolicies'] != 0) {
            $data['totalDirectPoliciesAvgPremium'] = $this->toTwoDp(
                $data['totalDirectPoliciesPremium'] / $data['totalDirectPolicies']
            );
        }

        $newInvitationPolicies = $policyRepo->findAllActivePolicies(Lead::LEAD_SOURCE_INVITATION, $start, $end);
        $data['newInvitationPolicies'] = $newInvitationPolicies->count();
        $data['newInvitationPoliciesPremium'] = Policy::sumYearlyPremiumPrice($newInvitationPolicies);
        if ($data['newInvitationPolicies'] != 0) {
            $data['newInvitationPoliciesAvgPremium'] = $this->toTwoDp(
                $data['newInvitationPoliciesPremium'] / $data['newInvitationPolicies']
            );
        }

        $totalInvitationPolicies = $policyRepo->findAllActivePolicies(Lead::LEAD_SOURCE_INVITATION);
        $data['totalInvitationPolicies'] = $totalInvitationPolicies->count();
        $data['totalInvitationPoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalInvitationPolicies);
        if ($data['totalInvitationPolicies'] != 0) {
            $data['totalInvitationPoliciesAvgPremium'] = $this->toTwoDp(
                $data['totalInvitationPoliciesPremium'] / $data['totalInvitationPolicies']
            );
        }

        $newSCodePolicies = $policyRepo->findAllActivePolicies(Lead::LEAD_SOURCE_SCODE, $start, $end);
        $data['newSCodePolicies'] = $newSCodePolicies->count();
        $data['newSCodePoliciesPremium'] = Policy::sumYearlyPremiumPrice($newSCodePolicies);
        if ($data['newSCodePolicies'] != 0) {
            $data['newSCodePoliciesAvgPremium'] = $this->toTwoDp(
                $data['newSCodePoliciesPremium'] / $data['newSCodePolicies']
            );
        }

        $totalSCodePolicies = $policyRepo->findAllActivePolicies(Lead::LEAD_SOURCE_SCODE);
        $data['totalSCodePolicies'] = $totalSCodePolicies->count();
        $data['totalSCodePoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalInvitationPolicies);
        if ($data['totalSCodePolicies'] != 0) {
            $data['totalSCodePoliciesAvgPremium'] = $this->toTwoDp(
                $data['totalSCodePoliciesPremium'] / $data['totalSCodePolicies']
            );
        }

        $data['newPolicies'] = $policyRepo->countAllActivePolicies($end, $start);
        $data['newPoliciesPremium'] = $data['newDirectPoliciesPremium'] + $data['newInvitationPoliciesPremium'] +
            $data['newSCodePoliciesPremium'];
        if ($data['newPolicies'] != 0) {
            $data['newPoliciesAvgPremium'] = $this->toTwoDp($data['newPoliciesPremium'] / $data['newPolicies']);
        }

        $data['totalPolicies'] = $policyRepo->countAllActivePolicies();
        $data['totalPoliciesPremium'] = $data['totalDirectPoliciesPremium'] + $data['totalInvitationPoliciesPremium'] +
            $data['totalSCodePoliciesPremium'];
        if ($data['totalPolicies'] != 0) {
            $data['totalPoliciesAvgPremium'] = $this->toTwoDp($data['totalPoliciesPremium'] / $data['totalPolicies']);
        }

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

        return [
            'start' => $start,
            'end' => $end,
            'data' => $data,
            'total_connections' => $totalConnections,
            'new_connections' => $newConnections,
            'excluded_policies' => $excludedPolicies,
        ];
    }

    /**
     * @Route("/admin-users/{id}", name="admin_admin_user")
     * @Template
     */
    public function adminAdminUserAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);

        $user = $repo->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User not found');
        }
        $disableMFAForm = $this->get('form.factory')
            ->createNamedBuilder('disable_mfa_form')->add('disable', SubmitType::class)
            ->getForm();
        $enableMFAForm = $this->get('form.factory')
            ->createNamedBuilder('enable_mfa_form')->add('enable', SubmitType::class)
            ->getForm();
        $mfaImageUrl = $this->get("scheb_two_factor.security.google_authenticator")->getUrl($user);

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('disable_mfa_form')) {
                $disableMFAForm->handleRequest($request);
                if ($disableMFAForm->isValid()) {
                    $user->setGoogleAuthenticatorSecret(null);
                    $dm->flush();

                    return $this->redirectToRoute('admin_admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('enable_mfa_form')) {
                $enableMFAForm->handleRequest($request);
                if ($enableMFAForm->isValid()) {
                    $secret = $this->get("scheb_two_factor.security.google_authenticator")->generateSecret();
                    $user->setGoogleAuthenticatorSecret($secret);
                    $dm->flush();

                    return $this->redirectToRoute('admin_admin_user', ['id' => $id]);
                }
            }
        }

        return [
            'user' => $user,
            'disable_mfa_form' => $disableMFAForm->createView(),
            'enable_mfa_form' => $enableMFAForm->createView(),
            'mfa_image_url' => $mfaImageUrl
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

                    return $this->redirectToRoute('admin_users');
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
        $csrf = $this->get('form.csrf_provider');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $qb = $repo->createQueryBuilder();
        $pager = $this->pager($request, $qb);

        return [
            'claims' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'pager' => $pager,
        ];
    }

    /**
     * @Route("/accounts/print/{year}/{month}", name="admin_accounts_print")
     */
    public function adminAccountsPrintAction($year, $month)
    {
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $templating = $this->get('templating');
        $snappyPdf = $this->get('knp_snappy.pdf');
        $snappyPdf->setOption('orientation', 'Landscape');
        $snappyPdf->setOption('page-size', 'A4');
        $html = $templating->render('AppBundle:Pdf:adminAccounts.html.twig', [
            'year' => $year,
            'month' => $month,
            'paymentTotals' => $this->getAllPaymentTotals($date),
            'activePolicies' => $this->getActivePolicies($date),
        ]);

        return new Response(
            $snappyPdf->getOutputFromHtml($html),
            200,
            array(
                'Content-Type'          => 'application/pdf',
                'Content-Disposition'   => sprintf('attachment; filename="so-sure-%d-%d.pdf"', $year, $month)
            )
        );
    }

    private function getAllPaymentTotals(\DateTime $date)
    {
        $isProd = $this->getParameter('kernel.environment') == 'prod';
        $payments = $this->getPayments($date);

        return [
            'all' => Payment::sumPayments($payments, $isProd),
            'judo' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'sosure' => Payment::sumPayments($payments, $isProd, SoSurePayment::class),
        ];
    }

    private function getPayments(\DateTime $date)
    {
        $dm = $this->getManager();
        $paymentRepo = $dm->getRepository(Payment::class);
        $payments = $paymentRepo->getAllPaymentsForExport($date);

        return $payments;
    }

    private function getActivePolicies($date)
    {
        $dm = $this->getManager();
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);

        return $phonePolicyRepo->countAllActivePoliciesToEndOfMonth($date);
    }

    /**
     * @Route("/accounts", name="admin_accounts")
     * @Route("/accounts/{year}/{month}", name="admin_accounts_date")
     * @Template
     */
    public function adminAccountsAction(Request $request, $year = null, $month = null)
    {
        $now = new \DateTime();
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        $s3FileRepo = $dm->getRepository(S3File::class);
        $judoFile = new JudoFile();
        $judoForm = $this->get('form.factory')
            ->createNamedBuilder('judo', JudoFileType::class, $judoFile)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('judo')) {
                $judoForm->handleRequest($request);
                if ($judoForm->isSubmitted() && $judoForm->isValid()) {
                    $dm = $this->getManager();
                    $judoFile->setBucket('admin.so-sure.com');
                    $judoFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $judoService = $this->get('app.judopay');
                    $data = $judoService->processCsv($judoFile);

                    $dm->persist($judoFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_accounts_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('m'),
                    ]);
                }
            }
        }

        return [
            'judoForm' => $judoForm->createView(),
            'year' => $year,
            'month' => $month,
            'paymentTotals' => $this->getAllPaymentTotals($date),
            // TODO: query will eve
            'activePolicies' => $this->getActivePolicies($date),
            'files' => $s3FileRepo->getAllFiles($date),
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

        return [
            'year' => $year,
            'month' => $month,
            'end' => $end,
            'scheduledPayments' => $scheduledPayments,
        ];
    }

    /**
     * @Route("/banking", name="admin_banking")
     * @Route("/banking/{year}/{month}", name="admin_banking_date")
     * @Template
     */
    public function adminBankingAction(Request $request, $year = null, $month = null)
    {
        $now = new \DateTime();
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        $paymentRepo = $dm->getRepository(Payment::class);
        $barclaysFileRepo = $dm->getRepository(BarclaysFile::class);
        $lloydsFileRepo = $dm->getRepository(LloydsFile::class);

        $payments = $paymentRepo->getAllPaymentsForExport($date);
        $paymentTotals = Payment::sumPayments($payments, $this->getParameter('kernel.environment') == 'prod');
        $paymentDailys = Payment::dailyPayments($payments, $this->getParameter('kernel.environment') == 'prod');

        $lloydsFile = new LloydsFile();
        $lloydsForm = $this->get('form.factory')
            ->createNamedBuilder('lloyds', LloydsFileType::class, $lloydsFile)
            ->getForm();
        $barclaysFile = new BarclaysFile();
        $barclaysForm = $this->get('form.factory')
            ->createNamedBuilder('barclays', BarclaysFileType::class, $barclaysFile)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lloyds')) {
                $lloydsForm->handleRequest($request);
                if ($lloydsForm->isSubmitted() && $lloydsForm->isValid()) {
                    $dm = $this->getManager();
                    $lloydsFile->setBucket('admin.so-sure.com');
                    $lloydsFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $lloydsService = $this->get('app.lloyds');
                    $data = $lloydsService->processCsv($lloydsFile);

                    $dm->persist($lloydsFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('m'),
                    ]);
                }
            } elseif ($request->request->has('barclays')) {
                $barclaysForm->handleRequest($request);
                if ($barclaysForm->isSubmitted() && $barclaysForm->isValid()) {
                    $dm = $this->getManager();
                    $barclaysFile->setBucket('admin.so-sure.com');
                    $barclaysFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $barclaysService = $this->get('app.barclays');
                    $data = $barclaysService->processCsv($barclaysFile);

                    $dm->persist($barclaysFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('m'),
                    ]);
                }
            }
        }
        
        $barclaysFiles = $barclaysFileRepo->getBarclaysFiles($date);
        $dailyTransaction = BarclaysFile::combineDailyTransactions($barclaysFiles);
        $dailyBarclaysProcessing = BarclaysFile::combineDailyProcessing($barclaysFiles);
        $totalTransaction = 0;
        foreach ($dailyTransaction as $key => $value) {
            if (stripos($key, sprintf('%d%02d', $year, $month)) !== false) {
                $totalTransaction += $value;
            }
        }
        $totalBarclaysProcessing = 0;
        foreach ($dailyBarclaysProcessing as $key => $value) {
            if (stripos($key, sprintf('%d%02d', $year, $month)) !== false) {
                $totalBarclaysProcessing += $value;
            }
        }

        $lloydsFiles = $lloydsFileRepo->getLloydsFiles($date);
        $dailyReceived = LloydsFile::combineDailyReceived($lloydsFiles);
        $dailyLloydsProcessing = LloydsFile::combineDailyProcessing($lloydsFiles);
        $totalReceived = 0;
        foreach ($dailyReceived as $key => $value) {
            if (stripos($key, sprintf('%d%02d', $year, $month)) !== false) {
                $totalReceived += $value;
            }
        }
        $totalLloydsProcessing = 0;
        foreach ($dailyLloydsProcessing as $key => $value) {
            if (stripos($key, sprintf('%d%02d', $year, $month)) !== false) {
                $totalLloydsProcessing += $value;
            }
        }

        return [
            'lloydsForm' => $lloydsForm->createView(),
            'barclaysForm' => $barclaysForm->createView(),
            'year' => $year,
            'month' => $month,
            'days_in_month' => cal_days_in_month(CAL_GREGORIAN, $month, $year),
            'paymentTotals' => $paymentTotals,
            'totalTransaction' => $totalTransaction,
            'totalBarclaysProcessing' => $totalBarclaysProcessing,
            'totalLloydsProcessing' => $totalLloydsProcessing,
            'totalReceived' => $totalReceived,
            'paymentDailys' => $paymentDailys,
            'dailyTransaction' => $dailyTransaction,
            'dailyBarclaysProcessing' => $dailyBarclaysProcessing,
            'dailyLloydsProcessing' => $dailyLloydsProcessing,
            'dailyReceived' => $dailyReceived,
            'barclaysFiles' => $barclaysFiles,
            'lloydsFiles' => $lloydsFiles,
        ];
    }

    /**
     * @Route("/charge", name="admin_charge")
     * @Route("/charge/{year}/{month}", name="admin_charge_date")
     * @Template
     */
    public function chargeAction($year = null, $month = null)
    {
        $now = new \DateTime();
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        $repo = $dm->getRepository(Charge::class);
        $charges = $repo->findMonthly($date);
        $summary = [];
        foreach ($charges as $charge) {
            if (!isset($summary[$charge->getType()])) {
                $summary[$charge->getType()] = 0;
            }
            $summary[$charge->getType()] += $charge->getAmount();
        }

        return [
            'year' => $year,
            'month' => $month,
            'charges' => $charges,
            'summary' => $summary,
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
}
