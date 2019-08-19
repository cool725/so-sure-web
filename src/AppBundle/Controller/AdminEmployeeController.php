<?php

namespace AppBundle\Controller;

use AppBundle\Document\AffiliateCompany;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\Form\InvalidImei;
use AppBundle\Document\Form\PicSureStatus;
use AppBundle\Document\Form\SerialNumber;
use AppBundle\Document\PaymentMethod\CheckoutPaymentMethod;
use AppBundle\Document\Promotion;
use AppBundle\Document\Participation;
use AppBundle\Document\PaymentMethod\BacsPaymentMethod;
use AppBundle\Document\File\PaymentRequestUploadFile;
use AppBundle\Document\Note\CallNote;
use AppBundle\Document\Note\Note;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Exception\PaymentDeclinedException;
use AppBundle\Form\Type\AdminEmailOptOutType;
use AppBundle\Form\Type\AffiliateType;
use AppBundle\Form\Type\BacsCreditType;
use AppBundle\Form\Type\BacsPaymentRequestType;
use AppBundle\Form\Type\ClaimInfoType;
use AppBundle\Form\Type\CallNoteType;
use AppBundle\Form\Type\DetectedImeiType;
use AppBundle\Form\Type\InvalidImeiType;
use AppBundle\Form\Type\LinkClaimType;
use AppBundle\Form\Type\ClaimNoteType;
use AppBundle\Form\Type\PaymentRequestUploadFileType;
use AppBundle\Form\Type\PicSureStatusType;
use AppBundle\Form\Type\SerialNumberType;
use AppBundle\Form\Type\UploadFileType;
use AppBundle\Form\Type\UserHandlingTeamType;
use AppBundle\Form\Type\PromotionType;
use AppBundle\Form\Type\RewardType;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Security\FOSUBUserProvider;
use AppBundle\Service\BacsService;
use AppBundle\Service\CheckoutService;
use AppBundle\Service\FraudService;
use AppBundle\Service\JudopayService;
use AppBundle\Service\PolicyService;
use AppBundle\Service\ReceperioService;
use AppBundle\Service\ReportingService;
use AppBundle\Service\RouterService;
use AppBundle\Service\SalvaExportService;
use AppBundle\Service\AffiliateService;
use Doctrine\ODM\MongoDB\Query\Builder;
use Faker\Calculator\Luhn;
use Gedmo\Loggable\Document\Repository\LogEntryRepository;
use Grpc\Call;
use Predis\Client;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Form;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Validator\Constraints as Assert;
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
use AppBundle\Document\CustomerCompany;
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
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\SmsOptOut;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\JudoFile;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\File\LloydsFile;
use AppBundle\Document\File\ImeiUploadFile;
use AppBundle\Document\File\ScreenUploadFile;
use AppBundle\Document\File\ManualAffiliateFile;
use AppBundle\Document\Form\Cancel;
use AppBundle\Document\Form\Imei;
use AppBundle\Document\Form\BillingDay;
use AppBundle\Document\Form\Chargebacks;
use AppBundle\Document\Form\CreateReward;
use AppBundle\Form\Type\AddressType;
use AppBundle\Form\Type\ManualAffiliateFileType;
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
use AppBundle\Form\Type\AdminSmsOptOutType;
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
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;
use MongoRegex;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use CensusBundle\Document\Postcode;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\Validator\Constraints\Choice;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_EMPLOYEE')")
 */
class AdminEmployeeController extends BaseController implements ContainerAwareInterface
{
    use DateTrait;
    use CurrencyTrait;
    use ImeiTrait;
    use ContainerAwareTrait;
    use ValidatorTrait;

    /**
     * @Route("", name="admin_home")
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
        $phoneService = $this->get('app.phone');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $makes = $repo->findActiveMakes();
        $phones = $repo->createQueryBuilder();
        $phones = $phones->field('make')->notEqual('ALL');

        $policyTerms = $this->getLatestPolicyTerms();
        $excess = $policyTerms->getDefaultExcess();
        $picsureExcess = $policyTerms->getDefaultPicSureExcess();

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
        $rootDir = $this->getParameter('kernel.root_dir');
        $additionalPhonesForm = $this->get('form.factory')
            ->createNamedBuilder('additional_phones_form')
            ->add('file', ChoiceType::class, [
                'required' => true,
                'choices' => $phoneService->getAdditionalPhones($rootDir),
            ])
            ->add('load', SubmitType::class)
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
            } elseif ($request->request->has('additional_phones_form')) {
                if ($this->getUser()->hasRole('ROLE_ADMIN')) {
                    $additionalPhonesForm->handleRequest($request);
                    if ($additionalPhonesForm->isValid()) {
                        $additionalPhones = $phoneService->getAdditionalPhonesInstance(
                            $additionalPhonesForm->get('file')->getData()
                        );
                        if ($additionalPhones !== null) {
                            $additionalPhones->setContainer($this->container);
                            $additionalPhones->load($dm);

                            $this->addFlash('success', sprintf(
                                'Loaded additional phones: %s',
                                $additionalPhonesForm->get('file')->getData()
                            ));
                        } else {
                            $this->addFlash('error', sprintf(
                                'Error loading additional phones: %s',
                                $additionalPhonesForm->get('file')->getData()
                            ));
                        }
                    }
                } else {
                    $this->addFlash(
                        'error',
                        'You don\'t have the permissions to load additional phones'
                    );
                }

                return new RedirectResponse($this->generateUrl('admin_phones'));
            }
        }

        $searchForm->handleRequest($request);
        $data = $searchForm->get('os')->getData();
        $phones = $phones->field('os')->in($data);
        $data = filter_var($searchForm->get('active')->getData(), FILTER_VALIDATE_BOOLEAN);
        $phones = $phones->field('active')->equals($data);
        $rules = $searchForm->get('rules')->getData();
        $make = $searchForm->get('make')->getData();
        $model = $searchForm->get('model')->getData();
        if ($rules == 'missing') {
            $phones = $phones->field('suggestedReplacement')->exists(false);
            $phones = $phones->field('replacementPrice')->lte(0);
        } elseif ($rules == 'retired') {
            $retired = \DateTime::createFromFormat('U', time());
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
            $year = \DateTime::createFromFormat('U', time());
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
        if ($make) {
            $phones->field('makeCanonical')->equals(mb_strtolower($make));
        }
        if ($model) {
            // regexp to search for each word so you don't have to get the model exactly right.
            $words = explode(' ', $model);
            $wordString = '';
            foreach ($words as $word) {
                $wordString .= "(?=.*?\b{$word}\b)";
            }
            $phones->field('model')->equals(new MongoRegex("/^{$wordString}.*$/i"));
        }
        $phones = $phones->sort('make', 'asc');
        $phones = $phones->sort('model', 'asc');
        $phones = $phones->sort('memory', 'asc');
        $pager = $this->pager($request, $phones);

        $now = \DateTime::createFromFormat('U', time());
        $oneDay = $this->addBusinessDays($now, 1);


        return [
            'phones' => $pager->getCurrentPageResults(),
            'form' => $searchForm->createView(),
            'pager' => $pager,
            'new_phone' => $newPhoneForm->createView(),
            'makes' => $makes,
            'additional_phones' => $additionalPhonesForm->createView(),
            'one_day' => $oneDay,
            'policyTerms' => $this->getLatestPolicyTerms(),
            'excess' => $excess,
            'picsureExcess' => $picsureExcess
        ];
    }

    /**
     * @Route("/phones/download", name="admin_phones_download")
     */
    public function adminPhonesDownload()
    {
        /** @var RouterService $router */
        $router = $this->get('app.router');
        /** @var PhoneRepository $repo */
        $repo = $this->getManager()->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();

        $lines = [];
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            $lines[] = sprintf(
                '"%s", "%s"',
                $phone->__toString(),
                $router->generateUrl('purchase_phone_make_model_memory', [
                    'make' => $phone->getMake(),
                    'model' => $phone->getEncodedModel(),
                    'memory' => $phone->getMemory()
                ])
            );
        }
        $data = implode(PHP_EOL, $lines);

        $tmpFile = sprintf('%s/%s', sys_get_temp_dir(), 'so-sure-phones.csv');
        file_put_contents($tmpFile, $data);

        $headers = [
            'Content-Type' => 'text/plain',
            'Content-Disposition' => 'attachment; filename="so-sure-phones.csv"',
        ];

