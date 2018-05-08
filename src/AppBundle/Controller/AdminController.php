<?php

namespace AppBundle\Controller;

use AppBundle\Document\ArrayToApiArrayTrait;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\Sequence;
use AppBundle\Form\Type\BacsMandatesType;
use AppBundle\Form\Type\BacsUploadFileType;
use AppBundle\Form\Type\SequenceType;
use AppBundle\Repository\File\BarclaysFileRepository;
use AppBundle\Repository\File\BarclaysStatementFileRepository;
use AppBundle\Repository\File\JudoFileRepository;
use AppBundle\Repository\File\LloydsFileRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\SequenceService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
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
use AppBundle\Document\OptOut\OptOut;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\OptOut\SmsOptOut;
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
            $to = null;
            if ($request->get('to')) {
                $to = new \DateTime($request->get('to'), new \DateTimeZone(SoSure::TIMEZONE));
            }
            $notes = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'notes'), 1500);
            try {
                $phone->changePrice($gwp, $from, $to, $notes);
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());

                return new RedirectResponse($this->generateUrl('admin_phones'));
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
        if (!$claim) {
            throw $this->createNotFoundException('Claim not found');
        }

        $phone = $phoneRepo->find($request->get('phone'));
        if ($claim && $phone) {
            $claim->setReplacementPhone($phone);
            $dm->flush();
        }
        return $this->redirectToRoute('admin_claims');
    }

    /**
     * @Route("/claims/update-claim/{route}", name="admin_claims_update_claim")
     * @Route("/claims/update-claim/{route}/{policyId}", name="admin_claims_update_claim_policy")
     * @Method({"POST"})
     */
    public function adminClaimsUpdateClaimAction(Request $request, $route = null, $policyId = null)
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

        if ($request->get('change-claim-type') && $request->get('claim-type')) {
            $claim->setType($request->get('claim-type'), true);
        }
        if ($request->get('change-approved-date') && $request->get('approved-date')) {
            $date = new \DateTime($request->get('approved-date'));
            $claim->setApprovedDate($date);
        }
        if ($request->get('update-replacement-phone') && $request->get('replacement-phone')) {
            $phoneRepo = $dm->getRepository(Phone::class);
            $phone = $phoneRepo->find($request->get('replacement-phone'));
            if ($phone) {
                $claim->setReplacementPhone($phone);
            }
        }
        $dm->flush();

        if ($policyId) {
            return $this->redirectToRoute($route, ['id' => $policyId]);
        } elseif ($route) {
            return $this->redirectToRoute($route);
        } else {
            return $this->redirectToRoute('admin_claims');
        }
    }

    /**
     * @Route("/accounts/print/{year}/{month}", name="admin_accounts_print")
     */
    public function adminAccountsPrintAction($year, $month)
    {
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $templating = $this->get('templating');
        $snappyPdf = $this->get('knp_snappy.pdf');
        $snappyPdf->setOption('orientation', 'Portrait');
        $snappyPdf->setOption('page-size', 'A4');
        $reportingService = $this->get('app.reporting');
        $html = $templating->render('AppBundle:Pdf:adminAccounts.html.twig', [
            'year' => $year,
            'month' => $month,
            'paymentTotals' => $reportingService->getAllPaymentTotals($this->isProduction(), $date),
            'activePolicies' => $reportingService->getActivePoliciesCount($date),
            'activePoliciesWithDiscount' => $reportingService->getActivePoliciesWithPolicyDiscountCount($date),
            'rewardPotLiability' => $reportingService->getRewardPotLiability($date),
            'rewardPromoPotLiability' => $reportingService->getRewardPotLiability($date, true),
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
        ];
    }

    /**
     * @Route("/bacs", name="admin_bacs")
     * @Route("/bacs/{year}/{month}", name="admin_bacs_date", requirements={"year":"[0-9]{4,4}","month":"[0-9]{1,2}"})
     * @Template
     */
    public function bacsAction(Request $request, $year = null, $month = null)
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
        $paymentsRepo = $dm->getRepository(BacsPayment::class);
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
            ->createNamedBuilder('upload', BacsUploadFileType::class)
            ->getForm();
        $uploadSubmissionForm = $this->get('form.factory')
            ->createNamedBuilder('uploadSubmission', BacsUploadFileType::class)
            ->getForm();
        $mandatesForm = $this->get('form.factory')
            ->createNamedBuilder('mandates', BacsMandatesType::class)
            ->getForm();
        $sequenceData = new \AppBundle\Document\Form\Sequence();
        $sequenceData->setSeq($currentSequence->getSeq());
        $sequenceForm = $this->get('form.factory')
            ->createNamedBuilder('sequence', SequenceType::class, $sequenceData)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('upload')) {
                $uploadForm->handleRequest($request);
                if ($uploadForm->isSubmitted() && $uploadForm->isValid()) {
                    $file = $uploadForm->getData()['file'];
                    if ($bacs->processUpload($file)) {
                        $this->addFlash(
                            'success',
                            'Successfully uploaded & processed file'
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
            } elseif ($request->request->has('uploadSubmission')) {
                $uploadSubmissionForm->handleRequest($request);
                if ($uploadSubmissionForm->isSubmitted() && $uploadSubmissionForm->isValid()) {
                    $file = $uploadSubmissionForm->getData()['file'];
                    if ($bacs->processSubmissionUpload($file)) {
                        $this->addFlash(
                            'success',
                            'Successfully uploaded & submitted bacs submission file'
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
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'files' => $s3FileRepo->getAllFiles($date, 'accesspay'),
            'addacs' => $s3FileRepo->getAllFiles($date, 'bacsReportAddacs'),
            'auddis' => $s3FileRepo->getAllFiles($date, 'bacsReportAuddis'),
            'input' => $s3FileRepo->getAllFiles($date, 'bacsReportInput'),
            'payments' => $paymentsRepo->findPayments($date),
            'uploadForm' => $uploadForm->createView(),
            'uploadSubmissionForm' => $uploadSubmissionForm->createView(),
            'mandatesForm' => $mandatesForm->createView(),
            'sequenceForm' => $sequenceForm->createView(),
            'currentSequence' => $currentSequence,
        ];
    }

    /**
     * @Route("/bacs/file/{id}", name="admin_bacs_file")
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
     * @Route("/bacs/serial-number-details/{serial}", name="admin_bacs_serial_number_details")
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
     * @Route("/bacs/submit/{id}", name="admin_bacs_submit")
     * @Route("/bacs/cancel/{id}", name="admin_bacs_cancel")
     * @Route("/bacs/serial-number/{id}", name="admin_bacs_update_serial_number")
     * @Method({"POST"})
     */
    public function bacsEditFileAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(AccessPayFile::class);
        /** @var AccessPayFile $file */
        $file = $repo->find($id);
        if ($file) {
            $message = 'Unknown';
            if ($request->get('_route') == 'admin_bacs_submit') {
                $file->setStatus(AccessPayFile::STATUS_SUBMITTED);
                $paymentRepo = $dm->getRepository(BacsPayment::class);

                $payments = $paymentRepo->findBy([
                    'serialNumber' => $file->getSerialNumber(),
                    'status' => BacsPayment::STATUS_GENERATED
                ]);
                foreach ($payments as $payment) {
                    $payment->setStatus(BacsPayment::STATUS_SUBMITTED);
                    $payment->submit();
                }

                $message = sprintf('Bacs file %s is marked as submitted', $file->getFileName());
            } elseif ($request->get('_route') == 'admin_bacs_cancel') {
                $file->setStatus(AccessPayFile::STATUS_CANCELLED);
                $paymentRepo = $dm->getRepository(BacsPayment::class);

                $message = sprintf('Bacs file %s is marked as cancelled', $file->getFileName());
            } elseif ($request->get('_route') == 'admin_bacs_update_serial_number') {
                $paymentRepo = $dm->getRepository(BacsPayment::class);
                $payments = $paymentRepo->findBy([
                    'serialNumber' => $file->getSerialNumber(),
                    'status' => BacsPayment::STATUS_GENERATED
                ]);
                foreach ($payments as $payment) {
                    $payment->setSerialNumber($request->get('serialNumber'));
                }

                $file->setSerialNumber($request->get('serialNumber'));
                $metadata = $file->getMetadata();
                $metadata['serial-number'] = $file->getSerialNumber();
                $file->setMetadata($metadata);
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
     * @Route("/bacs/approve/{id}", name="admin_bacs_approve")
     * @Route("/bacs/reject/{id}", name="admin_bacs_reject")
     * @Method({"POST"})
     */
    public function bacsPaymentAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(BacsPayment::class);
        /** @var BacsPayment $payment */
        $payment = $repo->find($id);
        if ($payment) {
            $message = 'Unknown';
            if ($request->get('_route') == 'admin_bacs_approve') {
                $payment->approve();
                $message = 'Payment is approved';
            } elseif ($request->get('_route') == 'admin_bacs_reject') {
                $payment->reject();
                $message = 'Payment is rejected';
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
        set_time_limit(60);

        $now = new \DateTime();
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
        /** @var JudoFileRepository $judoFileRepo */
        $judoFileRepo = $dm->getRepository(JudoFile::class);
        /** @var BarclaysStatementFileRepository $barclaysStatementFileRepo */
        $barclaysStatementFileRepo = $dm->getRepository(BarclaysStatementFile::class);
        /** @var BarclaysFileRepository $barclaysFileRepo */
        $barclaysFileRepo = $dm->getRepository(BarclaysFile::class);
        /** @var LloydsFileRepository $lloydsFileRepo */
        $lloydsFileRepo = $dm->getRepository(LloydsFile::class);

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
            } elseif ($request->request->has('barclays_statement')) {
                $barclaysStatementForm->handleRequest($request);
                if ($barclaysStatementForm->isSubmitted() && $barclaysStatementForm->isValid()) {
                    $dm = $this->getManager();
                    $barclaysStatementFile->setBucket('admin.so-sure.com');
                    $barclaysStatementFile->setKeyFormat($this->getParameter('kernel.environment') . '/upload/%s');

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
            }
        }

        $payments = $paymentRepo->getAllPaymentsForExport($date);
        $isProd = $this->isProduction();
        $sosure = [
            'dailyTransaction' => Payment::dailyPayments($payments, $isProd, JudoPayment::class),
            'monthlyTransaction' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
        ];

        $monthlyJudoFiles = $judoFileRepo->getMonthJudoFiles($date);
        $monthlyPerDayJudoTransaction = JudoFile::combineDailyTransactions($monthlyJudoFiles);

        $yearlyJudoFiles = $judoFileRepo->getYearJudoFilesToDate($date);
        $yearlyPerDayJudoTransaction = JudoFile::combineDailyTransactions($yearlyJudoFiles);

        $allJudoFiles = $judoFileRepo->getAllJudoFilesToDate($date);
        $allJudoTransaction = JudoFile::combineDailyTransactions($allJudoFiles);

        $judo = [
            'dailyTransaction' => $monthlyPerDayJudoTransaction,
            'monthlyTransaction' => JudoFile::totalCombinedFiles($monthlyPerDayJudoTransaction, $year, $month),
            'yearlyTransaction' => JudoFile::totalCombinedFiles($yearlyPerDayJudoTransaction),
            'allTransaction' => JudoFile::totalCombinedFiles($allJudoTransaction),
        ];

        $monthlyBarclaysFiles = $barclaysFileRepo->getMonthBarclaysFiles($date);
        $monthlyPerDayBarclaysTransaction = BarclaysFile::combineDailyTransactions($monthlyBarclaysFiles);
        $monthlyPerDayBarclaysProcessing = BarclaysFile::combineDailyProcessing($monthlyBarclaysFiles);

        $yearlyBarclaysFiles = $barclaysFileRepo->getYearBarclaysFilesToDate($date);
        $yearlyBarclaysTransaction = BarclaysFile::combineDailyTransactions($yearlyBarclaysFiles);
        $yearlyBarclaysProcessing = BarclaysFile::combineDailyProcessing($yearlyBarclaysFiles);

        $allBarclaysFiles = $barclaysFileRepo->getAllBarclaysFilesToDate($date);
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

        $monthlyLloydsFiles = $lloydsFileRepo->getMonthLloydsFiles($date);
        $monthlyPerDayLloydsReceived = LloydsFile::combineDailyReceived($monthlyLloydsFiles);
        $monthlyPerDayLloydsProcessing = LloydsFile::combineDailyProcessing($monthlyLloydsFiles);

        $yearlyLloydsFiles = $lloydsFileRepo->getYearLloydsFilesToDate($date);
        $yearlyPerDayLloydsReceived = LloydsFile::combineDailyReceived($yearlyLloydsFiles);
        $yearlyPerDayLloydsProcessing = LloydsFile::combineDailyProcessing($yearlyLloydsFiles);

        $allLloydsFiles = $lloydsFileRepo->getAllLloydsFilesToDate($date);
        $allLloydsReceived = LloydsFile::combineDailyReceived($allLloydsFiles);
        $allLloydsProcessing = LloydsFile::combineDailyProcessing($allLloydsFiles);

        $lloyds = [
            'dailyReceived' => $monthlyPerDayLloydsReceived,
            'dailyProcessed' => $monthlyPerDayLloydsProcessing,
            'monthlyReceived' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsReceived, $year, $month),
            'monthlyProcessed' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsProcessing, $year, $month),
            'yearlyReceived' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsReceived),
            'yearlyProcessed' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsProcessing),
            'allReceived' => LloydsFile::totalCombinedFiles($allLloydsReceived),
            'allProcessed' => LloydsFile::totalCombinedFiles($allLloydsProcessing),
        ];

        return [
            'judoForm' => $judoForm->createView(),
            'barclaysForm' => $barclaysForm->createView(),
            'barclaysStatementForm' => $barclaysStatementForm->createView(),
            'lloydsForm' => $lloydsForm->createView(),
            'year' => $year,
            'month' => $month,
            'days_in_month' => cal_days_in_month(CAL_GREGORIAN, $month, $year),
            'lloyds' => $lloyds,
            'barclays' => $barclays,
            'sosure' => $sosure,
            'judo' => $judo,
            'judoFiles' => $monthlyJudoFiles,
            'barclaysFiles' => $monthlyBarclaysFiles,
            'barclaysStatementFiles' => $barclaysStatementFileRepo->getMonthBarclaysStatementFiles($date),
            'lloydsFiles' => $monthlyLloydsFiles,
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
        // TODO: Find default intent for forms. hack to add a second token with known intent
        if (!$this->isCsrfTokenValid('flags', $request->get('_csrf_token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

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
        ])) {
            throw new \Exception(
                'Claim can only be withdrawn if claim is in-review or pending-closed state'
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
        $now = new \DateTime();
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
        $now = new \DateTime();
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
}
