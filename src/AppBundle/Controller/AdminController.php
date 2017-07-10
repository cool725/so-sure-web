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
use AppBundle\Document\BacsPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Invoice;
use AppBundle\Document\Feature;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Stats;
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
use AppBundle\Form\Type\ClaimFlagsType;
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
 * @Security("has_role('ROLE_ADMIN')")
 */
class AdminController extends BaseController
{
    use DateTrait;
    use CurrencyTrait;

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

            $price = new PhonePrice();
            $price->setGwp($request->get('gwp'));
            $price->setValidFrom($from);
            $notes = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'notes'), 1500);
            $price->setNotes($notes);
            if ($request->get('to')) {
                $price->setValidTo($to);
            }

            if ($price->getMonthlyPremiumPrice($from) < $phone->getSalvaMiniumumBinderMonthlyPremium()) {
                $this->addFlash('error', sprintf(
                    '£%.2f is less than allowed min binder £%.2f',
                    $price->getMonthlyPremiumPrice($from),
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
     * @Route("/phone/{id}/details", name="admin_phone_details")
     * @Method({"POST"})
     */
    public function phoneDetailsAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $editPhone = $repo->find($id);
        if ($editPhone) {
            $phones = $repo->findBy(['make' => $editPhone->getMake(), 'model' => $editPhone->getModel()]);
            foreach ($phones as $phone) {
                $phone->setDescription($request->get('description'));
                $phone->setFunFacts($request->get('fun-facts'));
            }
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
     * @Route("/admin-users", name="admin_admin_users")
     * @Template
     */
    public function adminAdminUsersAction()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);

        $admins = $repo->findUsersInRole('ROLE_ADMIN');
        $employees = $repo->findUsersInRole('ROLE_EMPLOYEE');

        return [
            'admins' => $admins,
            'employees' => $employees,
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
     * @Route("/claims/replacement-phone", name="admin_claims_replacement_phone")
     * @Method({"POST"})
     */
    public function adminClaimsReplacementPhoneAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $phoneRepo = $dm->getRepository(Phone::class);
        $claim = $repo->find($request->get('id'));
        $phone = $phoneRepo->find($request->get('phone'));
        if ($claim && $phone) {
            $claim->setReplacementPhone($phone);
            $dm->flush();
        }

        return $this->redirectToRoute('admin_claims');
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
        $isProd = $this->isProduction();
        $payments = $this->getPayments($date);

        return [
            'all' => Payment::sumPayments($payments, $isProd),
            'judo' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'sosure' => Payment::sumPayments($payments, $isProd, SoSurePayment::class),
            'bacs' => Payment::sumPayments($payments, $isProd, BacsPayment::class),
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
                    $judoFile->setKeyFormat($this->getParameter('kernel.environment') . '/upload/%s');

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
        $isProd = $this->isProduction();
        $paymentTotals = Payment::sumPayments($payments, $isProd, JudoPayment::class);
        $paymentDailys = Payment::dailyPayments($payments, $isProd, JudoPayment::class);

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
                    $lloydsFile->setKeyFormat($this->getParameter('kernel.environment') . '/upload/%s');

                    $lloydsService = $this->get('app.lloyds');
                    $data = $lloydsService->processCsv($lloydsFile);

                    $dm->persist($lloydsFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('n'),
                    ]);
                }
            } elseif ($request->request->has('barclays')) {
                $barclaysForm->handleRequest($request);
                if ($barclaysForm->isSubmitted() && $barclaysForm->isValid()) {
                    $dm = $this->getManager();
                    $barclaysFile->setBucket('admin.so-sure.com');
                    $barclaysFile->setKeyFormat($this->getParameter('kernel.environment') . '/upload/%s');

                    $barclaysService = $this->get('app.barclays');
                    $data = $barclaysService->processCsv($barclaysFile);

                    $dm->persist($barclaysFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('n'),
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
     * @Route("/invoices", name="admin_invoices")
     * @Template
     */
    public function invoicesAction()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Invoice::class);
        $invoices = $repo->findAll();

        return [
            'invoices' => $invoices,
        ];
    }

    /**
     * @Route("/feature-flags", name="admin_feature_flags")
     * @Template
     */
    public function featureFlagsAction()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Feature::class);
        $features = $repo->findAll();
        $form = $this->get('form.factory')
            ->createNamedBuilder('feature_form')->add('disable', SubmitType::class)
            ->getForm();

        return [
            'features' => $features,
        ];
    }

    /**
     * @Route("/feature-flags/{id}/active", name="admin_feature_flags_active")
     * @Method({"POST"})
     */
    public function featureFlagsActiveAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Feature::class);
        $feature = $repo->find($id);
        if ($feature) {
            if ($feature->isEnabled()) {
                $feature->setEnabled(false);
                $message = sprintf('Feature %s is now disabled', $feature->getName());
            } else {
                $feature->setEnabled(true);
                $message = sprintf('Feature %s is now active', $feature->getName());
            }
            $dm->flush();
            $this->addFlash(
                'notice',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_feature_flags'));
    }

    /**
     * @Route("/claim/flag/{id}", name="admin_claim_flags")
     * @Method({"POST"})
     */
    public function adminClaimFlagsAction(Request $request, $id)
    {
        $formData = $request->get('claimflags');
        if (!isset($formData['_token'])) {
            throw new \InvalidArgumentException('Missing parameters');
        }
        /*
        if (!$this->isCsrfTokenValid('default', $formData['_token'])) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }
        */

        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->find($id);
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }

        $formData = $request->get('claimflags');
        $claim->clearIgnoreWarningFlags();
        // may be empty if all unchecked
        if (isset($formData['ignoreWarningFlags'])) {
            foreach ($formData['ignoreWarningFlags'] as $flag) {
                $claim->setIgnoreWarningFlags($flag);
            }
        }
        $dm->flush();

        $this->addFlash(
            'success',
            'Claim flags updated'
        );

        return new RedirectResponse($this->generateUrl('admin_policy', ['id' => $claim->getPolicy()->getId()]));
    }

    /**
     * @Route("/claim/withdraw/{id}", name="admin_claim_withdraw")
     * @Method({"POST"})
     */
    public function adminClaimWithdrawAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('_token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->find($id);
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }
        if (!in_array($claim->getStatus(), [
            Claim::STATUS_INREVIEW,
            Claim::STATUS_PENDING_CLOSED,
            Claim::STATUS_APPROVED,
        ])) {
            throw new \Exception(
                'Claim can only be withdrawn if claim is in-review, approved or pending-closed state'
            );
        }

        $claim->setStatus(Claim::STATUS_WITHDRAWN);
        $dm->flush();

        $this->addFlash(
            'success',
            sprintf('Claim %s withdrawn', $claim->getNumber())
        );

        return new RedirectResponse($this->generateUrl('admin_policy', ['id' => $claim->getPolicy()->getId()]));
    }
}
