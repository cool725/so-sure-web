<?php

namespace AppBundle\Controller;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\ArrayToApiArrayTrait;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\File\BacsReportAruddFile;
use AppBundle\Document\File\BacsReportDdicFile;
use AppBundle\Document\File\BacsReportInputFile;
use AppBundle\Document\File\ReconciliationFile;
use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Document\Payment\BacsIndemnityPayment;
use AppBundle\Document\Sequence;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Form\Type\ChargeReportType;
use AppBundle\Form\Type\BacsMandatesType;
use AppBundle\Form\Type\PolicyStatusType;
use AppBundle\Form\Type\SalvaRequeueType;
use AppBundle\Form\Type\SalvaStatusType;
use AppBundle\Form\Type\UploadFileType;
use AppBundle\Form\Type\ReconciliationFileType;
use AppBundle\Form\Type\SequenceType;
use AppBundle\Repository\BacsIndemnityPaymentRepository;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\File\BacsReportAruddFileRepository;
use AppBundle\Repository\File\BacsReportInputFileRepository;
use AppBundle\Repository\File\BarclaysFileRepository;
use AppBundle\Repository\File\BarclaysStatementFileRepository;
use AppBundle\Repository\File\JudoFileRepository;
use AppBundle\Repository\File\LloydsFileRepository;
use AppBundle\Repository\File\ReconcilationFileRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\BarclaysService;
use AppBundle\Service\LloydsService;
use AppBundle\Service\MailerService;
use AppBundle\Service\ReportingService;
use AppBundle\Service\SalvaExportService;
use AppBundle\Service\SequenceService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
use AppBundle\Document\Cashback;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\Payment\ChargebackPayment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Invoice;
use AppBundle\Document\Feature;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Stats;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\JudoFile;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\File\BarclaysStatementFile;
use AppBundle\Document\File\LloydsFile;
use AppBundle\Document\Form\Cancel;
use AppBundle\Form\Type\CancelPolicyType;
use AppBundle\Form\Type\ClaimType;
use AppBundle\Form\Type\CashbackSearchType;
use AppBundle\Form\Type\ClaimFlagsType;
use AppBundle\Form\Type\ChargebackType;
use AppBundle\Form\Type\ImeiType;
use AppBundle\Form\Type\NoteType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Form\Type\AdminSmsOptOutType;
use AppBundle\Form\Type\PartialPolicyType;
use AppBundle\Form\Type\UserSearchType;
use AppBundle\Form\Type\PhoneSearchType;
use AppBundle\Form\Type\JudoFileType;
use AppBundle\Form\Type\FacebookType;
use AppBundle\Form\Type\BarclaysFileType;
use AppBundle\Form\Type\BarclaysStatementFileType;
use AppBundle\Form\Type\LloydsFileType;
use AppBundle\Form\Type\PendingPolicyCancellationType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
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
    use ArrayToApiArrayTrait;
    use ValidatorTrait;

    /**
     * @Route("/claims/delete-claim", name="admin_claims_delete_claim")
     * @Method({"POST"})
     */
    public function adminClaimsDeleteClaim(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        $claim = $repo->find($request->get('id'));
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }

        $subject = sprintf("Claim %s has been manually deleted.", $claim->getNumber());
        $mailer = $this->get('app.mailer');
        $mailer->sendTemplate(
            $subject,
            'tech@so-sure.com',
            'AppBundle:Email:claim/manuallyDeleted.html.twig',
            ['claim' => $claim, 'policy' => $claim->getPolicy()]
        );
        foreach ($claim->getCharges() as $charge) {
            $charge->setClaim(null);
            $this->get('logger')->warning(sprintf(
                'Charge %s for £%0.2f has been disassocated for deleted claim %s (%s)',
                $charge->getId(),
                $charge->getAmount(),
                $claim->getNumber(),
                $claim->getId()
            ));
        }
        $dm->remove($claim);
        $dm->flush();
        $dm->clear();

        return $this->redirectToRoute('admin_claims');
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
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice();
        $price->setGwp($request->get('gwp'));
        $dm->persist($phone);
        $dm->flush();
        $this->addFlash(
            'success',
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
        /** @var Phone $phone */
        $phone = $repo->find($id);
        if ($phone) {
            $gwp = $request->get('gwp');
            $from = new \DateTime($request->get('from'), new \DateTimeZone(SoSure::TIMEZONE));
            $to = null;
            if ($request->get('to')) {
                $to = new \DateTime($request->get('to'), new \DateTimeZone(SoSure::TIMEZONE));
            }
            $notes = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'notes'), 1500);
            try {
                $policyTerms = $this->getLatestPolicyTerms();
                $phone->changePrice(
                    $gwp,
                    $from,
                    $policyTerms->getDefaultExcess(),
                    $policyTerms->getDefaultPicSureExcess(),
                    $to,
                    $notes
                );
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());

                return new RedirectResponse($this->generateUrl('admin_phones'));
            }

            $dm->flush();
            $this->addFlash(
                'success',
                'Your changes were saved!'
            );

            /** @var MailerService $mailer */
            $mailer = $this->get('app.mailer');
            $mailer->send(
                sprintf('Phone pricing update for %s', $phone),
                'marketing@so-sure.com',
                sprintf(
                    'On %s, the price for %s will be updated to £%0.2f (£%0.2f GWP). Notes: %s',
                    $from->format(\DateTime::ATOM),
                    $phone,
                    $this->withIpt($gwp),
                    $gwp,
                    $notes
                ),
                null,
                null,
                'tech@so-sure.com'
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
                'success',
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
                'success',
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
        /** @var UserRepository $repo */
        $repo = $dm->getRepository(User::class);

        $customerServices = $repo->findUsersInRole(User::ROLE_CUSTOMER_SERVICES)->toArray();
        $employees = $repo->findUsersInRole(User::ROLE_EMPLOYEE)->toArray();
        $admins = $repo->findUsersInRole(User::ROLE_ADMIN)->toArray();

        return [
            'users' => array_merge($customerServices, $employees, $admins),
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
     * @Route("/accounts/print/{year}/{month}", name="admin_accounts_print")
     */
    public function adminAccountsPrintAction($year, $month)
    {
        // default 30s for prod is no longer enough
        set_time_limit(600);

        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $templating = $this->get('templating');
        $snappyPdf = $this->get('knp_snappy.pdf');
        $snappyPdf->setOption('orientation', 'Portrait');
        $snappyPdf->setOption('page-size', 'A4');
        /** @var ReportingService $reportingService */
        $reportingService = $this->get('app.reporting');


        $html = $templating->render('AppBundle:Pdf:adminAccounts.html.twig', [
            'year' => $year,
            'month' => $month,
            'paymentTotals' => $reportingService->getAllPaymentTotals($this->isProduction(), $date),
            'activePolicies' => $reportingService->getActivePoliciesCount($date),
            'activePoliciesWithDiscount' => $reportingService->getActivePoliciesWithPolicyDiscountCount($date),
            'rewardPotLiability' => $reportingService->getRewardPotLiability($date),
            'rewardPromoPotLiability' => $reportingService->getRewardPotLiability($date, true),
            'stats' => $reportingService->getStats($date),
            'print' => true,
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

    /**
     * @Route("/accounts", name="admin_accounts")
     * @Route("/accounts/{year}/{month}", name="admin_accounts_date")
     * @Template
     */
    public function adminAccountsAction(Request $request, $year = null, $month = null)
    {
        // default 30s for prod is no longer enough
        set_time_limit(600);

        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $salvaForm = $this->get('form.factory')
            ->createNamedBuilder('salva_form')
            ->add('export', SubmitType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('salva_form')) {
                $salvaForm->handleRequest($request);
                if ($salvaForm->isValid()) {
                    /** @var SalvaExportService $salva */
                    $salva = $this->get('app.salva');
                    $salva->exportPayments(true);
                    $this->addFlash(
                        'success',
                        'Re-exported Salva Payments File to S3'
                    );
                    return new RedirectResponse($this->generateUrl('admin_accounts_date', [
                        'year' => $year,
                        'month' => $month
                    ]));
                }
            }
        }

        $dm = $this->getManager();
        $s3FileRepo = $dm->getRepository(S3File::class);
        /** @var ReportingService $reportingService */
        $reportingService = $this->get('app.reporting');

        return [
            'year' => $year,
            'month' => $month,
            'paymentTotals' => $reportingService->getAllPaymentTotals($this->isProduction(), $date),
            'activePolicies' => $reportingService->getActivePoliciesCount($date),
            'activePoliciesWithDiscount' => $reportingService->getActivePoliciesWithPolicyDiscountCount($date),
            'rewardPotLiability' => $reportingService->getRewardPotLiability($date),
            'rewardPromoPotLiability' => $reportingService->getRewardPotLiability($date, true),
            'files' => $s3FileRepo->getAllFiles($date),
            'salvaForm' => $salvaForm->createView(),
            'stats' => $reportingService->getStats($date),
            'print' => false,
        ];
    }

    /**
     * @Route("/bacs", name="admin_bacs")
     * @Route("/bacs/{year}/{month}", name="admin_bacs_date", requirements={"year":"[0-9]{4,4}","month":"[0-9]{1,2}"})
     * @Template
     */
    public function bacsAction(Request $request, $year = null, $month = null)
    {
        // default 30s for prod is no longer enough
        set_time_limit(300);

        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        /** @var UserRepository $userRepo */
        $userRepo = $dm->getRepository(User::class);
        /** @var S3FileRepository $s3FileRepo */
        $s3FileRepo = $dm->getRepository(S3File::class);
        /** @var BacsPaymentRepository $paymentsRepo */
        $paymentsRepo = $dm->getRepository(BacsPayment::class);
        /** @var BacsIndemnityPaymentRepository $paymentsIndemnityRepo */
        $paymentsIndemnityRepo = $dm->getRepository(BacsIndemnityPayment::class);
        $sequenceRepo = $dm->getRepository(Sequence::class);
        /** @var Sequence $currentSequence */
        $currentSequence = $sequenceRepo->findOneBy(['name' => SequenceService::SEQUENCE_BACS_SERIAL_NUMBER]);
        // just in case its not present (dev)
        if (!$currentSequence) {
            /** @var SequenceService $seqService */
            $seqService = $this->get('app.sequence');
            $sequence = $seqService->getSequenceId(SequenceService::SEQUENCE_BACS_SERIAL_NUMBER);

            /** @var Sequence $currentSequence */
            $currentSequence = $sequenceRepo->findOneBy(['name' => SequenceService::SEQUENCE_BACS_SERIAL_NUMBER]);
        }

        /** @var BacsService $bacs */
        $bacs = $this->get('app.bacs');
        $uploadForm = $this->get('form.factory')
            ->createNamedBuilder('upload', UploadFileType::class)
            ->getForm();
        $uploadDebitForm = $this->get('form.factory')
            ->createNamedBuilder('uploadDebit', UploadFileType::class)
            ->getForm();
        $uploadCreditForm = $this->get('form.factory')
            ->createNamedBuilder('uploadCredit', UploadFileType::class)
            ->getForm();
        $mandatesForm = $this->get('form.factory')
            ->createNamedBuilder('mandates', BacsMandatesType::class)
            ->getForm();
        $sequenceData = new \AppBundle\Document\Form\Sequence();
        $sequenceData->setSeq($currentSequence->getSeq());
        $sequenceForm = $this->get('form.factory')
            ->createNamedBuilder('sequence', SequenceType::class, $sequenceData)
            ->getForm();
        $approvePaymentsForm = $this->get('form.factory')
            ->createNamedBuilder('approvePayments')
            ->add('confirm', CheckboxType::class)
            ->add('approve', SubmitType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('upload')) {
                $uploadForm->handleRequest($request);
                if ($uploadForm->isSubmitted() && $uploadForm->isValid()) {
                    $file = $uploadForm->getData()['file'];
                    if ($bacs->isS3FilePresent($file->getClientOriginalName())) {
                        $this->addFlash(
                            'error',
                            sprintf('File is already processed (%s).', $file->getClientOriginalName())
                        );
                    } elseif ($bacs->processUpload($file)) {
                        $this->addFlash(
                            'success',
                            sprintf('Successfully uploaded & processed file (%s)', $file->getClientOriginalName())
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            sprintf('Unable to process file (%s).', $file->getClientOriginalName())
                        );
                    }

                    return new RedirectResponse($this->generateUrl('admin_bacs_date', [
                        'year' => $year,
                        'month' => $month
                    ]));
                }
            } elseif ($request->request->has('uploadDebit')) {
                $uploadDebitForm->handleRequest($request);
                if ($uploadDebitForm->isSubmitted() && $uploadDebitForm->isValid()) {
                    $file = $uploadDebitForm->getData()['file'];
                    if ($bacs->processSubmissionUpload($file)) {
                        $this->addFlash(
                            'success',
                            'Successfully uploaded & submitted bacs debit file'
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Unable to process file - see rollbar error message'
                        );
                    }

                    return new RedirectResponse($this->generateUrl('admin_bacs_date', [
                        'year' => $year,
                        'month' => $month
                    ]));
                }
            } elseif ($request->request->has('uploadCredit')) {
                $uploadCreditForm->handleRequest($request);
                if ($uploadCreditForm->isSubmitted() && $uploadCreditForm->isValid()) {
                    $file = $uploadCreditForm->getData()['file'];
                    if ($bacs->processSubmissionUpload($file, false)) {
                        $this->addFlash(
                            'success',
                            'Successfully uploaded & submitted bacs credit file'
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Unable to process file - see rollbar error message'
                        );
                    }

                    return new RedirectResponse($this->generateUrl('admin_bacs_date', [
                        'year' => $year,
                        'month' => $month
                    ]));
                }
            } elseif ($request->request->has('mandates')) {
                $mandatesForm->handleRequest($request);
                if ($mandatesForm->isSubmitted() && $mandatesForm->isValid()) {
                    $userId = $mandatesForm->getData()['serialNumber'];
                    $userRepo = $this->getManager()->getRepository(User::class);
                    /** @var User $user */
                    $user = $userRepo->find($userId);
                    /** @var BacsPaymentMethod $bacsPaymentMethod */
                    $bacsPaymentMethod = $user->getPaymentMethod();
                    $serialNumber = $bacsPaymentMethod->getBankAccount()->getMandateSerialNumber();
                    if ($bacs->approveMandates($serialNumber)) {
                        $this->addFlash(
                            'success',
                            'Successfully approved mandates'
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Unable to approve mandates'
                        );
                    }

                    return new RedirectResponse($this->generateUrl('admin_bacs_date', [
                        'year' => $year,
                        'month' => $month
                    ]));
                }
            } elseif ($request->request->has('sequence')) {
                $sequenceForm->handleRequest($request);
                if ($sequenceForm->isSubmitted() && $sequenceForm->isValid()) {
                    $currentSequence->resetSeq($sequenceData->getSeq());
                    $this->getManager()->flush();
                    $this->addFlash(
                        'success',
                        'Updated sequence file'
                    );

                    return new RedirectResponse($this->generateUrl('admin_bacs_date', [
                        'year' => $year,
                        'month' => $month
                    ]));
                }
            } elseif ($request->request->has('approvePayments')) {
                $approvePaymentsForm->handleRequest($request);
                if ($approvePaymentsForm->isSubmitted() && $approvePaymentsForm->isValid()) {
                    /** @var BacsService $bacsService */
                    $bacsService = $this->get('app.bacs');
                    $bacsService->approvePayments(\DateTime::createFromFormat('U', time()));
                    $this->addFlash(
                        'success',
                        'Approved outstanding payments'
                    );

                    return new RedirectResponse($this->generateUrl('admin_bacs_date', [
                        'year' => $year,
                        'month' => $month
                    ]));
                }
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'files' => $s3FileRepo->getAllFiles($date, 'accesspay'),
            'addacs' => $s3FileRepo->getAllFiles($date, 'bacsReportAddacs'),
            'auddis' => $s3FileRepo->getAllFiles($date, 'bacsReportAuddis'),
            'arudds' => $s3FileRepo->getAllFiles($date, 'bacsReportArudd'),
            'ddic' => $s3FileRepo->getAllFiles($date, 'bacsReportDdic'),
            'input' => $s3FileRepo->getAllFiles($date, 'bacsReportInput'),
            'inputIncPrevMonth' => $s3FileRepo->getAllFiles($date, 'bacsReportInput', true),
            'payments' => $paymentsRepo->findPayments($date),
            'paymentsIncPrevNextMonth' => $paymentsRepo->findPaymentsIncludingPreviousNextMonth($date),
            'indemnity' => $paymentsIndemnityRepo->findPayments($date),
            'uploadForm' => $uploadForm->createView(),
            'uploadDebitForm' => $uploadDebitForm->createView(),
            'uploadCreditForm' => $uploadCreditForm->createView(),
            'mandatesForm' => $mandatesForm->createView(),
            'sequenceForm' => $sequenceForm->createView(),
            'approvePaymentsForm' => $approvePaymentsForm->createView(),
            'currentSequence' => $currentSequence,
            'outstandingMandates' => $userRepo->findPendingMandates()->getQuery()->execute()->count(),
        ];
    }

    /**
     * @Route("/bacs/file/download/{id}", name="admin_bacs_file")
     */
    public function bacsDownloadFileAction($id)
    {
        $dm = $this->getManager();
        /** @var S3FileRepository $repo */
        $repo = $dm->getRepository(S3File::class);
        /** @var S3File $s3File */
        $s3File = $repo->find($id);
        if (!$s3File) {
            throw new NotFoundHttpException();
        }

        /** @var BacsService $bacs */
        $bacs = $this->get('app.bacs');

        $file = $bacs->downloadS3($s3File);
        return StreamedResponse::create(
            function () use ($file) {
                readfile($file);
            },
            200,
            array('Content-Type' => 'text/plain')
        );
    }

    /**
     * @Route("/bacs/users/serial/{serial}", name="admin_bacs_serial_number_details")
     * @Method({"GET"})
     */
    public function bacsFileAction($serial)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $users = $repo->findBy(['paymentMethod.bankAccount.mandateSerialNumber' => (string) $serial]);
        $paymentMethods = [];
        foreach ($users as $user) {
            /** @var User $user */
            /** @var BacsPaymentMethod $bacs */
            $bacs = $user->getPaymentMethod();
            if ($bacs->getBankAccount()) {
                $paymentMethods[] = $bacs->getBankAccount()->toDetailsArray();
            }
        }

        return new JsonResponse($paymentMethods);
    }

    /**
     * @Route("/bacs/file/submit/{id}", name="admin_bacs_submit")
     * @Route("/bacs/file/cancel/{id}", name="admin_bacs_cancel")
     * @Route("/bacs/file/serial/{id}", name="admin_bacs_update_serial_number")
     * @Method({"POST"})
     */
    public function bacsEditFileAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        /** @var BacsService $bacsService */
        $bacsService = $this->get('app.bacs');
        $dm = $this->getManager();
        $repo = $dm->getRepository(AccessPayFile::class);
        /** @var AccessPayFile $file */
        $file = $repo->find($id);
        if ($file) {
            $message = 'Unknown';
            if ($request->get('_route') == 'admin_bacs_submit') {
                $bacsService->bacsFileSubmitted($file);
                $message = sprintf('Bacs file %s is marked as submitted', $file->getFileName());
            } elseif ($request->get('_route') == 'admin_bacs_cancel') {
                $bacsService->bacsFileCancelled($file);
                $message = sprintf(
                    'Bacs file %s is marked as cancelled. Remember to update payment serial numbers',
                    $file->getFileName()
                );
            } elseif ($request->get('_route') == 'admin_bacs_update_serial_number') {
                $bacsService->bacsFileUpdateSerialNumber($file, $request->get('serialNumber'));
                $message = sprintf('Bacs file %s serial number updated', $file->getFileName());
            } else {
                throw new \Exception('Unknown route');
            }

            $dm->flush();
            $this->addFlash(
                'success',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_bacs'));
    }

    /**
     * @Route("/bacs/payment/approve/{id}", name="admin_bacs_payment_approve")
     * @Route("/bacs/payment/reject/{id}", name="admin_bacs_payment_reject")
     * @Route("/bacs/payment/serial/{id}", name="admin_bacs_payment_serial")
     * @Route("/bacs/payment/serial/{id}/", name="admin_bacs_payment_serial_slash")
     * @Method({"POST"})
     */
    public function bacsPaymentAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $paymentRepo = $dm->getRepository(BacsPayment::class);
        $accessPayRepo = $dm->getRepository(AccessPayFile::class);
        /** @var BacsPayment $payment */
        $payment = $paymentRepo->find($id);
        if ($payment) {
            $message = 'Unknown';
            if ($request->get('_route') == 'admin_bacs_payment_approve') {
                $payment->approve();
                $message = 'Payment is approved';
            } elseif ($request->get('_route') == 'admin_bacs_payment_reject') {
                $payment->reject();
                $message = 'Payment is rejected';
            } elseif ($request->get('_route') == 'admin_bacs_payment_serial') {
                $serial = $request->get('serialNumber');

                $payment->setSerialNumber($serial);

                /** @var AccessPayFile $accessPayFile */
                $accessPayFile = $accessPayRepo->findOneBy(['serialNumber' => $serial]);
                if ($accessPayFile) {
                    // Moving forward, submitted date should be present, but for older files use the created date,
                    // which should more or less match
                    if ($accessPayFile->getSubmittedDate()) {
                        $payment->submit($accessPayFile->getSubmittedDate());
                    } else {
                        $payment->submit($accessPayFile->getCreated());
                    }
                    $message = 'Serial number has been updated and payment dates updated to match submission file';
                } else {
                    $message = 'Serial number has been updated, but unable to locate submission file to update dates';
                }
            }
            $dm->flush();
            $this->addFlash(
                'success',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_bacs'));
    }

    /**
     * @Route("/banking", name="admin_banking")
     * @Route("/banking/{year}/{month}", name="admin_banking_date")
     * @Template
     */
    public function adminBankingAction(Request $request, $year = null, $month = null)
    {
        // default 30s for prod is no longer enough
        set_time_limit(600);

        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        /** @var PaymentRepository $paymentRepo */
        $paymentRepo = $dm->getRepository(Payment::class);
        /** @var S3FileRepository $salvaFileRepo */
        $salvaFileRepo = $dm->getRepository(SalvaPaymentFile::class);
        /** @var JudoFileRepository $judoFileRepo */
        $judoFileRepo = $dm->getRepository(JudoFile::class);
        /** @var BarclaysStatementFileRepository $barclaysStatementFileRepo */
        $barclaysStatementFileRepo = $dm->getRepository(BarclaysStatementFile::class);
        /** @var BarclaysFileRepository $barclaysFileRepo */
        $barclaysFileRepo = $dm->getRepository(BarclaysFile::class);
        /** @var LloydsFileRepository $lloydsFileRepo */
        $lloydsFileRepo = $dm->getRepository(LloydsFile::class);
        /** @var ReconcilationFileRepository $reconcilationFileRepo */
        $reconcilationFileRepo = $dm->getRepository(ReconciliationFile::class);
        /** @var BacsReportInputFileRepository $inputRepo */
        $inputRepo = $dm->getRepository(BacsReportInputFile::class);
        /** @var BacsReportAruddFileRepository $aruddRepo */
        $aruddRepo = $dm->getRepository(BacsReportAruddFile::class);
        /** @var BacsReportDdicFileRepository $aruddRepo */
        $ddicRepo = $dm->getRepository(BacsReportDdicFile::class);

        $payments = $paymentRepo->getAllPaymentsForExport($date);
        $extraPayments = $paymentRepo->getAllPaymentsForExport($date, true);
        $extraCreditPayments = array_filter($extraPayments->toArray(), function ($v) {
            return $v->getAmount() >= 0.0;
        });
        $extraDebitPayments = array_filter($extraPayments->toArray(), function ($v) {
            return $v->getAmount() < 0.0;
        });
        $isProd = $this->isProduction();
        $tz = new \DateTimeZone(SoSure::TIMEZONE);
        $sosure = [
            'dailyTransaction' => Payment::dailyPayments($payments, $isProd),
            'monthlyTransaction' => Payment::sumPayments($payments, $isProd),
            'dailyShiftedTransaction' => Payment::dailyPayments($payments, $isProd, null, $tz),
            'dailyJudoTransaction' => Payment::dailyPayments($payments, $isProd, JudoPayment::class),
            'monthlyJudoTransaction' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'dailyJudoShiftedTransaction' => Payment::dailyPayments($payments, $isProd, JudoPayment::class, $tz),
            'monthlyJudoShiftedTransaction' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'dailyCreditBacsTransaction' => Payment::dailyPayments(
                $extraCreditPayments,
                $isProd,
                BacsPayment::class,
                null,
                'getBacsCreditDate'
            ),
            'dailyDebitBacsTransaction' => Payment::dailyPayments(
                $extraDebitPayments,
                $isProd,
                BacsPayment::class
            ),
            'monthlyBacsTransaction' => Payment::sumPayments($payments, $isProd, BacsPayment::class),
        ];

        $judoFile = new JudoFile();
        $judoForm = $this->get('form.factory')
            ->createNamedBuilder('judo', JudoFileType::class, $judoFile)
            ->getForm();
        $barclaysFile = new BarclaysFile();
        $barclaysForm = $this->get('form.factory')
            ->createNamedBuilder('barclays', BarclaysFileType::class, $barclaysFile)
            ->getForm();
        $barclaysStatementFile = new BarclaysStatementFile();
        $barclaysStatementForm = $this->get('form.factory')
            ->createNamedBuilder('barclays_statement', BarclaysStatementFileType::class, $barclaysStatementFile)
            ->getForm();
        $lloydsFile = new LloydsFile();
        $lloydsForm = $this->get('form.factory')
            ->createNamedBuilder('lloyds', LloydsFileType::class, $lloydsFile)
            ->getForm();
        $reconciliationFile = new ReconciliationFile();
        $reconciliationFile->setMonthlyTotal($sosure['monthlyTransaction']['total']);
        $reconciliationForm = $this->get('form.factory')
            ->createNamedBuilder('reconciliation', ReconciliationFileType::class, $reconciliationFile)
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
                    $barclaysFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    /** @var BarclaysService $barclaysService */
                    $barclaysService = $this->get('app.barclays');
                    $data = $barclaysService->processCsv($barclaysFile);

                    $dm->persist($barclaysFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('n'),
                    ]);
                }
            } elseif ($request->request->has('barclays_statement')) {
                $barclaysStatementForm->handleRequest($request);
                if ($barclaysStatementForm->isSubmitted() && $barclaysStatementForm->isValid()) {
                    $dm = $this->getManager();
                    $barclaysStatementFile->setBucket('admin.so-sure.com');
                    $barclaysStatementFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $barclaysService = $this->get('app.barclays');
                    $data = $barclaysService->processStatementNewCsv($barclaysStatementFile);

                    $dm->persist($barclaysStatementFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('n'),
                    ]);
                }
            } elseif ($request->request->has('lloyds')) {
                $lloydsForm->handleRequest($request);
                if ($lloydsForm->isSubmitted() && $lloydsForm->isValid()) {
                    $dm = $this->getManager();
                    $lloydsFile->setBucket('admin.so-sure.com');
                    $lloydsFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    /** @var LloydsService $lloydsService */
                    $lloydsService = $this->get('app.lloyds');
                    $data = $lloydsService->processCsv($lloydsFile);

                    $dm->persist($lloydsFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('n'),
                    ]);
                }
            } elseif ($request->request->has('reconciliation')) {
                $reconciliationForm->handleRequest($request);
                if ($reconciliationForm->isSubmitted() && $reconciliationForm->isValid()) {
                    $dm = $this->getManager();
                    $reconciliationFile->setBucket('admin.so-sure.com');
                    $reconciliationFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');
                    $reconciliationFile->setDate($date);

                    $dm->persist($reconciliationFile);
                    $dm->flush();

                    return $this->redirectToRoute('admin_banking_date', [
                        'year' => $date->format('Y'),
                        'month' => $date->format('n'),
                    ]);
                }
            }
        }

        $monthlyReconcilationFiles = $reconcilationFileRepo->getMonthlyFiles($date);
        $yearlyReconcilationFiles = $reconcilationFileRepo->getYearlyFilesToDate($date);
        $allReconcilationFiles = $reconcilationFileRepo->getAllFilesToDate($date);

        $reconciliation['monthlyTransaction'] = ReconciliationFile::combineMonthlyTotal($monthlyReconcilationFiles);
        $reconciliation['yearlyTransaction'] = ReconciliationFile::combineMonthlyTotal($yearlyReconcilationFiles);
        $reconciliation['allTransaction'] = ReconciliationFile::combineMonthlyTotal($allReconcilationFiles);

        $monthlySalvaFiles = $salvaFileRepo->getMonthlyFiles($date);
        $monthlyPerDaySalvaTransaction = SalvaPaymentFile::combineDailyTransactions($monthlySalvaFiles);

        $salva = [
            'dailyTransaction' => $monthlyPerDaySalvaTransaction,
            'monthlyTransaction' => SalvaPaymentFile::totalCombinedFiles(
                $monthlyPerDaySalvaTransaction,
                $year,
                $month
            ),
        ];

        $monthlyJudoFiles = $judoFileRepo->getMonthlyFiles($date);
        $monthlyPerDayJudoTransaction = JudoFile::combineDailyTransactions($monthlyJudoFiles);

        $yearlyJudoFiles = $judoFileRepo->getYearlyFilesToDate($date);
        $yearlyPerDayJudoTransaction = JudoFile::combineDailyTransactions($yearlyJudoFiles);

        $allJudoFiles = $judoFileRepo->getAllFilesToDate($date);
        $allJudoTransaction = JudoFile::combineDailyTransactions($allJudoFiles);

        $judo = [
            'dailyTransaction' => $monthlyPerDayJudoTransaction,
            'monthlyTransaction' => JudoFile::totalCombinedFiles($monthlyPerDayJudoTransaction, $year, $month),
            'yearlyTransaction' => JudoFile::totalCombinedFiles($yearlyPerDayJudoTransaction),
            'allTransaction' => JudoFile::totalCombinedFiles($allJudoTransaction),
        ];

        $monthlyBarclaysFiles = $barclaysFileRepo->getMonthlyFiles($date);
        $monthlyPerDayBarclaysTransaction = BarclaysFile::combineDailyTransactions($monthlyBarclaysFiles);
        $monthlyPerDayBarclaysProcessing = BarclaysFile::combineDailyProcessing($monthlyBarclaysFiles);

        $yearlyBarclaysFiles = $barclaysFileRepo->getYearlyFilesToDate($date);
        $yearlyBarclaysTransaction = BarclaysFile::combineDailyTransactions($yearlyBarclaysFiles);
        $yearlyBarclaysProcessing = BarclaysFile::combineDailyProcessing($yearlyBarclaysFiles);

        $allBarclaysFiles = $barclaysFileRepo->getAllFilesToDate($date);
        $allBarclaysTransaction = BarclaysFile::combineDailyTransactions($allBarclaysFiles);
        $allBarclaysProcessing = BarclaysFile::combineDailyProcessing($allBarclaysFiles);

        $barclays = [
            'dailyTransaction' => $monthlyPerDayBarclaysTransaction,
            'dailyProcessed' => $monthlyPerDayBarclaysProcessing,
            'monthlyTransaction' => BarclaysFile::totalCombinedFiles($monthlyPerDayBarclaysTransaction, $year, $month),
            'monthlyProcessed' => BarclaysFile::totalCombinedFiles($monthlyPerDayBarclaysProcessing, $year, $month),
            'yearlyTransaction' => BarclaysFile::totalCombinedFiles($yearlyBarclaysTransaction),
            'yearlyProcessed' => BarclaysFile::totalCombinedFiles($yearlyBarclaysProcessing),
            'allTransaction' => BarclaysFile::totalCombinedFiles($allBarclaysTransaction),
            'allProcessed' => BarclaysFile::totalCombinedFiles($allBarclaysProcessing),
        ];

        $monthlyLloydsFiles = $lloydsFileRepo->getMonthlyFiles($date);
        $monthlyPerDayLloydsReceived = LloydsFile::combineDailyReceived($monthlyLloydsFiles);
        $monthlyPerDayLloydsProcessing = LloydsFile::combineDailyProcessing($monthlyLloydsFiles);
        $monthlyPerDayLloydsBacs = LloydsFile::combineDailyBacs($monthlyLloydsFiles);
        $monthlyPerDayLloydsCreditBacs = LloydsFile::combineDailyCreditBacs($monthlyLloydsFiles);
        $monthlyPerDayLloydsDebitBacs = LloydsFile::combineDailyDebitBacs($monthlyLloydsFiles);

        $yearlyLloydsFiles = $lloydsFileRepo->getYearlyFilesToDate($date);
        $yearlyPerDayLloydsReceived = LloydsFile::combineDailyReceived($yearlyLloydsFiles);
        $yearlyPerDayLloydsProcessing = LloydsFile::combineDailyProcessing($yearlyLloydsFiles);
        $yearlyPerDayLloydsBacs = LloydsFile::combineDailyBacs($monthlyLloydsFiles);

        $allLloydsFiles = $lloydsFileRepo->getAllFilesToDate($date);
        $allLloydsReceived = LloydsFile::combineDailyReceived($allLloydsFiles);
        $allLloydsProcessing = LloydsFile::combineDailyProcessing($allLloydsFiles);
        $allLloydsBacs = LloydsFile::combineDailyBacs($allLloydsFiles);

        $lloyds = [
            'dailyReceived' => $monthlyPerDayLloydsReceived,
            'dailyProcessed' => $monthlyPerDayLloydsProcessing,
            'dailyCreditBacs' => $monthlyPerDayLloydsCreditBacs,
            'dailyDebitBacs' => $monthlyPerDayLloydsDebitBacs,
            'monthlyReceived' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsReceived, $year, $month),
            'monthlyProcessed' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsProcessing, $year, $month),
            'monthlyBacs' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsBacs, $year, $month),
            'yearlyReceived' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsReceived),
            'yearlyProcessed' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsProcessing),
            'yearlyBacs' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsBacs),
            'allReceived' => LloydsFile::totalCombinedFiles($allLloydsReceived),
            'allProcessed' => LloydsFile::totalCombinedFiles($allLloydsProcessing),
            'allBacs' => LloydsFile::totalCombinedFiles($allLloydsBacs),
        ];

        return [
            'judoForm' => $judoForm->createView(),
            'barclaysForm' => $barclaysForm->createView(),
            'barclaysStatementForm' => $barclaysStatementForm->createView(),
            'lloydsForm' => $lloydsForm->createView(),
            'reconciliationForm' => $reconciliationForm->createView(),
            'year' => $year,
            'month' => $month,
            'days_in_month' => cal_days_in_month(CAL_GREGORIAN, $month, $year),
            'lloyds' => $lloyds,
            'barclays' => $barclays,
            'sosure' => $sosure,
            'reconciliation' => $reconciliation,
            'salva' => $salva,
            'judo' => $judo,
            'judoFiles' => $monthlyJudoFiles,
            'barclaysFiles' => $monthlyBarclaysFiles,
            'barclaysStatementFiles' => $barclaysStatementFileRepo->getMonthlyFiles($date),
            'lloydsFiles' => $monthlyLloydsFiles,
            'bacsInputFiles' => $inputRepo->getMonthlyFiles($date),
            'bacsAruddFiles' => $aruddRepo->getMonthlyFiles($date),
            'bacsDdicFiles' => $ddicRepo->getMonthlyFiles($date),
            'reconcilationFiles' => $reconcilationFileRepo->getMonthlyFiles($date),
            'payments' => $payments,
        ];
    }

    /**
     * @Route("/charge", name="admin_charge")
     * @Template
     */
    public function chargeAction(Request $request)
    {
        $form = $this->createForm(ChargeReportType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $date = $form->get('date')->getData();
            $type = $data['type'];
            if ($type == 'all') {
                $type = null;
            }
        } else {
            $date = \DateTime::createFromFormat('U', time());
            $type = null;
        }

        $year = $date->format('Y');
        $month = $date->format('m');

        $dm = $this->getManager();
        $repo = $dm->getRepository(Charge::class);
        $charges = $repo->findMonthly($date, $type);
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
            'form' => $form->createView()
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
            'descriptions' => Feature::$descriptions,
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
                'success',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_feature_flags'));
    }

    /**
     * @Route("/cashback/{id}", name="admin_cashback_action", requirements={"id":"[0-9a-f]{24,24}"})
     * @Method({"POST"})
     */
    public function adminCashbackActionAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('_token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Cashback::class);
        $cashback = $repo->find($id);
        if (!$cashback) {
            throw $this->createNotFoundException('Cashback not found');
        }

        $policyService = $this->get('app.policy');
        $policyService->updateCashback($cashback, $request->get('status'));
        $this->addFlash(
            'success',
            sprintf('Set %s cashback to %s', $cashback->getPolicy()->getPolicyNumber(), $cashback->getStatus())
        );

        return new RedirectResponse($request->get('return_url'));
    }

    /**
     * @Route("/cashback", name="admin_cashback")
     * @Route("/cashback/{year}/{month}", name="admin_cashback_date")
     * @Template
     */
    public function cashbackAction(Request $request, $year = null, $month = null)
    {
        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));
        $nextMonth = clone $date;
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        $dm = $this->getManager();
        $repo = $dm->getRepository(Cashback::class);
        $qb = $repo->createQueryBuilder();
        $qb = $qb->field('date')->gte($date);
        $qb = $qb->field('date')->lt($nextMonth);

        $cashbackSearchForm = $this->get('form.factory')
            ->createNamedBuilder('search_form', CashbackSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $cashbackSearchForm->handleRequest($request);
        $data = $cashbackSearchForm->get('status')->getData();

        $qb = $qb->field('status')->in($data);

        return [
            'year' => $year,
            'month' => $month,
            'cashback' => $qb->getQuery()->execute(),
            'cashback_search_form' => $cashbackSearchForm->createView(),
        ];
    }

    /**
     * @Route("/chargeback", name="admin_chargeback")
     * @Route("/chargeback/{year}/{month}", name="admin_chargeback_date")
     * @Template
     */
    public function chargebackAction(Request $request, $year = null, $month = null)
    {
        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }

        $dm = $this->getManager();
        $chargeback = new ChargebackPayment();
        $chargeback->setSource(Payment::SOURCE_ADMIN);
        $chargebackForm = $this->get('form.factory')
            ->createNamedBuilder('chargeback_form', ChargebackType::class, $chargeback)
            ->getForm();

        if ($request->request->has('chargeback_form')) {
            $chargebackForm->handleRequest($request);
            if ($chargebackForm->isValid()) {
                $chargeback->setAmount(0 - abs($chargeback->getAmount()));
                $dm->persist($chargeback);
                $dm->flush();
                $this->addFlash(
                    'success',
                    sprintf('Added chargeback %s', $chargeback->getReference())
                );
            } else {
                $this->addFlash(
                    'error',
                    'Error adding chargeback'
                );
            }
        }

        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));
        $nextMonth = clone $date;
        $nextMonth = $nextMonth->add(new \DateInterval('P1M'));

        $repo = $dm->getRepository(ChargebackPayment::class);
        $qb = $repo->createQueryBuilder();
        if ($request->get('_route') == 'admin_chargeback_date') {
            $qb = $qb->field('date')->gte($this->startOfDay($date));
            $qb = $qb->field('date')->lt($this->startOfDay($nextMonth));
        } else {
            $qb = $qb->field('policy')->equals(null);
        }

        return [
            'year' => $year,
            'month' => $month,
            'chargebacks' => $qb->getQuery()->execute(),
            'chargeback_form' => $chargebackForm->createView(),
        ];
    }

    /**
     * @Route("/salva-requeue/{id}", name="salva_requeue_form")
     * @Template
     */
    public function salvaRequeueFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $salvaRequeueForm = $this->get('form.factory')
            ->createNamedBuilder('salva_requeue_form', SalvaRequeueType::class)
            ->setAction($this->generateUrl(
                'salva_requeue_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('salva_requeue_form')) {
                $salvaRequeueForm->handleRequest($request);
                if ($salvaRequeueForm->isValid()) {
                    /** @var SalvaExportService $salvaService */
                    $salvaService = $this->get('app.salva');

                    $result = $salvaService->queue($policy, $salvaRequeueForm->getData()['reason']);

                    if ($result) {
                        $this->addFlash(
                            'success',
                            sprintf('Sucessfully requeued salva policy: %s', $policy->getPolicyNumber())
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            sprintf('Could not requeue salva policy: %s', $policy->getPolicyNumber())
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $salvaRequeueForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/salva-status/{id}", name="salva_status_form")
     * @Template
     */
    public function salvaStatusFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(SalvaPhonePolicy::class);
        /** @var SalvaPhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $salvaRequeueForm = $this->get('form.factory')
            ->createNamedBuilder('salva_status_form', SalvaStatusType::class, $policy)
            ->setAction($this->generateUrl(
                'salva_status_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('salva_status_form')) {
                $salvaRequeueForm->handleRequest($request);
                if ($salvaRequeueForm->isValid()) {
                    $this->addFlash(
                        'success',
                        sprintf('Changed salva status to %s', $policy->getSalvaStatus())
                    );

                    $dm->flush();
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $salvaRequeueForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/policy-status/{id}", name="policy_status_form")
     * @Template
     */
    public function policyStatusFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $policyStatusForm = $this->get('form.factory')
            ->createNamedBuilder('policy_status_form', PolicyStatusType::class, $policy)
            ->setAction($this->generateUrl(
                'policy_status_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('policy_status_form')) {
                $policyStatusForm->handleRequest($request);
                if ($policyStatusForm->isValid()) {
                    $this->addFlash(
                        'success',
                        sprintf('Changed policy status to %s', $policy->getStatus())
                    );

                    $dm->flush();
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $policyStatusForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/policy-validation", name="policy_validation")
     * @Template
     */
    public function policyValidationAction(Request $request)
    {
        /** @var DocumentManager $dm */
        $dm = $this->getManager();

        /** @var Client $redis */
        $redis = $this->get("snc_redis.default");

        /** @var PolicyRepository $repo */
        $repo = $dm->getRepository(Policy::class);

        if ('POST' === $request->getMethod()) {
            if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
                throw new \InvalidArgumentException('Invalid csrf token');
            }

            /** @var Policy $policy */
            $policy = $repo->find($request->get('id'));

            if (!$policy) {
                throw $this->createNotFoundException(sprintf('Policy %s not found', $request->get('id')));
            }

            $this->addFlash('success', sprintf(
                'Policy %s successfully removed',
                $policy->getPolicyNumber()
            ));

            $redis->del($policy->getId());

            return $this->redirectToRoute('policy_validation');
        }

        $policies = $repo->findAll();

        $policiesForValidation = [];
        $validationErrors = [];

        /** @var Policy $policy */
        foreach ($policies as $policy) {
            if ($redis->exists($policy->getId())) {
                $policiesForValidation[$policy->getId()] = $policy;
                $validationErrors[$policy->getId()] = explode(';', $redis->get($policy->getId()));

                array_pop($validationErrors[$policy->getId()]);
            }
        }

        return [
            'policies' => $policiesForValidation,
            'policyValidationErrors' => $validationErrors
        ];
    }
}