        return StreamedResponse::create(
            function () use ($tmpFile) {
                $stream = fopen($tmpFile, 'r');
                echo stream_get_contents($stream);
                flush();
            },
            200,
            $headers
        );
    }

    /**
     * @Route("/policies", name="admin_policies")
     * @Template("AppBundle::Claims/claimsPolicies.html.twig")
     */
    public function adminPoliciesAction(Request $request)
    {
        $callNote = new \AppBundle\Document\Form\CallNote();
        $callNote->setUser($this->getUser());
        $callForm = $this->get('form.factory')
            ->createNamedBuilder('call_form', CallNoteType::class, $callNote)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('call_form')) {
                $callForm->handleRequest($request);
                if ($callForm->isValid()) {
                    /** @var PolicyRepository $repo */
                    $repo = $this->getManager()->getRepository(Policy::class);
                    /** @var Policy $policy */
                    $policy = $repo->find($callNote->getPolicyId());
                    if ($policy) {
                        $policy->addNotesList($callNote->toCallNote());
                        $this->getManager()->flush();
                        $this->addFlash('success', 'Recorded call');
                    } else {
                        $this->addFlash('error', 'Unable to record call');
                    }
                    return new RedirectResponse($request->getUri());
                }
            }
        }
        try {
            $data = $this->searchPolicies($request);
        } catch (RedirectException $e) {
            return new RedirectResponse($e->getMessage());
        }
        return array_merge($data, [
            'policy_route' => 'admin_policy',
            'call_form' => $callForm->createView(),
            'periods' => [
                'Week '.(new \DateTime('-1 week'))->format('W') => 'week-1',
                'Week '.(new \DateTime('-2 week'))->format('W') => 'week-2',
                'Week '.(new \DateTime('-3 week'))->format('W') => 'week-3',
                $this->monthName('-1 month') => 'month-1',
                $this->monthName('-2 month') => 'month-2',
                $this->monthName('-3 month') => 'month-3'
            ]
        ]);
    }

    /**
     * @Route("/policies/called-list", name="admin_policies_called_list")
     */
    public function adminPoliciesCalledListAction(Request $request)
    {
        $period = mb_split('-', $request->query->get('period'));
        $periodType = $period[0];
        $periodNumber = $period[1];
        $start = null;
        $end = null;
        $response = new StreamedResponse();
        if ($periodType == 'week') {
            $start = $this->startOfWeek(null, 0 - $periodNumber);
            $end = $this->startOfWeek(null, 0 - $periodNumber + 1);
            $response->headers->set(
                'Content-Disposition',
                'attachment; filename="so-sure-connections-week-'.$start->format('W').'-'.$start->format('Y').'.csv"'
            );
        } elseif ($periodType == 'month') {
            $month = (new \DateTime())->sub(new \DateInterval("P{$periodNumber}M"));
            $start = $this->startOfMonth($month);
            $end = $this->endOfMonth($month);
            $response->headers->set(
                'Content-Disposition',
                'attachment; filename="so-sure-connections-'.$start->format('F-Y').'.csv"'
            );
        } else {
            throw new \Exception($periodType.' is not week/month');
        }
        $dm = $this->getManager();
        /** @var PolicyRepository $policyRepo */
        $policyRepo = $dm->getRepository(Policy::class);
        $policies = $policyRepo->findUnpaidCalls($start, $end);
        // Build the response content.
        $response->setCallback(function () use ($policies) {
            $handle = fopen('php://output', 'w+');
            fputcsv($handle, [
                'Date',
                'Name',
                'Email',
                'Policy Number',
                'Phone Number',
                'Claim',
                'Cost of claims',
                'Termination Date',
                'Days Before Termination',
                'Present status',
                'Call',
                'Note',
                'Voicemail',
                'Other Actions',
                'All actions',
                'Category',
                'Termination week number',
                'Call week number',
                'Call month',
                'Cancellation month'
            ]);
            foreach ($policies as $policy) {
                /** @var Policy $policy */
                /** @var CallNote $note */
                $note = $policy->getLatestNoteByType(Note::TYPE_CALL);
                $approvedClaims = $policy->getApprovedClaims(true);
                $claimsCost = 0;
                foreach ($approvedClaims as $approvedClaim) {
                    /** @var Claim $approvedClaim */
                    $claimsCost += $approvedClaim->getTotalIncurred();
                }
                $line = [
                    $note->getDate()->format('Y-m-d'),
                    $policy->getUser()->getName(),
                    $policy->getUser()->getEmail(),
                    $policy->getPolicyNumber(),
                    $policy->getUser()->getMobileNumber(),
                    count($approvedClaims),
                    $claimsCost,
                    $policy->getPolicyExpirationDate() ? $policy->getPolicyExpirationDate()->format('Y-m-d') : null,
                    'FORMULA',
                    $policy->getStatus(),
                    'Yes',
                    $note->getResult(),
                    $note->getVoicemail() ? 'Yes' : '',
                    $note->getOtherActions(),
                    $note->getActions(true),
                    $note->getCategory(),
                    $policy->getPolicyExpirationDate() ? $policy->getPolicyExpirationDate()->format('W') : null,
                    $note->getDate()->format('W'),
                    $note->getDate()->format('M'),
                    $policy->getPolicyExpirationDate() ? $policy->getPolicyExpirationDate()->format('M') : null
                ];
                fputcsv(
                    $handle,
                    $line
                );
            }
            fclose($handle);
        });
        $response->setStatusCode(200);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        return $response;
    }

    /**
     * @Route("/users", name="admin_users")
     * @Template("AppBundle::AdminEmployee/adminUsers.html.twig")
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
                    $this->addFlash('success-raw', sprintf(
                        'Created User. <a href="%s">%s</a>',
                        $this->generateUrl('admin_user', ['id' => $user->getId()]),
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
        $emailOptOut->setLocation(EmailOptOut::OPT_LOCATION_ADMIN);
        $smsOptOut = new SmsOptOut();
        $smsOptOut->setLocation(EmailOptOut::OPT_LOCATION_ADMIN);

        $emailForm = $this->get('form.factory')
            ->createNamedBuilder('email_form', AdminEmailOptOutType::class, $emailOptOut)
            ->getForm();
        $smsForm = $this->get('form.factory')
            ->createNamedBuilder('sms_form', AdminSmsOptOutType::class, $smsOptOut)
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
        $repo = $dm->getRepository(EmailOptOut::class);
        $oupouts = $repo->findAll();

        return [
            'optouts' => $oupouts,
            'email_form' => $emailForm->createView(),
            'sms_form' => $smsForm->createView(),
        ];
    }

    /**
     * @Route("/claims-form/{id}/policy", name="admin_claims_form_policy")
     * @Route("/claims-form/{id}/claims", name="admin_claims_form_claims")
     */
    public function claimsFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->find($id);

        $claimsForm = $this->get('form.factory')
            ->createNamedBuilder('claims_form', ClaimInfoType::class, $claim)
            ->setAction($this->generateUrl(
                $request->get('_route'),
                ['id' => $id]
            ))
            ->getForm();
        $claimsNoteForm = $this->get('form.factory')
            ->createNamedBuilder('claims_note_form', ClaimNoteType::class, $claim)
            ->setAction($this->generateUrl(
                $request->get('_route'),
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claims_form')) {
                $claimsForm->handleRequest($request);
                if ($claimsForm->isValid()) {
                    $claim->getPolicy()->addNoteDetails(
                        sprintf('Manually updated claim %s', $claim->getNumber()),
                        $this->getUser(),
                        sprintf('Updated claim %s', $claim->getNumber())
                    );

                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Claim %s updated', $claim->getNumber())
                    );
                } else {
                    $this->addFlash(
                        'error',
                        sprintf('Failed to update Claim %s', $claim->getNumber())
                    );
                }
            } elseif ($request->request->has('claims_note_form')) {
                $claimsNoteForm->handleRequest($request);
                if ($claimsNoteForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        sprintf('Claim %s updated', $claim->getNumber())
                    );
                } else {
                    $this->addFlash(
                        'error',
                        sprintf('Failed to update Claim %s', $claim->getNumber())
                    );
                }
            }

            if ($request->get('_route') == 'admin_claims_form_policy') {
                return $this->redirectToRoute('admin_policy', ['id' => $claim->getPolicy()->getId()]);
            } else {
                return $this->redirectToRoute('admin_claims');
            }
        }

        return $this->render('AppBundle:Claims:claimsModalBody.html.twig', [
            'form' => $claimsForm->createView(),
            'claim' => $claim,
            'claim_note_form' => $claimsNoteForm->createView(),
        ]);
    }

    /**
     * @Route("/imei-form/{id}", name="imei_form")
     * @Template
     */
    public function imeiFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $imei = new Imei();
        $imei->setPolicy($policy);
        $imeiForm = $this->get('form.factory')
            ->createNamedBuilder('imei_form', ImeiType::class, $imei)
            ->setAction($this->generateUrl(
                'imei_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('imei_form')) {
                $imeiForm->handleRequest($request);
                if ($imeiForm->isValid()) {
                    $imeiUpdated = false;
                    $phoneUpdated = false;
                    if ($this->isImei($imei->getImei()) && $imei->getImei() != $policy->getImei()) {
                        $policy->adjustImei($imei->getImei(), false);
                        $imeiUpdated = true;
                    } elseif (!$this->isImei($imei->getImei())) {
                        $this->addFlash(
                            'error',
                            sprintf('%s is not a valid IMEI number', $imei->getImei())
                        );
                    }

                    if ($imei->getPhone() && $imei->getPhone() != $policy->getPhone()) {
                        $policy->setPhone($imei->getPhone());
                        $phoneUpdated = true;
                    }

                    $msg = null;
                    if ($imeiUpdated && $phoneUpdated) {
                        $msg = 'IMEI & Phone updated';
                    } elseif ($imeiUpdated) {
                        $msg = 'IMEI updated';
                    } elseif ($phoneUpdated) {
                        $msg = 'Phone updated';
                    }

                    if ($imeiUpdated || $phoneUpdated) {
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s %s', $policy->getPolicyNumber(), $msg)
                        );

                        $policy->addNoteDetails(
                            $imei->getNote(),
                            $this->getUser(),
                            $msg
                        );

                        $dm->flush();
                    }
                } else {
                    $this->addFlash(
                        'error',
                        'Unable to save form'
                    );
                }

                return $this->redirectToRoute('admin_policy', ['id' => $id]);
            }
        }

        return [
            'form' => $imeiForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/serial-number-form/{id}", name="serial_number_form")
     * @Template
     */
    public function serialNumberFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $serialNumber = new SerialNumber();
        $serialNumber->setPolicy($policy);
        $serialNumberForm = $this->get('form.factory')
            ->createNamedBuilder('serial_number_form', SerialNumberType::class, $serialNumber)
            ->setAction($this->generateUrl(
                'serial_number_form',
                ['id' => $id]
            ))
            ->getForm();

        if ("POST" === $request->getMethod()) {
            $serialNumberForm->handleRequest($request);
            if ($serialNumberForm->isValid()) {
                $policy->setSerialNumber($serialNumber->getSerialNumber());
                $msg = "Serial Number update";
                $this->addFlash(
                    'success',
                    sprintf('Policy %s %s', $policy->getPolicyNumber(), $msg)
                );

                $policy->addNoteDetails(
                    $serialNumber->getNote(),
                    $this->getUser(),
                    $msg
                );

                $dm->flush();
            } else {
                $this->addFlash(
                    'error',
                    'Unable to save form'
                );
            }
            return $this->redirectToRoute('admin_policy', ['id' => $id]);
        }

        return [
            'form' => $serialNumberForm->createView(),
            'policy' => $policy,
        ];

    }

    /**
     * @Route("/detected-imei-form/{id}", name="detected_imei_form")
     * @Template
     */
    public function detectedImeiFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $imei = new Imei();
        $imei->setPolicy($policy);
        if ($policy->getDetectedImei()) {
            $imei->setImei($policy->getDetectedImei());
        } else {
            $imei->setImei($request->get('detected-imei'));
        }
        $imeiForm = $this->get('form.factory')
            ->createNamedBuilder('imei_form', DetectedImeiType::class, $imei)
            ->setAction($this->generateUrl(
                'detected_imei_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('imei_form')) {
                $imeiForm->handleRequest($request);
                if ($imeiForm->isValid()) {
                    /** @var PolicyService $policyService */
                    $policyService = $this->get('app.policy');
                    $policyService->setDetectedImei($policy, $imei->getImei(), $this->getUser(), $imei->getNote());

                    $this->addFlash(
                        'success',
                        sprintf('Policy %s detected imei updated.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $imeiForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/invalid-imei-form/{id}", name="invalid_imei_form")
     * @Template
     */
    public function invalidImeiFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $invalidImei = new InvalidImei();
        $invalidImei->setPolicy($policy);
        $imeiForm = $this->get('form.factory')
            ->createNamedBuilder('imei_form', InvalidImeiType::class, $invalidImei)
            ->setAction($this->generateUrl(
                'invalid_imei_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('imei_form')) {
                $imeiForm->handleRequest($request);
                if ($imeiForm->isValid()) {
                    /** @var PolicyService $policyService */
                    $policyService = $this->get('app.policy');

                    $policyService->setInvalidImei(
                        $policy,
                        $invalidImei->hasInvalidImei(),
                        $this->getUser(),
                        $invalidImei->getNote()
                    );

                    $this->addFlash(
                        'success',
                        sprintf('Policy %s invalid imei updated.', $policy->getPolicyNumber())
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $imeiForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/picsure-form/{id}", name="picsure_form")
     * @Template
     */
    public function picsureFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $picSure = new PicSureStatus();
        $picSure->setPolicy($policy);
        /** @var Form $picsureForm */
        $picsureForm = $this->get('form.factory')
            ->createNamedBuilder('picsure_form', PicSureStatusType::class, $picSure)
            ->setAction($this->generateUrl(
                'picsure_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('picsure_form')) {
                $picsureForm->handleRequest($request);
                if ($picsureForm->isValid()) {
                    if ($policy->getPolicyTerms()->isPicSureEnabled()) {
                        $policy->setPicSureStatus($picSure->getPicSureStatus(), $this->getUser());
                        $policy->addNoteDetails(
                            $picSure->getNote(),
                            $this->getUser(),
                            'Changed Pic-Sure status'
                        );

                        $dm->flush();
                        $this->addFlash(
                            'success',
                            sprintf('Set pic-sure to %s', $policy->getPicSureStatus())
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Policy is not a pic-sure policy'
                        );
                    }
                } else {
                    $this->addFlash(
                        'error',
                        sprintf('Unable to update. Errror: %s', (string) $picsureForm->getErrors())
                    );
                }

                return $this->redirectToRoute('admin_policy', ['id' => $id]);
            }
        }

        return [
            'form' => $picsureForm->createView(),
            'policy' => $policy
        ];
    }

    /**
     * @Route("/link-claim/{id}", name="link_claim_form")
     * @Template
     */
    public function linkClaimFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $linkClaimform = $this->get('form.factory')
            ->createNamedBuilder('link_claim_form', LinkClaimType::class)
            ->setAction($this->generateUrl(
                'link_claim_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('link_claim_form')) {
                $linkClaimform->handleRequest($request);
                if ($linkClaimform->isValid()) {
                    /** @var ClaimRepository $repo */
                    $repo = $dm->getRepository(Claim::class);

                    $claim = $repo->findClaimByDetails(
                        $linkClaimform->get('id')->getData(),
                        $linkClaimform->get('number')->getData()
                    );

                    if (!$claim) {
                        $this->addFlash(
                            'error',
                            sprintf('No claim matched')
                        );

                        return $this->redirectToRoute('admin_policy', ['id' => $id]);
                    }

                    $policy->addLinkedClaim($claim);
                    $policy->addNoteDetails(
                        $linkClaimform->get('note')->getData(),
                        $this->getUser(),
                        sprintf('Linked Claim %s', $linkClaimform->get('number')->getData())
                    );

                    $dm->flush();

                    $this->addFlash(
                        'success',
                        sprintf(
                            'Policy %s successfully linked with claim: %s',
                            $policy->getPolicyNumber(),
                            $linkClaimform->get('number')->getData()
                        )
                    );

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $linkClaimform->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/bacs-payment-request/{id}", name="bacs_payment_request_form")
     * @Template
     */
    public function bacsPaymentRequestFormAction(Request $request, $id = null)
    {
        $dm = $this->getManager();

        /** @var PhonePolicyRepository $repo */
        $repo = $dm->getRepository(PhonePolicy::class);

        /** @var PolicyService $policyService */
        $policyService = $this->get('app.policy');

        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);

        if (!$policy) {
            throw $this->createNotFoundException(sprintf('Policy %s not found', $id));
        }

        $bacsPaymentRequestForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_payment_request_form', BacsPaymentRequestType::class)
            ->setAction($this->generateUrl(
                'bacs_payment_request_form',
                ['id' => $id]
            ))
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('bacs_payment_request_form')) {
                $bacsPaymentRequestForm->handleRequest($request);
                if ($bacsPaymentRequestForm->isValid()) {
                    $status = $policyService->sendBacsPaymentRequest($policy);

                    if ($status) {
                        $this->addFlash('success', "Successfully sent bacs payment request");

                        $policy->addNoteDetails(
                            $bacsPaymentRequestForm->get('note')->getData(),
                            $this->getUser(),
                            'Bacs Payment Request'
                        );

                        $dm->flush();
                    } else {
                        $this->addFlash('error', "Error sending bacs payment request");
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }

        return [
            'form' => $bacsPaymentRequestForm->createView(),
            'policy' => $policy,
        ];
    }

    /**
     * @Route("/policy/{id}/p", name="admin_payment_redirect")
     */
    public function paymentRedirectAction($id)
    {
        $dm = $this->getManager();
        /** @var PaymentRepository $repo */
        $repo = $dm->getRepository(Payment::class);
        /** @var Payment $payment */
        $payment = $repo->find($id);
        if (!$payment || !$payment->getPolicy()) {
            throw $this->createNotFoundException("Unknown payment");
        }

        return $this->redirectToRoute('admin_policy', ['id' => $payment->getPolicy()->getId()]);
    }

    /**
     * @Route("/policy/{id}/sp", name="admin_scheduled_payment_redirect")
     */
    public function scheduledPaymentRedirectAction($id)
    {
        $dm = $this->getManager();
        /** @var ScheduledPaymentRepository $repo */
        $repo = $dm->getRepository(ScheduledPayment::class);
        /** @var ScheduledPayment $scheduledPayment */
        $scheduledPayment = $repo->find($id);
        if (!$scheduledPayment || !$scheduledPayment->getPolicy()) {
            throw $this->createNotFoundException("Unknown scheduled payment");
        }

        return $this->redirectToRoute('admin_policy', ['id' => $scheduledPayment->getPolicy()->getId()]);
    }

    /**
     * @Route("/policy/{id}", name="admin_policy")
     * @Template("AppBundle::Admin/claimsPolicy.html.twig")
     */
    public function claimsPolicyAction(Request $request, $id)
    {
        /** @var PolicyService $policyService */
        $policyService = $this->get('app.policy');
        /** @var FraudService $fraudService */
        $fraudService = $this->get('app.fraud');
        /** @var ReceperioService $imeiService */
        $imeiService = $this->get('app.imei');
        $invitationService = $this->get('app.invitation');
        $dm = $this->getManager();
        /** @var PhonePolicyRepository $repo */
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = $repo->find($id);
        if (!$policy) {
            throw $this->createNotFoundException('Policy not found');
        }
        /** @var BacsService $bacsService */
        $bacsService = $this->get('app.bacs');

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
        $facebookForm = $this->get('form.factory')
            ->createNamedBuilder('facebook_form', FacebookType::class, $policy)
            ->getForm();
        $receperioForm = $this->get('form.factory')
            ->createNamedBuilder('receperio_form')->add('rerun', SubmitType::class)
            ->getForm();
        $chargebacks = new Chargebacks();
        $chargebacks->setPolicy($policy);
        $chargebacksForm = $this->get('form.factory')
            ->createNamedBuilder('chargebacks_form', ChargebacksType::class, $chargebacks)
            ->getForm();
        $bacsPayment = new BacsPayment();
        $bacsPayment->setSource(Payment::SOURCE_ADMIN);
        $bacsPayment->setManual(true);
        $bacsPayment->setStatus(BacsPayment::STATUS_SUCCESS);
        $bacsPayment->setSuccess(true);
        $bacsPayment->setDate(\DateTime::createFromFormat('U', time()));
        $bacsPayment->setAmount($policy->getPremium()->getYearlyPremiumPrice());
        $bacsPayment->setTotalCommission(Salva::YEARLY_TOTAL_COMMISSION);
        if ($policy->getPolicyOrUserBacsBankAccount()) {
            $bacsPayment->setDetails($policy->getPolicyOrUserBacsBankAccount()->__toString());
        }

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
        $swapPaymentPlanForm = $this->get('form.factory')
            ->createNamedBuilder('swap_payment_plan_form')->add('swap', SubmitType::class)
            ->getForm();
        $payPolicyForm = $this->get('form.factory')
            ->createNamedBuilder('pay_policy_form')
            ->add('monthly', SubmitType::class)
            ->add('yearly', SubmitType::class)
            ->getForm();
        $skipPaymentForm = $this->get('form.factory')
            ->createNamedBuilder('skip_payment_form')
            ->add('payment_id', HiddenType::class)
            ->add('skip', SubmitType::class)
            ->getForm();
        $cancelDirectDebitForm = $this->get('form.factory')
            ->createNamedBuilder('cancel_direct_debit_form')
            ->add('cancel', SubmitType::class)
            ->getForm();
        $paymentRequestFile = new PaymentRequestUploadFile();
        $paymentRequestFile->setPolicy($policy);
        $runScheduledPaymentForm = $this->get('form.factory')
            ->createNamedBuilder('run_scheduled_payment_form', PaymentRequestUploadFileType::class, $paymentRequestFile)
            ->getForm();
        $bacsRefund = new BacsPayment();
        $bacsRefund->setDate($this->getNextBusinessDay($this->now()));
        $bacsRefund->setSource(Payment::SOURCE_ADMIN);
        $bacsRefund->setPolicy($policy);
        $bacsRefund->setAmount($policy->getPremiumInstallmentPrice(true));
        $bacsRefund->setTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
        $bacsRefund->setStatus(BacsPayment::STATUS_PENDING);
        if ($policy->getPolicyOrUserBacsBankAccount()) {
            $bacsRefund->setDetails($policy->getPolicyOrUserBacsBankAccount()->__toString());
        }
        $bacsRefundForm = $this->get('form.factory')
            ->createNamedBuilder('bacs_refund_form', BacsCreditType::class, $bacsRefund)
            ->getForm();
        if ($policy->getDontCancelIfUnpaid()) {
            $dontCancelForm = $this->get('form.factory')
                ->createNamedBuilder('dont_cancel_form')
                ->add(
                    'allowCancellation',
                    SubmitType::class,
                    [
                        "label" => 'Allow cancellation',
                        'attr' => [
                            'dontCancel' => false,
                            'class' => 'btn btn-danger confirm-submit'
                        ]
                    ]
                )
                ->getForm();
        } else {
            $dontCancelForm = $this->get('form.factory')
                ->createNamedBuilder('dont_cancel_form')
                ->add(
                    'dontCancel',
                    SubmitType::class,
                    [
                        "label" => 'Prevent cancellation',
                        'attr' => [
                            'dontCancel' => true,
                            'class' => 'btn btn-danger confirm-submit'
                        ]
                    ]
                )
                ->getForm();
        }
        $salvaUpdateForm = $this->get('form.factory')
            ->createNamedBuilder('salva_update_form')
            ->add('update', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('cancel_form')) {
                $cancelForm->handleRequest($request);
                if ($cancelForm->isValid()) {
                    $claimCancel = $policy->canCancel($cancel->getCancellationReason(), null, true);
                    if ($policy->canCancel($cancel->getCancellationReason()) ||
                        ($claimCancel && $cancel->getForce())) {
                        if ($cancel->getRequestedCancellationReason()) {
                            $policy->setRequestedCancellationReason($cancel->getRequestedCancellationReason());
                        }
                        $policyService->cancel(
                            $policy,
                            $cancel->getCancellationReason(),
                            true,
                            null,
                            true,
                            $cancel->getFullRefund()
                        );
                        $this->addFlash(
                            'success',
                            sprintf('Policy %s was cancelled.', $policy->getPolicyNumber())
                        );
                    } elseif ($claimCancel && !$cancel->getForce()) {
                        $this->addFlash('error', sprintf(
                            'Unable to cancel Policy %s due to %s as override was not enabled',
                            $policy->getPolicyNumber(),
                            $cancel->getCancellationReason()
                        ));
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
            } elseif ($request->request->has('note_form')) {
                $noteForm->handleRequest($request);
                if ($noteForm->isValid()) {
                    $policy->addNoteDetails(
                        $this->conformAlphanumericSpaceDot($noteForm->getData()['notes'], 2500),
                        $this->getUser()
                    );
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
                    if ($policyService->resendPolicyEmail($policy)) {
                        $this->addFlash(
                            'success',
                            'Resent the policy email.'
                        );
                    } else {
                        $this->addFlash(
                            'error',
                            'Failed to resend the policy email.'
                        );
                    }
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('bacs_form')) {
                $bacsForm->handleRequest($request);
                if ($bacsForm->isValid()) {
                    // non-manual payments should be scheduled
                    if (!$bacsPayment->isManual()) {
                        $bacsPayment->setStatus(BacsPayment::STATUS_PENDING);
                        if (!$policy->hasPolicyOrUserBacsPaymentMethod()) {
                            $this->get('logger')->warning(sprintf(
                                'Payment (Policy %s) is scheduled, however no bacs account for user',
                                $policy->getId()
                            ));
                        }
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
                    $imeiUploadFile->setBucket(SoSure::S3_BUCKET_POLICY);
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
                    $screenUploadFile->setBucket(SoSure::S3_BUCKET_POLICY);
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
                        $chargeback->setDate(\DateTime::createFromFormat('U', time()));

                        if ($this->areEqualToTwoDp(
                            $chargeback->getAmount(),
                            $policy->getPremiumInstallmentPrice(true)
                        )) {
                            $chargeback->setRefundTotalCommission(Salva::MONTHLY_TOTAL_COMMISSION);
                        } else {
                            $this->addFlash(
                                'error',
                                sprintf(
                                    'Unable to determine commission to refund for chargeback %s',
                                    $chargeback->getReference()
                                )
                            );
                        }

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

                        $mailer->sendTemplateToUser(
                            $customerSubject,
                            $policy->getUser(),
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

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('pay_policy_form')) {
                $payPolicyForm->handleRequest($request);
                if ($payPolicyForm->isValid()) {
                    $date = \DateTime::createFromFormat('U', time());
                    $phone = $policy->getPhone();
                    $currentPrice = $phone->getCurrentPhonePrice();
                    if ($currentPrice && $payPolicyForm->get('monthly')->isClicked()) {
                        $amount = $currentPrice->getMonthlyPremiumPrice(null, $date);
                    } elseif ($currentPrice && $payPolicyForm->get('yearly')->isClicked()) {
                        $amount = $currentPrice->getYearlyPremiumPrice(null, $date);
                    } else {
                        throw new \Exception('1 or 12 payments only');
                    }
                    $premium = $policy->getPremium();
                    if ($premium &&
                        !$this->areEqualToTwoDp($amount, $premium->getAdjustedStandardMonthlyPremiumPrice()) &&
                        !$this->areEqualToTwoDp($amount, $premium->getAdjustedYearlyPremiumPrice())) {
                        throw new \Exception(sprintf(
                            'Current price does not match policy price for %s',
                            $policy->getId()
                        ));
                    }

                    /** @var CheckoutService $checkout */
                    $checkout = $this->get('app.checkout');
                    $details = $checkout->runTokenPayment(
                        $policy,
                        $amount,
                        $date->getTimestamp(),
                        $policy->getId()
                    );
                    try {
                        $checkout->add(
                            $policy,
                            $details['receiptId'],
                            Payment::SOURCE_TOKEN,
                            $date
                        );
                        // @codingStandardsIgnoreStart
                        $this->addFlash(
                            'success',
                            'Policy is now paid for. Pdf generation may take a few minutes. Refresh the page to verify.'
                        );
                        // @codingStandardsIgnoreEnd
                    } catch (PaymentDeclinedException $e) {
                        if ($policy->getStatus() === Policy::STATUS_PENDING) {
                            $policy->setStatus(null);
                        }

                        $this->addFlash(
                            'danger',
                            'Payment was declined'
                        );
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('cancel_direct_debit_form')) {
                $cancelDirectDebitForm->handleRequest($request);
                if ($cancelDirectDebitForm->isValid()) {
                    $bacsService->queueCancelBankAccount(
                        $policy->getPolicyOrUserBacsBankAccount(),
                        $policy->hasBacsPaymentMethod() ? $policy->getId() : $policy->getPayerOrUser()->getId()
                    );
                    $this->addFlash('success', sprintf(
                        'Direct Debit Cancellation has been queued.'
                    ));
                }
            } elseif ($request->request->has('skip_payment_form')) {
                $skipPaymentForm->handleRequest($request);
                if ($skipPaymentForm->isValid()) {
                    $paymentId = $skipPaymentForm->getData()['payment_id'];
                    $paymentRepo = $this->getManager()->getRepository(BacsPayment::class);
                    /** @var BacsPayment $payment */
                    $payment = $paymentRepo->find($paymentId);
                    if (!$payment) {
                        throw $this->createNotFoundException('Missing payment');
                    }

                    $payment->setStatus(BacsPayment::STATUS_SKIPPED);
                    $payment->setScheduledPayment(null);
                    $this->getManager()->flush();
                    $this->addFlash('success', sprintf(
                        'Skipped payment'
                    ));
                }
            } elseif ($request->request->has('run_scheduled_payment_form')) {
                $runScheduledPaymentForm->handleRequest($request);
                if ($runScheduledPaymentForm->isValid()) {
                    $scheduledPayment = $policy->getNextScheduledPayment();
                    if ($scheduledPayment && $paymentRequestFile) {
                        $paymentRequestFile->setBucket(SoSure::S3_BUCKET_POLICY);
                        $paymentRequestFile->setKeyFormat($this->getParameter('kernel.environment') . '/%s');

                        $policy->addPolicyFile($paymentRequestFile);
                        $scheduledPayment->adminReschedule();
                        if ($scheduledPayment->getPreviousAttempt()) {
                            $scheduledPayment->setPreviousAttempt(null);
                        }
                        if ($scheduledPayment->getPayment()) {
                            $scheduledPayment->setPayment(null);
                        }

                        $this->getManager()->flush();

                        $this->addFlash('success', sprintf(
                            'Rescheduled scheduled payment for %s',
                            $scheduledPayment->getScheduled() ?
                                    $scheduledPayment->getScheduled()->format('d M Y') :
                                    '?'
                        ));
                    }

                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('bacs_refund_form')) {
                $bacsRefundForm->handleRequest($request);
                if ($bacsRefundForm->isValid()) {
                    $bacsService->scheduleBacsPayment(
                        $policy,
                        0 - abs($bacsRefund->getAmount()),
                        ScheduledPayment::TYPE_REFUND,
                        $bacsRefund->getNotes()
                    );
                    $this->addFlash('success', sprintf(
                        'Refund scheduled'
                    ));
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            } elseif ($request->request->has('dont_cancel_form')) {
                $form = $request->request->get('dont_cancel_form');
                $dontCancel = isset($form['dontCancel']);
                $allowCancellation = isset($form['allowCancellation']);
                $dontCancelForm->handleRequest($request);
                $policy->setDontCancelIfUnpaid($dontCancel);
                $dm->flush();
                $this->addFlash(
                    'success',
                    $dontCancel ? 'Policy wont be cancelled if unpaid' : 'Policy will be cancelled if unpaid'
                );
                return $this->redirectToRoute('admin_policy', ['id' => $id]);
            } elseif ($request->request->has('salva_update_form')) {
                $salvaUpdateForm->handleRequest($request);
                if ($salvaUpdateForm->isValid()) {
                    /** @var SalvaExportService $salvaService */
                    $salvaService = $this->get('app.salva');
                    $salvaService->queuePolicy($policy, SalvaExportService::QUEUE_UPDATED);

                    $this->addFlash('success', 'Queued Salva Policy Update');
                }
            }
        }
        $checks = $fraudService->runChecks($policy);
        $now = \DateTime::createFromFormat('U', time());

        /** @var LogEntryRepository $logRepo */
        $logRepo = $this->getManager()->getRepository(LogEntry::class);
        $previousPicSureStatuses = $logRepo->findBy([
            'objectId' => $policy->getId(),
            'data.picSureStatus' => ['$nin' => [null, PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED]],
        ], ['loggedAt' => 'desc'], 1);
        $previousPicSureStatus = null;
        if (count($previousPicSureStatuses) > 0) {
            $previousPicSureStatus = $previousPicSureStatuses[0];
        }

        $previousInvalidPicSureStatuses = $logRepo->findBy([
            'objectId' => $policy->getId(),
            'data.picSureStatus' => PhonePolicy::PICSURE_STATUS_INVALID
        ]);
        $hadInvalidPicSureStatus = false;
        if (count($previousInvalidPicSureStatuses) > 0) {
            $hadInvalidPicSureStatus = true;
        }


        return [
            'policy' => $policy,
            'cancel_form' => $cancelForm->createView(),
            'pending_cancel_form' => $pendingCancelForm->createView(),
            'note_form' => $noteForm->createView(),
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
            'swap_payment_plan_form' => $swapPaymentPlanForm->createView(),
            'pay_policy_form' => $payPolicyForm->createView(),
            'cancel_direct_debit_form' => $cancelDirectDebitForm->createView(),
            'run_scheduled_payment_form' => $runScheduledPaymentForm->createView(),
            'bacs_refund_form' => $bacsRefundForm->createView(),
            'dont_cancel_form' => $dontCancelForm->createView(),
            'salva_update_form' => $salvaUpdateForm->createView(),
            'skip_payment_form' => $skipPaymentForm->createView(),
            'fraud' => $checks,
            'policy_route' => 'admin_policy',
            'user_route' => 'admin_user',
            'policy_history' => $this->getSalvaPhonePolicyHistory($policy->getId()),
            'user_history' => $this->getUserHistory($policy->getUser()->getId()),
            'suggested_cancellation_date' => $now->add(new \DateInterval('P30D')),
            'claim_types' => Claim::$claimTypes,
            'phones' => $dm->getRepository(Phone::class)->findActiveInactive()->getQuery()->execute(),
            'now' => \DateTime::createFromFormat('U', time()),
            'previousPicSureStatus' => $previousPicSureStatus,
            'hadInvalidPicSureStatus' => $hadInvalidPicSureStatus,
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
        /** @var User $user */
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
        if (!$address) {
            $address = new Address();
        }
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
        $handlingTeamForm = $this->get('form.factory')
            ->createNamedBuilder('handling_team_form', UserHandlingTeamType::class, $user)
            ->getForm();
        $deleteForm = $this->get('form.factory')
            ->createNamedBuilder('delete_form')
            ->add('delete', SubmitType::class)
            ->getForm();

        if ($user->getIsBlacklisted()) {
            $blacklistForm = $this->get('form.factory')
                ->createNamedBuilder('blacklist_user_form')
                ->add(
                    'unblacklist',
                    SubmitType::class,
                    [
                        "label" => 'Unblacklist',
                        'attr' => [
                            "unblacklist" => true,
                            'class' => 'btn btn-danger btn-square btn-sm mb-1 confirm-blacklist-user'
                        ]
                    ]
                )
                ->getForm();
        } else {
            $blacklistForm = $this->get('form.factory')
                ->createNamedBuilder('blacklist_user_form')
                ->add(
                    'blacklist',
                    SubmitType::class,
                    [
                        "label" => 'Blacklist',
                        'attr' => [
                            "blacklist" => true,
                            'class' => 'btn btn-danger btn-square btn-sm mb-1 confirm-blacklist-user'
                        ]
                    ]
                )
                ->getForm();
        }

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
                    if (null == $user->getConfirmationToken()) {
                        /** @var \FOS\UserBundle\Util\TokenGeneratorInterface $tokenGenerator */
                        $tokenGenerator = $this->get('fos_user.util.token_generator');
                        $user->setConfirmationToken($tokenGenerator->generateToken());
                    }

                    $this->container->get('fos_user.mailer')->sendResettingEmailMessage($user);
                    $user->setPasswordRequestedAt(\DateTime::createFromFormat('U', time()));
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
                        'Updated User'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                } else {
                    $errors = 'Unknown';
                    try {
                        $this->validateObject($user);
                    } catch (\Exception $e) {
                        $errors = $e->getMessage();
                    }
                    $this->addFlash(
                        'error',
                        sprintf('Failed to update user. Error: %s', $errors)
                    );
                }
            } elseif ($request->request->has('user_address_form')) {
                $userAddressForm->handleRequest($request);
                if ($userAddressForm->isValid()) {
                    $user->setBillingAddress($address);
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
                    $existingUser = $userRepo->findOneBy(['emailCanonical' => mb_strtolower($user->getEmail())]);
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
            } elseif ($request->request->has('handling_team_form')) {
                $handlingTeamForm->handleRequest($request);
                if ($handlingTeamForm->isValid()) {
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Updated Handling Team'
                    );

                    return $this->redirectToRoute('admin_user', ['id' => $id]);
                }
            } elseif ($request->request->has('blacklist_user_form')) {
                $form = $request->request->get('blacklist_user_form');
                $blacklist = isset($form['blacklist']);
                $unblacklist = isset($form['unblacklist']);
                $blacklistForm->handleRequest($request);
                if ($blacklist) {
                    $user->setIsBlacklisted(true);
                    $dm->flush();
                    $this->addFlash(
                        'error',
                        'User Blacklisted!'
                    );
                } elseif ($unblacklist) {
                    $user->setIsBlacklisted(false);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        "User Unblacklisted"
                    );
                }

                return $this->redirectToRoute('admin_user', ['id' => $id]);
            } elseif ($request->request->has('delete_form')) {
                $deleteForm->handleRequest($request);
                if ($deleteForm->isValid()) {
                    /** @var FOSUBUserProvider $userService */
                    $userService = $this->get('app.user');
                    $userService->deleteUser($user);
                    $dm->flush();
                    $this->addFlash(
                        'success',
                        'Deleted User'
                    );

                    return $this->redirectToRoute('admin_users');
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
            'handling_team_form' => $handlingTeamForm->createView(),
            'blacklist_user_form' => $blacklistForm->createView(),
            'delete_form' => $deleteForm->createView(),
            'postcode' => $postcode,
            'census' => $census,
            'income' => $income,
            'policy_route' => 'admin_policy',
        ];
    }

    /**
     * @Route("/claims", name="admin_claims")
     * @Template("AppBundle::Claims/claims.html.twig")
     */
    public function adminClaimsAction(Request $request)
    {
        return $this->searchClaims($request);
    }

    /**
     * @Route("/claim/{number}", name="admin_claim_number")
     */
    public function adminClaimNumberAction($number)
    {
        $dm = $this->getManager();
        /** @var ClaimRepository $repo */
        $repo = $dm->getRepository(Claim::class);
        /** @var Claim $claim */
        $claim = $repo->findOneBy(['number' => $number]);
        if (!$claim) {
            throw $this->createNotFoundException('Policy not found');
        }

        return $this->redirectToRoute('admin_policy', ['id' => $claim->getPolicy()->getId()]);
    }

    /**
     * @Route("/policy/download/{id}", name="admin_download_file")
     * @Route("/policy/download/{id}/attachment", name="admin_download_file_attachment")
     */
    public function policyDownloadFileAction(Request $request, $id)
    {
        return $this->policyDownloadFile(
            $id,
            $request->get('_route') == 'admin_download_file_attachment'
        );
    }

    /**
     * @Route("/phone/{id}/alternatives", name="admin_phone_alternatives")
     * @Method({"GET"})
     */
    public function phoneAlternativesAction($id)
    {
        return $this->phoneAlternatives($id);
    }

    /**
     * @Route("/claim/notes/{id}", name="admin_claim_notes", requirements={"id":"[0-9a-f]{24,24}"})
     * @Method({"POST"})
     */
    public function claimsNotesAction(Request $request, $id)
    {
        return $this->claimsNotes($request, $id);
    }

    /**
     * @Route("/scheduled-payments", name="admin_scheduled_payments")
     * @Route("/scheduled-payments/{year}/{month}", name="admin_scheduled_payments_date")
     * @Template
     */
    public function adminScheduledPaymentsAction(Request $request, $year = null, $month = null)
    {
        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = new \DateTime(sprintf('%d-%d-01', $year, $month));
        $end = $this->endOfMonth($date);

        $dm = $this->getManager();
        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $dm->getRepository(ScheduledPayment::class);
        $scheduledPayments = $scheduledPaymentRepo->findMonthlyScheduled($date);
        $scheduledPaymentsMonthly = $scheduledPaymentRepo->getMonthlyValues();
        $total = 0;

        foreach ($scheduledPaymentsMonthly as $scheduledPaymentsMonthlyItem) {
            if ($scheduledPaymentsMonthlyItem["_id"]["year"] == $year &&
                $scheduledPaymentsMonthlyItem["_id"]["month"] == $month) {
                $total = $scheduledPaymentsMonthlyItem["total"];
            }
        }
        /*
        $totalJudo = 0;
        $totalBacs = 0;
        $query = $scheduledPayments->getQuery()->execute();
        foreach ($query as $scheduledPayment) {
            if (in_array(
                $scheduledPayment->getStatus(),
                [ScheduledPayment::STATUS_SCHEDULED, ScheduledPayment::STATUS_SUCCESS]
            )) {
                $total += $scheduledPayment->getAmount();
                if ($scheduledPayment->getPolicy()->hasPolicyOrUserBacsPaymentMethod()) {
                    $totalBacs += $scheduledPayment->getAmount();
                } else {
                    $totalJudo += $scheduledPayment->getAmount();
                }
            }
        }
        */

        $pager = $this->pager($request, $scheduledPayments);

        return [
            'year' => $year,
            'month' => $month,
            'end' => $end,
            'scheduledPayments' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'totals' => $scheduledPaymentsMonthly,
            'total' => $total,
            //'totalJudo' => $totalJudo,
            //'totalBacs' => $totalBacs,
        ];
    }

    /**
     * @Route("/pl", name="admin_quarterly_pl")
     * @Route("/pl/{year}/{month}", name="admin_quarterly_pl_date")
     * @Template
     */
    public function adminQuarterlyPLAction(Request $request, $year = null, $month = null)
    {
        if ($request->get('_route') == "admin_quarterly_pl") {
            $now = \DateTime::createFromFormat('U', time());
            $now = $now->sub(new \DateInterval('P1Y'));
            return new RedirectResponse($this->generateUrl('admin_quarterly_pl_date', [
                'year' => $now->format('Y'),
                'month' => $now->format('m'),
            ]));
        }
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $year, $month),
            SoSure::getSoSureTimezone()
        );

        $data = [];

        $reporting = $this->get('app.reporting');
        $report = $reporting->getQuarterlyPL($date);

        return ['data' => $report];
    }

    /**
     * @Route("/underwriting", name="admin_underwriting")
     * @Route("/underwriting/{year}/{month}", name="admin_underwriting_date")
     * @Template
     */
    public function adminUnderWritingAction(Request $request, $year = null, $month = null)
    {
        if ($request->get('_route') == "admin_underwriting") {
            $now = \DateTime::createFromFormat('U', time());
            $now = $now->sub(new \DateInterval('P1Y'));
            return new RedirectResponse($this->generateUrl('admin_underwriting_date', [
                'year' => $now->format('Y'),
                'month' => $now->format('m'),
            ]));
        }
        $date = \DateTime::createFromFormat(
            "Y-m-d",
            sprintf('%d-%d-01', $year, $month),
            SoSure::getSoSureTimezone()
        );

        $data = [];

        /** @var ReportingService $reporting */
        $reporting = $this->get('app.reporting');
        $report = $reporting->getUnderWritingReporting($date);

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
            SoSure::getSoSureTimezone()
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
            $imei = trim($form->getData()['imei']);
            $history = $logRepo->findBy([
                'data.imei' => $imei
            ]);
            $unsafeCharges = $chargeRepo->findBy(['details' => $imei]);
            foreach ($unsafeCharges as $unsafeCharge) {
                try {
                    // attempt to access user
                    if ($unsafeCharge->getUser() && $unsafeCharge->getUser()->getName()) {
                        $charges[] = $unsafeCharge;
                    }
                } catch (\Exception $e) {
                    $user = new User();
                    $user->setFirstName('Deleted');
                    $unsafeCharge->setUser($user);
                    $charges[] = $unsafeCharge;
                }
            }

            if (!$this->isImei($imei)) {
                $otherImei = 'unknown - invalid length';
                if (mb_strlen($imei) >= 14) {
                    $otherImei = $this->luhnGenerate(mb_substr($imei, 0, 14));
                }
                $this->addFlash('error', sprintf(
                    sprintf('Invalid IMEI. Did you mean %s?', $otherImei)
                ));
            }
        }

        return [
            'history' => $history,
            'charges' => $charges,
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/detected-imei-search", name="admin_detected_imei_search")
     * @Template
     */
    public function detectedImeiSearchAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        $imei = $request->get('imei');
        $detectedImei = $request->get('detected-imei');
        if (!$imei) {
            throw $this->createNotFoundException('Missing imei');
        }

        $policy = $repo->findOneBy(['imei' => $imei]);
        if ($policy) {
            return new RedirectResponse($this->generateUrl('admin_policy', [
                'id' => $policy->getId(),
                'detected-imei' => $detectedImei,
            ]));
        } else {
            throw $this->createNotFoundException('Not found imei');
        }
    }

    /**
     * @Route("/detected-imei", name="admin_detected_imei")
     * @Template
     */
    public function detectedImeiAction()
    {
        $redis = $this->get("snc_redis.default");
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);

        /*
        $debug = false;
        if ($debug) {
            $policy = $repo->findOneBy(['imei' => ['$ne' => null]]);
            $redis->lpush('DETECTED-IMEI', json_encode([
                'detected_imei' => $policy->getImei(),
                'suggested_imei' => 'a456',
                'bucket' => 'a',
                'key' => 'key',
            ]));
        }
        */

        $storedImeis = $redis->lrange("DETECTED-IMEI", 0, -1);
        $imeis = [];
        foreach ($storedImeis as $storedImei) {
            $imei = json_decode($storedImei, true);
            $imei['actualPolicy'] = $repo->findOneBy(['imei' => $imei['detected_imei']]);
            $imei['detectedPolicy'] = $repo->findOneBy(['detectedImei' => $imei['detected_imei']]);
            if (mb_strlen($imei['suggested_imei']) > 0) {
                $imei['suggestedPolicy'] = $repo->findOneBy(['imei' => $imei['suggested_imei']]);
            } else {
                $imei['suggestedPolicy'] = null;
            }
            $imei['raw'] = $storedImei;
            $imeis[] = $imei;
        }

        return [
            "imeis" => $imeis
        ];
    }

    /**
     * @Route("/detected-imei/delete", name="admin_delete_detected_imei")
     * @Template
     * @Method("POST")
     */
    public function deleteDetectedImeiAction(Request $request)
    {
        $item = $request->request->get("item");
        /** @var Client $redis */
        $redis = $this->get("snc_redis.default");
        if ($redis->lrem('DETECTED-IMEI', 1, $item)) {
            $this->addFlash('success', sprintf(
                sprintf('Removed %s', $item)
            ));
        } else {
            $this->addFlash('error', sprintf(
                sprintf('Failed to remove %s', $item)
            ));
        }

        return new RedirectResponse($this->generateUrl('admin_detected_imei'));
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
        $createReward = new CreateReward();
        $connectForm = $this->get('form.factory')
            ->createNamedBuilder('connectForm')
            ->add('email', EmailType::class)
            ->add('amount', TextType::class)
            ->add('rewardId', HiddenType::class)
            ->add('next', SubmitType::class)
            ->getForm();
        $rewardForm = $this->get('form.factory')
            ->createNamedBuilder('rewardForm', RewardType::class, $createReward)
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
                                'emailCanonical' => mb_strtolower($connectForm->getData()['email'])
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
                        $user->setEmail($createReward->getEmail());
                        $user->setFirstName($createReward->getFirstName());
                        $user->setLastName($createReward->getLastName());
                        $dm->persist($user);
                        $dm->flush();
                        $reward = new Reward();
                        $reward->setUser($user);
                        $reward->setDefaultValue($createReward->getDefaultValue());
                        $reward->setExpiryDate($createReward->getExpiryDate());
                        $reward->setPolicyAgeMin($createReward->getPolicyAgeMin());
                        $reward->setPolicyAgeMax($createReward->getPolicyAgeMax());
                        $reward->setUsageLimit($createReward->getUsageLimit());
                        $reward->setHasNotClaimed($createReward->getHasNotClaimed());
                        $reward->setHasRenewed($createReward->getHasRenewed());
                        $reward->setHasCancelled($createReward->getHasCancelled());
                        $reward->setIsFirst($createReward->getIsFirst());
                        $reward->setTermsAndConditions($createReward->getTermsAndConditions());
                        $dm->persist($reward);
                        $code = $createReward->getCode();
                        if (mb_strlen($code) > 0) {
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
     * @Route("/tastecard-form/{id}", name="tastecard_form")
     * @Template
     */
    public function tasteCardFormAction(Request $request, $id)
    {
        $tasteCardForm = $this->get("form.factory")
            ->createNamedBuilder("tastecard_form")
            ->add("number", TextType::class)
            ->add("update", SubmitType::class)
            ->add("resend", SubmitType::class)
            ->setAction($this->generateUrl(
                'tastecard_form',
                ['id' => $id]
            ))
            ->getForm();
        $dm = $this->getManager();
        $policyRepository = $dm->getRepository(Policy::class);
        $policy = $policyRepository->find($id);
        if ('POST' === $request->getMethod()) {
            if ($request->request->has("tastecard_form")) {
                $tasteCardForm->handleRequest($request);
                if ($tasteCardForm->isValid()) {
                    $policyService = $this->get("app.policy");
                    if ($tasteCardForm->getClickedButton()->getName() === "update") {
                        $tasteCard = $this->conformAlphanumeric($tasteCardForm->get("number")->getData(), 10, 10);
                        if ($tasteCard) {
                            $policy->setTasteCard($tasteCard);
                            $dm->flush();
                            $policyService->tasteCardEmail($policy);
                            $this->addFlash("success", "Tastecard set to {$tasteCard}.");
                        } else {
                            $this->addFlash("error", "Tastecard number must be 10 alphanumeric characters.");
                        }
                    } elseif ($tasteCardForm->getClickedButton()->getName() === "resend" && $policy->getTasteCard()) {
                        $policyService->tasteCardEmail($policy);
                        $this->addFlash('success', 'Tastecard notification has been resent.');
                    }
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }
        return [
            "form" => $tasteCardForm->createView(),
            "policy" => $policy
        ];
    }

    /**
     * @Route("/scheduled-payment-form/{id}/{scheduledPaymentId}", name="scheduled_payment_form")
     * @Template
     */
    public function scheduledPaymentFormAction(Request $request, $id, $scheduledPaymentId)
    {

        $dm = $this->getManager();
        $scheduledPaymentRepo = $dm->getRepository(ScheduledPayment::class);
        $scheduledPayment = $scheduledPaymentRepo->find($scheduledPaymentId);
        if (!$scheduledPayment) {
            $this->addFlash(
                "error",
                "Attempted to modify nonexistent scheduled payment with id '{$scheduledPaymentId}'."
            );
            return $this->redirectToRoute('admin_policy', ['id' => $id]);
        }
        $scheduledPaymentForm = $this->get("form.factory")
            ->createNamedBuilder("scheduled_payment_form")
            ->add("notes", TextType::class, ["data" => $scheduledPayment->getNotes()])
            ->add(
                "scheduled",
                DateTimeType::class,
                [
                    "data" => $scheduledPayment->getScheduled(),
                    "html5" => false,
                    "widget" => "single_text",
                    "format" => "dd-MM-yyyy HH:mm",
                    "attr" => ["class" => "datetimepickerfuture", "autocomplete" => "off"]
                ]
            )
            ->add("submit", SubmitType::class)
            ->setAction(
                $this->generateUrl("scheduled_payment_form", ["id" => $id, "scheduledPaymentId" => $scheduledPaymentId])
            )
            ->getForm();
        if ('POST' === $request->getMethod()) {
            // TODO: permissions
            if ($request->request->has("scheduled_payment_form")) {
                $scheduledPaymentForm->handleRequest($request);
                if ($scheduledPaymentForm->isValid()) {
                    $notes = $scheduledPaymentForm->get("notes")->getData();
                    $scheduled = $scheduledPaymentForm->get("scheduled")->getData();
                    if ($notes) {
                        $scheduledPayment->setNotes($notes);
                    }
                    if ($scheduled) {
                        $scheduledPayment->setScheduled($scheduled);
                    }
                    $dm->flush();
                    $this->addFlash("success", "Scheduled Payment updated.");
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }
        return ["form" => $scheduledPaymentForm->createView()];
    }

    /**
     * @Route("/cancel-scheduled-payment-form/{id}/{scheduledPaymentId}", name="cancel_scheduled_payment_form")
     * @Template
     */
    public function cancelScheduledPaymentFormAction(Request $request, $id, $scheduledPaymentId)
    {
        $dm = $this->getManager();
        $scheduledPaymentRepo = $dm->getRepository(ScheduledPayment::class);
        /** @var ScheduledPayment $scheduledPayment */
        $scheduledPayment = $scheduledPaymentRepo->find($scheduledPaymentId);
        if (!$scheduledPayment) {
            $this->addFlash(
                "error",
                "Attempted to cancel nonexistent scheduled payment with id '{$scheduledPaymentId}'."
            );
            return $this->redirectToRoute('admin_policy', ['id' => $id]);
        }
        $cancelScheduledPaymentForm = $this->get("form.factory")
            ->createNamedBuilder("cancel_scheduled_payment_form")
            ->add("notes", TextType::class, ["data" => $scheduledPayment->getNotes()])
            ->add("submit", SubmitType::class)
            ->setAction(
                $this->generateUrl(
                    "cancel_scheduled_payment_form",
                    ["id" => $id, "scheduledPaymentId" => $scheduledPaymentId]
                )
            )
            ->getForm();
        if ('POST' === $request->getMethod()) {
            // TODO: permissions
            if ($request->request->has("cancel_scheduled_payment_form")) {
                $cancelScheduledPaymentForm->handleRequest($request);
                if ($cancelScheduledPaymentForm->isValid()) {
                    $notes = $cancelScheduledPaymentForm->get("notes")->getData();
                    $scheduledPayment->cancel($notes);
                    $dm->flush();
                    $this->addFlash("success", "Scheduled Payment Cancelled.");
                    return $this->redirectToRoute('admin_policy', ['id' => $id]);
                }
            }
        }
        return ["form" => $cancelScheduledPaymentForm->createView()];
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
        $companyRepo = $dm->getRepository(CustomerCompany::class);
        $userRepo = $dm->getRepository(User::class);
        $companies = $companyRepo->findAll();

        try {
            if ('POST' === $request->getMethod()) {
                if ($request->request->has('belongForm')) {
                    $belongForm->handleRequest($request);
                    if ($belongForm->isValid()) {
                        $user = $userRepo->findOneBy([
                            'emailCanonical' => mb_strtolower($belongForm->getData()['email'])
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
                        $company = new CustomerCompany();
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
        $now = \DateTime::createFromFormat('U', time());

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
                /** @var Phone $phone */
                $phone->setDescription($request->get('description'));
                $phone->setFunFacts($request->get('fun-facts'));
                $phone->setCanonicalPath($request->get('canonical-path'));
            }
            $dm->flush();
            $this->addFlash(
                'success',
                'Your changes were saved!'
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
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
                'success',
                $message
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/{id}/topphone", name="admin_phone_topphone")
     * @Method({"POST"})
     */
    public function phoneTopPhoneAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            if ($phone->isTopPhone()) {
                $phone->setTopPhone(false);
                $message = 'Phone is no longer a top phone';
            } else {
                $phone->setTopPhone(true);
                $message = 'Phone is now a top phone';
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
                'success',
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
        $now = \DateTime::createFromFormat('U', time());
        if (!$year) {
            $year = $now->format('Y');
        }
        if (!$month) {
            $month = $now->format('m');
        }
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));
        /** @var ReportingService $reporting */
        $reporting = $this->get('app.reporting');
        $judo = $reporting->payments($date, true);
        $checkout = $reporting->payments($date, false, true);

        return [
            'judo' => $judo,
            'checkout' => $checkout,
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
        /** @var PhonePolicy $policy */
        $policy = null;
        if ($id) {
            /** @var PhonePolicy $policy */
            $policy = $repo->find($id);
        }
        $picSureSearchForm = $this->get('form.factory')
            ->createNamedBuilder('search_form', PicSureSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $picSureSearchForm->handleRequest($request);

        if ($request->get('_route') == "admin_picsure_approve") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED, $this->getUser());
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplateToUser(
                'Phone validation successful ✅',
                $policy->getUser(),
                'AppBundle:Email:picsure/accepted.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:picsure/accepted.txt.twig',
                ['policy' => $policy]
            );

            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Your phone is now successfully validated.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            if (count($picsureFiles) > 0) {
                $this->get('event_dispatcher')->dispatch(
                    PicsureEvent::EVENT_APPROVED,
                    new PicsureEvent($policy, $picsureFiles[0])
                );
            } else {
                $this->get('logger')->error(sprintf("Missing picture file in policy %s.", $policy->getId()));
            }

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        } elseif ($request->get('_route') == "admin_picsure_reject") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED, $this->getUser());
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplateToUser(
                'Phone validation failed ❌',
                $policy->getUser(),
                'AppBundle:Email:picsure/rejected.html.twig',
                ['policy' => $policy],
                'AppBundle:Email:picsure/rejected.txt.twig',
                ['policy' => $policy]
            );
            if ($policy->isWithinCooloffPeriod()) {
                $mailer->sendTemplate(
                    'Please cancel (cooloff) policy due to pic-sure rejection',
                    'support@wearesosure.com',
                    'AppBundle:Email:picsure/adminRejected.html.twig',
                    ['policy' => $policy]
                );
                $this->addFlash('error-raw', sprintf(
                    'Policy <a href="%s">%s</a> should be cancelled (intercom support message also sent).',
                    $this->get('app.router')->generateUrl('admin_policy', ['id' => $policy->getId()]),
                    $policy->getPolicyNumber()
                ));
            }
            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Your phone did not pass validation. If you phone was damaged prior to your policy purchase, then it is crimial fraud to claim on our policy. Please contact us if you have purchased this policy by mistake.'
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            if (count($picsureFiles) > 0) {
                $this->get('event_dispatcher')->dispatch(
                    PicsureEvent::EVENT_REJECTED,
                    new PicsureEvent($policy, $picsureFiles[0])
                );
            } else {
                $this->get('logger')->error(sprintf("Missing picture file in policy %s.", $policy->getId()));
            }

            return new RedirectResponse($this->generateUrl('admin_picsure'));
        } elseif ($request->get('_route') == "admin_picsure_invalid") {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID, $this->getUser());
            $dm->flush();
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplateToUser(
                'Sorry, please attempt to validate again ⚠️',
                $policy->getUser(),
                'AppBundle:Email:picsure/invalid.html.twig',
                ['policy' => $policy, 'additional_message' => $request->get('message')],
                'AppBundle:Email:picsure/invalid.txt.twig',
                ['policy' => $policy, 'additional_message' => $request->get('message')]
            );
            try {
                $push = $this->get('app.push');
                // @codingStandardsIgnoreStart
                $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                    'Sorry, your phone validation was not successful: %s',
                    $request->get('message')
                ), null, null, $policy);
                // @codingStandardsIgnoreEnd
            } catch (\Exception $e) {
                $this->get('logger')->error(sprintf("Error in pic-sure push."), ['exception' => $e]);
            }

            $picsureFiles = $policy->getPolicyPicSureFiles();
            if (count($picsureFiles) > 0) {
                $this->get('event_dispatcher')->dispatch(
                    PicsureEvent::EVENT_INVALID,
                    new PicsureEvent($policy, $picsureFiles[0])
                );
            } else {
                $this->get('logger')->error(sprintf("Missing picture file in policy %s.", $policy->getId()));
            }

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

    /**
     * @Route("/optimise-csv", name="admin_optimise_csv")
     * @Template
     */
    public function optimiseCsvAction(Request $request)
    {
        $dm = $this->getManager();
        $s3 = $this->get('aws.s3');
        /** @var S3FileRepository */
        $s3FileRepo = $dm->getRepository(S3File::class);
        $uploadedFile = new ManualAffiliateFile();
        $uploadForm = $this->get('form.factory')
            ->createNamedBuilder('upload_form', ManualAffiliateFileType::class, $uploadedFile)
            ->getForm();
        if ($request->getMethod() === 'POST') {
            if ($request->request->has('upload_form')) {
                $uploadForm->handleRequest($request);
                if ($uploadForm->isSubmitted() && $uploadForm->isValid()) {
                    try {
                        $affiliateService = $this->get('app.affiliate');
                        $affiliateService->processOptimiseCsv($uploadedFile);
                        $this->addFlash('success', 'File Processed');
                    } catch (\Exception $e) {
                        $this->addFlash('error', $e->getMessage().", file not saved.");
                    }
                    return new RedirectResponse($this->generateUrl('admin_optimise_csv'));
                }
            }
        }
        return [
            'upload_form' => $uploadForm->createView(),
            'files' => $s3FileRepo->getAllFilesToDate(null, 'manualAffiliate')
        ];
    }

    /**
     * @Route("/affiliate", name="admin_affiliate")
     * @Template
     */
    public function affiliateAction()
    {
        $dm = $this->getManager();
        $companyRepo = $dm->getRepository(AffiliateCompany::class);
        return ['companies' => $companyRepo->findAll()];
    }

    /**
     * @Route("/affiliate/create", name="admin_affiliate_create")
     * @Template
     */
    public function affiliateFormAction(Request $request)
    {
        $companyForm = $this->get('form.factory')
            ->createNamedBuilder('affiliate_form', AffiliateType::class)
            ->setAction($this->generateUrl('admin_affiliate_create'))
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('affiliate_form')) {
                $companyForm->handleRequest($request);
                if ($companyForm->isValid()) {
                    $company = new AffiliateCompany();
                    $company->setName($this->getDataString($companyForm->getData(), 'name'));
                    $address = new Address();
                    $address->setLine1($this->getDataString($companyForm->getData(), 'address1'));
                    $address->setLine2($this->getDataString($companyForm->getData(), 'address2'));
                    $address->setLine3($this->getDataString($companyForm->getData(), 'address3'));
                    $address->setCity($this->getDataString($companyForm->getData(), 'city'));
                    $postcode = $this->getDataString($companyForm->getData(), 'postcode');
                    try {
                        $address->setPostcode($postcode);
                    } catch (\InvalidArgumentException $e) {
                        $this->addFlash('error', "{$postcode} is not a valid post code.");
                    }
                    $company->setAddress($address);
                    $company->setDays($this->getDataString($companyForm->getData(), 'days'));
                    $company->setChargeModel($this->getDataString($companyForm->getData(), 'chargeModel'));
                    if ($company->getChargeModel() == AffiliateCompany::MODEL_ONGOING) {
                        $company->setRenewalDays($this->getDataString($companyForm->getData(), 'renewalDays'));
                    }
                    $company->setCampaignSource($this->getDataString($companyForm->getData(), 'campaignSource'));
                    $company->setCampaignName($this->getDataString($companyForm->getData(), 'campaignName'));
                    $company->setLeadSource($this->getDataString($companyForm->getData(), 'leadSource'));
                    $company->setLeadSourceDetails(
                        $this->getDataString($companyForm->getData(), 'leadSourceDetails')
                    );
                    $company->setCPA($this->getDataString($companyForm->getData(), 'cpa'));
                    $dm = $this->getManager();
                    $dm->persist($company);
                    $dm->flush();
                    $this->addFlash('success', 'Added affiliate');
                } else {
                    $this->addFlash('error', sprintf('Unable to add company. %s', (string) $companyForm->getErrors()));
                }
                return new RedirectResponse($this->generateUrl('admin_affiliate'));
            }
        }
        return ['form' => $companyForm->createView()];
    }

    /**
     * @Route("/affiliate/{id}", name="admin_affiliate_overview")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliateOverviewController($id)
    {
        $dm = $this->getManager();
        $affiliateRepository = $dm->getRepository(AffiliateCompany::class);
        $affiliate = $affiliateRepository->find($id);
        return [
            "affiliate" => $affiliate,
            "overview" => true
        ];
    }

    /**
     * @Route("/affiliate/{id}/promotion", name="admin_affiliate_add_promotion")
     * @Template
     */
    public function affiliatePromotionFormAction(Request $request, $id)
    {
        $user = $this->getUser();
        $dm = $this->getManager();
        $affiliateRepository = $dm->getRepository(AffiliateCompany::class);
        $promotionRepository = $dm->getRepository(Promotion::class);
        $affiliate = $affiliateRepository->find($id);
        $promotions = $promotionRepository->findBy(["active" => true]);
        $promotionList = [];
        foreach ($promotions as $promotion) {
            $promotionList[$promotion->getName()] = $promotion->getId();
        }
        $choiceParams = ["choices" => $promotionList];
        if ($affiliate->getPromotion()) {
            $choiceParams["data"] = $affiliate->getPromotion()->getId();
        }
        $promotionForm = $this->get("form.factory")
            ->createNamedBuilder("promotion_form")
            ->add("promotion", ChoiceType::class, $choiceParams)
            ->add("next", SubmitType::class)
            ->setAction($this->generateUrl("admin_affiliate_add_promotion", ["id" => $id]))
            ->getForm();
        if ("POST" === $request->getMethod()) {
            if (!$affiliate) {
                $this->addFlash("error", "{$id} is not the id of an affiliate company.");
            } elseif ($request->request->has("promotion_form")) {
                $promotionForm->handleRequest($request);
                if ($promotionForm->isValid()) {
                    $promotion = $promotionRepository->find($promotionForm->getData()["promotion"]);
                    if ($affiliate->getPromotion() != $promotion) {
                        $oldPromotion = $affiliate->getPromotion();
                        $affiliate->setPromotion($promotion);
                        // History Information.
                        if ($oldPromotion) {
                            $affiliate->createNote($user, "Promotion Removed: ".$oldPromotion->getName());
                        }
                        $affiliate->createNote($user, "Promotion Added: ".$promotion->getName());
                        // Finalise.
                        $this->getManager()->flush();
                        $this->addFlash("success", "Added promotion to affiliate.");
                    }
                }
            }
            return new RedirectResponse($this->generateUrl("admin_affiliate_overview", ["id" => $id]));
        }
        return ['form' => $promotionForm->createView()];
    }

    /**
     * @Route("/affiliate/charge/{id}/{year}/{month}", name="admin_affiliate_charge")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliateChargeAction($id, $year = null, $month = null)
    {
        $now = \DateTime::createFromFormat('U', time());
        $year = $year ?: $now->format('Y');
        $month = $month ?: $now->format('m');
        $date = \DateTime::createFromFormat("Y-m-d", sprintf('%d-%d-01', $year, $month));

        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $chargeRepo = $dm->getRepository(Charge::class);
        $affiliate = $affiliateRepo->find($id);
        if ($affiliate) {
            $charges = $chargeRepo->findMonthly($date, 'affiliate', false, $affiliate);
            return ['affiliate' => $affiliate,
                'charges' => $charges,
                'cost' => $affiliate->getCpa() * count($charges),
                'month' => $month,
                'year' => $year,
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/affiliate/pending/{id}", name="admin_affiliate_pending")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliatePendingAction($id)
    {
        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $affiliate = $affiliateRepo->find($id);
        $affiliateService = $this->get("app.affiliate");
        if ($affiliate) {
            $pending = $affiliateService->getMatchingUsers(
                $affiliate,
                new \DateTime(),
                [User::AQUISITION_NEW, User::AQUISITION_PENDING],
                $affiliate->getChargeModel() == AffiliateCompany::MODEL_ONE_OFF
            );
            $days = [];
            foreach ($pending as $user) {
                $days[$user->getId()] = $affiliateService->daysToAquisition($affiliate, $user);
            }
            return [
                'affiliate' => $affiliate,
                'pending' => $affiliateService->getMatchingUsers(
                    $affiliate,
                    new \DateTime(),
                    [User::AQUISITION_NEW, User::AQUISITION_PENDING],
                    $affiliate->getChargeModel() == AffiliateCompany::MODEL_ONE_OFF
                ),
                'days' => $days
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/affiliate/potential/{id}", name="admin_affiliate_potential")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliatePotentialAction($id)
    {
        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $affiliate = $affiliateRepo->find($id);
        $affiliateService = $this->get("app.affiliate");
        if ($affiliate) {
            return [
                'affiliate' => $affiliate,
                'potential' => $affiliateService->getMatchingUsers($affiliate, null, [User::AQUISITION_POTENTIAL])
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/affiliate/lost/{id}", name="admin_affiliate_lost")
     * @Template("AppBundle:AdminEmployee:affiliateCharge.html.twig")
     */
    public function affiliateLostAction($id)
    {
        $dm = $this->getManager();
        $affiliateRepo = $dm->getRepository(AffiliateCompany::class);
        $affiliate = $affiliateRepo->find($id);
        $affiliateService = $this->get("app.affiliate");
        if ($affiliate) {
            return [
                'affiliate' => $affiliate,
                'lost' => $affiliateService->getMatchingUsers($affiliate, null, [User::AQUISITION_LOST])
            ];
        } else {
            return ['error' => 'Invalid URL, given ID does not correspond to an affiliate.'];
        }
    }

    /**
     * @Route("/promotions", name="admin_promotions")
     * @Template("AppBundle:AdminEmployee:promotions.html.twig")
     */
    public function promotionsAction(Request $request)
    {
        $dm = $this->getManager();
        $promotionForm = $this->get('form.factory')
            ->createNamedBuilder('promotionForm', PromotionType::class, null, ['method' => 'POST'])
            ->getForm();
        $promotionForm->handleRequest($request);
        if ($promotionForm->isSubmitted() && $promotionForm->isValid()) {
            $promotion = $promotionForm->getData();
            $promotion->setStart(new \DateTime());
            $promotion->setActive(true);
            $dm->persist($promotion);
            $dm->flush();
            $this->addFlash('success', 'Added Promotion');
            return new RedirectResponse($this->generateUrl('admin_promotions'));
        }
        // TODO: order them so that inactive come after active, but beside that it's ordered by newness.
        $promotionRepository = $dm->getRepository(Promotion::class);
        $promotions = $promotionRepository->findAll();
        return ["promotions" => $promotions, "promotionForm" => $promotionForm->createView()];
    }

    /**
     * @Route("/promotion/{id}", name="admin_promotion")
     * @Template("AppBundle:AdminEmployee:promotion.html.twig")
     */
    public function promotionAction($id)
    {
        $dm = $this->getManager();
        $promotionRepository = $dm->getRepository(Promotion::class);
        $promotion = $promotionRepository->find($id);
        return ["promotion" => $promotion];
    }
}
