<?php

namespace AppBundle\Controller;

use AppBundle\Classes\Helvetia;
use AppBundle\Classes\Salva;
use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\ArrayToApiArrayTrait;
use AppBundle\Document\BacsPaymentMethod;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\File\BacsReportAruddFile;
use AppBundle\Document\File\BacsReportDdicFile;
use AppBundle\Document\File\BacsReportInputFile;
use AppBundle\Document\File\CashflowsFile;
use AppBundle\Document\File\CheckoutFile;
use AppBundle\Document\File\ReconciliationFile;
use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Document\Form\CardRefund;
use AppBundle\Document\Form\CreateScheduledPayment;
use AppBundle\Document\Payment\BacsIndemnityPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Sequence;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Exception\PolicyPhonePriceException;
use AppBundle\Exception\ValidationException;
use AppBundle\Form\Type\CashflowsFileType;
use AppBundle\Form\Type\ChargeReportType;
use AppBundle\Form\Type\BacsMandatesType;
use AppBundle\Form\Type\CardRefundType;
use AppBundle\Form\Type\CheckoutFileType;
use AppBundle\Form\Type\CreateScheduledPaymentType;
use AppBundle\Form\Type\PolicyStatusType;
use AppBundle\Form\Type\SalvaRequeueType;
use AppBundle\Form\Type\SalvaStatusType;
use AppBundle\Form\Type\UploadFileType;
use AppBundle\Form\Type\ReconciliationFileType;
use AppBundle\Form\Type\SequenceType;
use AppBundle\Repository\BacsIndemnityPaymentRepository;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\File\BacsReportAruddFileRepository;
use AppBundle\Repository\File\BacsReportDdicFileRepository;
use AppBundle\Repository\File\BacsReportInputFileRepository;
use AppBundle\Repository\File\BarclaysFileRepository;
use AppBundle\Repository\File\BarclaysStatementFileRepository;
use AppBundle\Repository\File\CashflowsFileRepository;
use AppBundle\Repository\File\CheckoutFileRepository;
use AppBundle\Repository\File\LloydsFileRepository;
use AppBundle\Repository\File\ReconcilationFileRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\UserRepository;
use AppBundle\Service\BacsService;
use AppBundle\Service\BarclaysService;
use AppBundle\Service\CashflowsService;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\ClaimsService;
use AppBundle\Service\JudopayService;
use AppBundle\Service\LloydsService;
use AppBundle\Service\MailerService;
use AppBundle\Service\ReportingService;
use AppBundle\Service\SalvaExportService;
use AppBundle\Service\SequenceService;
use AppBundle\Service\BankingService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Predis\Collection\Iterator\SortedSetKey;
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
     * @Route("/claims/delete", name="admin_claims_delete_claim")
     * @Method({"POST"})
     */
    public function adminClaimsDeleteClaim(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
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

        $claim->getPolicy()->addNoteDetails(
            sprintf('Manually deleted claim %s, %s', $claim->getNumber(), $claim->getId()),
            $this->getUser(),
            sprintf('Deleted claim %s', $claim->getNumber())
        );

        $dm->remove($claim);
        $dm->flush();
        $dm->clear();

        $this->addFlash('success', sprintf('Successfully removed claim number %s', $claim->getNumber()));

        return $this->redirectToRoute('admin_claims');
    }

    /**
     * @Route("/claims/process", name="admin_claims_process_claim")
     * @Method({"POST"})
     */
    public function adminClaimsProcessClaim(Request $request)
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

        /** @var ClaimsService $claimsService */
        $claimsService = $this->get('app.claims');
        if ($claimsService->processClaim($claim)) {
            $this->addFlash(
                'success',
                'Processed claim'
            );
        } else {
            $this->addFlash(
                'error',
                'Failed to process claim. Already processed? Or not settled?'
            );
        }

        return $this->redirectToRoute('admin_claims');
    }

    /**
     * @Route("/judo-refund/{id}", name="judo_refund_form")
     * @Template
     */
    public function judoRefundFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();

        /** @var PolicyRepository $repo */
        $repo = $dm->getRepository(Policy::class);

        /** @var Policy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $judoRefund = new CardRefund();
        $judoRefund->setPolicy($policy);
        $judoRefundForm = $this->get('form.factory')
            ->createNamedBuilder('judo_refund_form', CardRefundType::class, $judoRefund)
            ->setAction($this->generateUrl(
                'judo_refund_form',
                ['id' => $policy->getId()]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('judo_refund_form')) {
                $judoRefundForm->handleRequest($request);
                if ($judoRefundForm->isValid()) {
                    /** @var JudopayService $judopayService */
                    $judopayService = $this->get('app.judopay');

                    try {
                        $judopayService->refund(
                            $judoRefund->getPayment(),
                            $judoRefund->getAmount(),
                            $judoRefund->getTotalCommission(),
                            $judoRefund->getNotes()
                        );

                        $policy->addNoteDetails(
                            $judoRefund->getNotes(),
                            $this->getUser(),
                            'Judo Refund'
                        );

                        $dm->flush();

                        $this->addFlash(
                            'success',
                            sprintf('Successfully refunded payment of £%s', $judoRefund->getAmount())
                        );
                    } catch (\Exception $e) {
                        $this->addFlash(
                            'error',
                            sprintf('Error processing refund: %s', $e)
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'judo_refund_form' => $judoRefundForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/checkout-refund/{id}", name="checkout_refund_form")
     * @Template
     */
    public function checkoutRefundFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();

        /** @var PolicyRepository $repo */
        $repo = $dm->getRepository(Policy::class);

        /** @var Policy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $checkoutRefund = new CardRefund();
        $checkoutRefund->setPolicy($policy);
        $checkoutRefundForm = $this->get('form.factory')
            ->createNamedBuilder('checkout_refund_form', CardRefundType::class, $checkoutRefund)
            ->setAction($this->generateUrl(
                'checkout_refund_form',
                ['id' => $policy->getId()]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('checkout_refund_form')) {
                $checkoutRefundForm->handleRequest($request);
                if ($checkoutRefundForm->isValid()) {
                    /** @var CheckoutService $checkoutService */
                    $checkoutService = $this->get('app.checkout');

                    try {
                        $checkoutService->refund(
                            $checkoutRefund->getPayment(),
                            $checkoutRefund->getAmount(),
                            $checkoutRefund->getTotalCommission(),
                            $checkoutRefund->getNotes()
                        );

                        $policy->addNoteDetails(
                            $checkoutRefund->getNotes(),
                            $this->getUser(),
                            'Checkout Refund'
                        );

                        $dm->flush();

                        $this->addFlash(
                            'success',
                            sprintf('Successfully refunded payment of £%s', $checkoutRefund->getAmount())
                        );
                    } catch (\Exception $e) {
                        $this->addFlash(
                            'error',
                            sprintf('Error processing refund: %s', $e)
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'checkout_refund_form' => $checkoutRefundForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/create-scheduled-payment/{id}", name="create_scheduled_payment_form")
     * @Template
     */
    public function createScheduledPaymentFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        /** @var PolicyRepository $repo */
        $repo = $dm->getRepository(Policy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }
        // Make sure the person making the request has the right permission.
        // Create the form.
        $createScheduledPayment = new CreateScheduledPayment(
            self::getBankHolidays(),
            $policy->getActiveScheduledPayments()
        );
        $createScheduledPayment->setAmount($policy->getPremiumInstallmentPrice());
        $createScheduledPaymentForm = $this->get('form.factory')
            ->createNamedBuilder(
                'create_scheduled_payment_form',
                CreateScheduledPaymentType::class,
                $createScheduledPayment
            )
            ->setAction($this->generateUrl('create_scheduled_payment_form', ['id' => $policy->getId()]))
            ->getForm();
        // process the form.
        if ($request->getMethod() === 'POST') {
            if (!in_array($policy->getStatus(), [
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_PICSURE_REQUIRED
            ])) {
                $this->addFlash(
                    'error',
                    "Cannot schedule payments for an inactive policy"
                );
                return $this->redirectToRoute('admin_policy', ['id' => $id]);
            }
            if ($request->request->has('create_scheduled_payment_form')) {
                $createScheduledPaymentForm->handleRequest($request);
                $monthlyPremium = null;
                if ($createScheduledPaymentForm->isValid()) {
                    $date = new \DateTime($createScheduledPayment->getDate());
                    if ($policy->hasScheduledPaymentOnDate($date)) {
                        $this->addFlash(
                            'error',
                            sprintf(
                                'Payment already scheduled for %s, please use date picker to choose available date',
                                $date->format('l jS F Y')
                            )
                        );
                    } else {
                        $amount = $createScheduledPayment->getAmount();
                        $scheduledPayment = new ScheduledPayment();
                        $scheduledPayment->setScheduled($date);
                        $scheduledPayment->setNotes($createScheduledPayment->getNotes());
                        $scheduledPayment->setAmount($amount);
                        $scheduledPayment->setPolicy($policy);
                        $scheduledPayment->setStatus(ScheduledPayment::STATUS_SCHEDULED);

                        $addPolicyNote = true;

                        try {
                            $policy->addScheduledPayment($scheduledPayment);
                            $dm->flush();
                            $this->addFlash('success', "Payment scheduled successfully.");
                        } catch (\Exception $e) {
                            $this->addFlash(
                                'error',
                                sprintf("Could not scheduled payment: %s", $e->getMessage())
                            );
                            $addPolicyNote = false;
                        }
                        /**
                         * We also want to add the notes to the policy notes if the scheduled
                         * payment was successfully added.
                         */
                        if ($addPolicyNote) {
                            $policy->addNoteDetails(
                                sprintf(
                                    "Manually scheduled payment for %s. Justification: %s",
                                    $date->format('l jS F Y'),
                                    $createScheduledPayment->getNotes()
                                ),
                                $this->getUser()
                            );
                        }
                    }
                    $dm->flush();
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'create_scheduled_payment_form' => $createScheduledPaymentForm->createView(),
            'policy' => $policy,
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
        /** @var PhonePrice $price */
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY);
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
            $from = new \DateTime($request->get('from'), SoSure::getSoSureTimezone());
            $notes = $this->conformAlphanumericSpaceDot($this->getRequestString($request, 'notes'), 1500);
            $stream = $this->getRequestString($request, 'stream');
            try {
                $policyTerms = $this->getLatestPolicyTerms();
                $excess = $policyTerms->getDefaultExcess();
                $picsureExcess = $policyTerms->getDefaultPicSureExcess();
                if ($request->get('damage-excess')) {
                    $excess->setDamage($request->get('damage-excess'));
                }
                if ($request->get('warranty-excess')) {
                    $excess->setWarranty($request->get('warranty-excess'));
                }
                if ($request->get('extended-warranty-excess')) {
                    $excess->setExtendedWarranty($request->get('extended-warranty-excess'));
                }
                if ($request->get('loss-excess')) {
                    $excess->setLoss($request->get('loss-excess'));
                }
                if ($request->get('theft-excess')) {
                    $excess->setTheft($request->get('theft-excess'));
                }
                if ($request->get('picsure-damage-excess')) {
                    $picsureExcess->setDamage($request->get('picsure-damage-excess'));
                }
                if ($request->get('picsure-warranty-excess')) {
                    $picsureExcess->setWarranty($request->get('picsure-warranty-excess'));
                }
                if ($request->get('picsure-extended-warranty-excess')) {
                    $picsureExcess->setExtendedWarranty($request->get('picsure-extended-warranty-excess'));
                }
                if ($request->get('picsure-loss-excess')) {
                    $picsureExcess->setLoss($request->get('picsure-loss-excess'));
                }
                if ($request->get('picsure-theft-excess')) {
                    $picsureExcess->setTheft($request->get('picsure-theft-excess'));
                }
                $phone->changePrice($gwp, $from, $excess, $picsureExcess, $notes, $stream);
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());
                return new RedirectResponse($this->generateUrl('admin_phones'));
            }
            $dm->flush();
            $this->addFlash('success', 'Your changes were saved!');
            /** @var MailerService $mailer */
            $mailer = $this->get('app.mailer');
            $mailer->send(
                sprintf('Phone pricing update for %s', $phone),
                'marketing@so-sure.com',
                sprintf(
                    'On %s, the price for %s will be updated to £%0.2f (£%0.2f GWP) for "%s". Notes: %s',
                    $from->format(\DateTime::ATOM),
                    $phone,
                    $this->withIpt($gwp),
                    $gwp,
                    $stream,
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
     * Receives a request to update the retail price of a phone and acitons it.
     * @Route("/phone/{id}/retail", name="admin_phone_retail")
     * @Method({"POST"})
     */
    public function phoneUpdateRetailAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid CSRF');
        }
        $dm = $this->getManager();
        /** @var PhoneRepository $phoneRepository */
        $phoneRepository = $dm->getRepository(Phone::class);
        /** @var Phone */
        $phone = $phoneRepository->find($id);
        $price = $request->get('price');
        $url = $request->get('url');
        if ($price > 0 && $url) {
            $phone->addRetailPrice($price, $url, new \DateTime());
            $dm->persist($phone);
            $dm->flush();
            $this->addFlash('success', 'Successfully updated current retail price');
        } else {
            $this->addFlash('error', 'Could not update current retail price due to invalid parameters');
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
     * @Route("accounts-salva", name="admin_accounts_helvetia")
     * @Route("/accounts/{year}/{month}", name="admin_accounts_helvetia_date")
     * @Template
     */
    public function adminAccountsHelvetiaAction(Request $request, $year = null, $month = null)
    {
        $now = new \DateTime();
        $year = $year ?: $now->format('Y');
        $month = $month ?: $now->format('m');
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));
        $helvetiaForm = $this->get('form.factory')
            ->createNamedBuilder('salva_form')
            ->add('export', SubmitType::class)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('salva_form')) {
                $helvetiaForm->handleRequest($request);
                if ($helvetiaForm->isValid()) {
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
        /** @var S3FileRepository $s3FileRepo */
        $s3FileRepo = $dm->getRepository(S3File::class);
        /** @var ReportingService $reportingService */
        $reportingService = $this->get('app.reporting');
        $data = [
            'year' => $year,
            'month' => $month,
            'paymentTotals' => $reportingService->getAllPaymentTotals(
                $date,
                Helvetia::POLICY_TYPES,
                !$this->isProduction()
            ),
            'activePolicies' => $reportingService->getActivePoliciesCount($date),
            'activePoliciesWithDiscount' => $reportingService->getActivePoliciesWithPolicyDiscountCount($date),
            'rewardPotLiability' => $reportingService->getRewardPotLiability($date),
            'rewardPromoPotLiability' => $reportingService->getRewardPotLiability($date, true),
            'print' => false,
        ];
        $data = array_merge($data, [
            'files' => $s3FileRepo->getAllFiles($date),
            'salvaForm' => $helvetiaForm->createView(),
        ]);
        return $data;
    }

    /**
     * @Route("/accounts/print/{year}/{month}", name="admin_accounts_salva_print")
     */
    public function adminAccountsSalvaPrintAction($year, $month)
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
     * @Route("/accounts", name="admin_accounts_salva")
     * @Route("/accounts/{year}/{month}", name="admin_accounts_salva_date")
     * @Template
     */
    public function adminAccountsSalvaAction(Request $request, $year = null, $month = null)
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
        /** @var S3FileRepository $s3FileRepo */
        $s3FileRepo = $dm->getRepository(S3File::class);
        /** @var ReportingService $reportingService */
        $reportingService = $this->get('app.reporting');

        // 15 - 18 seconds
        $data = [
            'year' => $year,
            'month' => $month,
            'paymentTotals' => $reportingService->getAllPaymentTotals($this->isProduction(), $date),
            'activePolicies' => $reportingService->getActivePoliciesCount($date),
            'activePoliciesWithDiscount' => $reportingService->getActivePoliciesWithPolicyDiscountCount($date),
            'rewardPotLiability' => $reportingService->getRewardPotLiability($date),
            'rewardPromoPotLiability' => $reportingService->getRewardPotLiability($date, true),
            'stats' => $reportingService->getStats($date),
            'print' => false,
        ];
        //throw new \Exception(print_r($data, true));

        $data = array_merge($data, [
            'files' => $s3FileRepo->getAllFiles($date),
            'salvaForm' => $salvaForm->createView(),
        ]);

        return $data;
    }

    /**
     * @Route("/bacs", name="admin_bacs")
     * @Route("/bacs/{year}/{month}", name="admin_bacs_date", requirements={"year":"[0-9]{4,4}","month":"[0-9]{1,2}"})
     * @Route("/bacs/payments/{year}/{month}", name="admin_bacs_payments",
     *     requirements={"year":"[0-9]{4,4}","month":"[0-9]{1,2}"})
     * @Route("/bacs/reports/{year}/{month}", name="admin_bacs_reports",
     *     requirements={"year":"[0-9]{4,4}","month":"[0-9]{1,2}"})
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
                    $serialNumber = $mandatesForm->getData()['serialNumber'];
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

        $data = [
            'year' => $year,
            'month' => $month,
            'uploadForm' => $uploadForm->createView(),
            'uploadDebitForm' => $uploadDebitForm->createView(),
            'uploadCreditForm' => $uploadCreditForm->createView(),
            'mandatesForm' => $mandatesForm->createView(),
            'sequenceForm' => $sequenceForm->createView(),
            'approvePaymentsForm' => $approvePaymentsForm->createView(),
            'currentSequence' => $currentSequence,
            'outstandingMandates' => $userRepo->findPendingMandates()->getQuery()->execute()->count(),
            'files' => $s3FileRepo->getAllFiles($date, 'accesspay'),
            'paymentsIncPrevNextMonth' => $paymentsRepo->findPaymentsIncludingPreviousNextMonth($date),
            'inputIncPrevMonth' => $s3FileRepo->getAllFiles($date, 'bacsReportInput', true),
        ];

        if ($request->get('_route') == 'admin_bacs_payments') {
            $data = array_merge($data, [
                'indemnity' => $paymentsIndemnityRepo->findPayments($date),
                'payments' => $paymentsRepo->findPayments($date),
            ]);
        } elseif ($request->get('_route') == 'admin_bacs_reports') {
            $data = array_merge($data, [
                'addacs' => $s3FileRepo->getAllFiles($date, 'bacsReportAddacs'),
                'auddis' => $s3FileRepo->getAllFiles($date, 'bacsReportAuddis'),
                'arudds' => $s3FileRepo->getAllFiles($date, 'bacsReportArudd'),
                'ddic' => $s3FileRepo->getAllFiles($date, 'bacsReportDdic'),
                'input' => $s3FileRepo->getAllFiles($date, 'bacsReportInput'),
                'withdrawal' => $s3FileRepo->getAllFiles($date, 'bacsReportWithdrawal'),
            ]);
        }

        return $data;
    }

    /**
     * @Route("/file/delete/{id}", name="admin_file_delete")
     */
    public function deleteFileAction(Request $request, $id)
    {
        $referer = $request->headers->get('referer');

        $dm = $this->getManager();
        /** @var S3FileRepository $repo */
        $repo = $dm->getRepository(S3File::class);
        /** @var S3File $s3File */
        $s3File = $repo->find($id);
        if (!$s3File) {
            throw new NotFoundHttpException();
        }

        $dm->remove($s3File);
        $dm->flush();

        return $this->getSuccessJsonResponse("Deleted file");
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
        $paymentMethods = [];

        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policies = $repo->findBy(['paymentMethod.bankAccount.mandateSerialNumber' => (string) $serial]);
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $bankAccount = $policy->getBacsBankAccount();
            if ($bankAccount) {
                $paymentMethods[] = $bankAccount->toDetailsArray();
            }
        }

        $repo = $dm->getRepository(User::class);
        $users = $repo->findBy(['paymentMethod.bankAccount.mandateSerialNumber' => (string) $serial]);
        foreach ($users as $user) {
            /** @var User $user */
            $bankAccount = $user->getBacsBankAccount();
            if ($bankAccount) {
                $paymentMethods[] = $bankAccount->toDetailsArray();
            }
        }

        return new JsonResponse($paymentMethods);
    }

    /**
     * @Route("/bacs/file/submit/{id}", name="admin_bacs_submit")
     * @Route("/bacs/file/cancel/{id}", name="admin_bacs_cancel")
     * @Route("/bacs/file/serial/{id}", name="admin_bacs_update_serial_number")
     * @Route("/bacs/file/meta/{id}", name="admin_bacs_update_meta")
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
                try {
                    $count = $bacsService->bacsFileUpdateSerialNumber($file, $request->get('serialNumber'));
                    $message = sprintf(
                        'Bacs file %s serial number updated (%d payments updated)',
                        $file->getFileName(),
                        $count
                    );
                } catch (ValidationException $e) {
                    $this->addFlash('error', $e->getMessage());

                    return new RedirectResponse($this->generateUrl('admin_bacs'));
                }
            } elseif ($request->get('_route') == 'admin_bacs_update_meta') {
                $debit = $request->get('debit');
                $credit = $request->get('credit');
                if ($debit) {
                    $metadata = $file->getMetadata();
                    $metadata['debit-amount'] = $debit;
                    $file->setMetadata($metadata);
                }
                if ($credit) {
                    $metadata = $file->getMetadata();
                    $metadata['credit-amount'] = $credit;
                    $file->setMetadata($metadata);
                }
                $message = 'Updated metadata';
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
     * @Route("/banking/card/{year}/{month}", name="admin_banking_card_date")
     * @Route("/banking/merchant/{year}/{month}", name="admin_banking_merchant_date")
     * @Route("/banking/bacs/{year}/{month}", name="admin_banking_bacs_date")
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
        /** @var BacsReportInputFileRepository $inputRepo */
        $inputRepo = $dm->getRepository(BacsReportInputFile::class);
        /** @var BacsReportAruddFileRepository $aruddRepo */
        $aruddRepo = $dm->getRepository(BacsReportAruddFile::class);
        /** @var BacsReportDdicFileRepository $ddicRepo */
        $ddicRepo = $dm->getRepository(BacsReportDdicFile::class);
        /** @var BacsPaymentRepository $bacsPaymentRepo */
        $bacsPaymentRepo = $dm->getRepository(BacsPayment::class);

        $bacsPayments = $bacsPaymentRepo->findPayments($date)->toArray();
        $manualBacsPayments = array_filter($bacsPayments, function ($payment) {
            /** @var BacsPayment $payment */
            return $payment->isManual();
        });

        $bankingService = $this->get('app.banking');

        $sosure = $bankingService->getSoSureBanking($date);

        $checkoutFile = new CheckoutFile();
        $checkoutForm = $this->get('form.factory')
            ->createNamedBuilder('checkout', CheckoutFileType::class, $checkoutFile)
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
            if ($request->request->has('checkout')) {
                $checkoutForm->handleRequest($request);
                if ($checkoutForm->isSubmitted() && $checkoutForm->isValid()) {
                    $dm = $this->getManager();
                    $checkoutFile->setBucket(SoSure::S3_BUCKET_ADMIN);
                    $checkoutFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                    $checkoutService = $this->get('app.checkout');
                    $data = $checkoutService->processCsv($checkoutFile);

                    $dm->persist($checkoutFile);
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
                    $lloydsFile->setBucket(SoSure::S3_BUCKET_ADMIN);
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
                    $reconciliationFile->setBucket(SoSure::S3_BUCKET_ADMIN);
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
        $data = [
            'checkoutForm' => $checkoutForm->createView(),
            'lloydsForm' => $lloydsForm->createView(),
            'reconciliationForm' => $reconciliationForm->createView(),
            'dates' => $this->getYMD($year, $month),
            'salva' => $bankingService->getSalvaBanking($date, $year, $month),
            'sosure' => $sosure,
            'reconciliation' => $bankingService->getReconcilationBanking($date),
            'year' => $date->format('Y'),
            'month' => $date->format('n'),
            'checkout' => $bankingService->getCheckoutBanking($date, $year, $month),
            'cashflows' => $bankingService->getCashflowsBanking($date, $year, $month),
            'lloyds' => $bankingService->getLloydsBanking($date, $year, $month),
            'bacsInputFiles' => $inputRepo->getMonthlyFiles($date),
            'bacsAruddFiles' => $aruddRepo->getMonthlyFiles($date),
            'bacsDdicFiles' => $ddicRepo->getMonthlyFiles($date),
            'manualBacsPayments' => Payment::sumPayments($manualBacsPayments, false)
        ];
        return $data;
    }

    private function getYMD($year, $month, $daysInNextMonth = 3)
    {
        $ymd = [];
        $bacs = [];
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $ymd[$day] = sprintf('%d%02d%02d', $year, $month, $day);
            $date = \DateTime::createFromFormat('Ymd', $ymd[$day]);
            $reversed = $this->subBusinessDays($date, BacsPayment::DAYS_CREDIT);
            $bacs[$day] = $reversed->format('Ymd');
        }

        $nextMonth = $month + 1;
        $nextMonthYear = $year;
        if ($nextMonth > 12) {
            $nextMonth = 1;
            $nextMonthYear += 1;
        }
        $nextMonthYMD = [];
        for ($day = 1; $day <= $daysInNextMonth; $day++) {
            $nextMonthYMD[$day] = sprintf('%d%02d%02d', $nextMonthYear, $nextMonth, $day);
        }

        return [
            'year' => $year,
            'month' => $month,
            'ym' => sprintf('%d%02d', $year, $month),
            'ymd' => $ymd,
            'next_ymd' => $nextMonthYMD,
            'bacs' => $bacs,
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
        $date = $this->startOfDay($date);
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
     * @Route("/policy-validation", name="admin_policy_validation")
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

            if ($request->request->has('flag-redis-policy')) {
                if ($request->get('flag-redis-policy') == 'remove') {
                    $redis->srem('policy:validation:flags', $policy->getId());

                    $this->addFlash('success', sprintf(
                        'Unflagged policy %s',
                        $policy->getPolicyNumber()
                    ));
                } else {
                    $redis->sadd('policy:validation:flags', $policy->getId());

                    $this->addFlash('success', sprintf(
                        'Flagged policy %s',
                        $policy->getPolicyNumber()
                    ));
                }
            }

            if ($request->request->has('delete-redis-policy')) {
                $pattern = '*' . $policy->getId() . '*';

                foreach (new SortedSetKey($redis, 'policy:validation', $pattern) as $member => $rank) {
                    $redis->zrem('policy:validation', $member);
                }

                $redis->srem('policy:validation:flags', $policy->getId());

                $this->addFlash('success', sprintf(
                    'Policy %s removed from redis',
                    $policy->getPolicyNumber()
                ));
            }

            return $this->redirectToRoute('admin_policy_validation');
        }

        return [
            'validation' => $redis->zrange('policy:validation', 0, -1),
            'flags' => $redis->smembers('policy:validation:flags'),
        ];
    }
}
