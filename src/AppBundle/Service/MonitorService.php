<?php
namespace AppBundle\Service;

use AppBundle\Classes\Salva;
use AppBundle\Document\Cashback;
use AppBundle\Document\Claim;
use AppBundle\Document\Connection\Connection;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\AccessPayFile;
use AppBundle\Document\File\DaviesFile;
use AppBundle\Document\File\DirectGroupFile;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Form\Bacs;
use AppBundle\Document\MultiPay;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\User;
use AppBundle\Exception\MonitorException;
use AppBundle\Repository\BacsPaymentRepository;
use AppBundle\Repository\CashbackRepository;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PolicyRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\MongoDB\LoggableCollection;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class MonitorService
{
    use DateTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var \Predis\Client */
    protected $redis;

    /** @var IntercomService */
    protected $intercom;

    /** @var MixpanelService */
    protected $mixpanel;

    /** @var JudopayService */
    protected $judopay;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param \Predis\Client  $redis
     * @param IntercomService $intercom
     * @param MixpanelService $mixpanel
     * @param JudopayService  $judopay
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        \Predis\Client $redis,
        IntercomService $intercom,
        MixpanelService $mixpanel,
        JudopayService $judopay
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->intercom = $intercom;
        $this->mixpanel = $mixpanel;
        $this->judopay = $judopay;
    }

    public function run($name, $details = null)
    {
        if (method_exists($this, $name)) {
            if ($details) {
                return call_user_func([$this, $name], $details);
            }

            return call_user_func([$this, $name]);
        }

        throw new \Exception(sprintf('Unknown monitor %s', $name));
    }

    public function multipay()
    {
        /** @var PolicyRepository $repo */
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findBy(['status' => Policy::STATUS_MULTIPAY_REQUESTED]);
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $multipays = $policy->getUser()->getMultiPays();
            foreach ($multipays as $multipay) {
                if ($multipay->getPolicy()->getId() == $policy->getId()
                    && $multipay->getStatus() == MultiPay::STATUS_ACCEPTED) {
                    throw new MonitorException(sprintf(
                        'Policy %s has multipay requested, yet multipay status is approved',
                        $policy->getPolicyNumber()
                    ));
                }
            }
        }

        return sprintf('All multipay requested policies have correct status');
    }

    public function claimsReplacementPhone()
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findMissingReceivedDate();
        $now = \DateTime::createFromFormat('U', time());
        foreach ($claims as $claim) {
            /** @var Claim $claim */
            $replacementDate = $claim->getPolicy()->getImeiReplacementDate();
            if ($replacementDate &&
                $now->getTimestamp() - $replacementDate->getTimestamp() > 3600) {
                throw new MonitorException(sprintf(
                    'Claim %s Policy %s is missing replacement phone',
                    $claim->getNumber(),
                    $claim->getPolicy()->getPolicyNumber()
                ));
            }
        }
    }

    public function claimsSettledUnprocessed()
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findSettledUnprocessed();

        /** @noinspection LoopWhichDoesNotLoopInspection */
        foreach ($claims as $claim) {
            /** @var Claim $claim */
            throw new MonitorException(sprintf(
                'Claim %s is settled, but has not been processed (e.g. pot updated)',
                $claim->getNumber()
            ));
        }
    }

    public function cashbackPastDue()
    {
        /** @var CashbackRepository $repo */
        $repo = $this->dm->getRepository(Cashback::class);
        $cashbacks = $repo->getLateCashback();
        foreach ($cashbacks as $cashback) {
            /** @var Cashback $cashback */
            throw new MonitorException(sprintf(
                'Cashback for policy id:%s (%s) is late (%s)',
                $cashback->getPolicy()->getId(),
                $cashback->getPolicy()->getSalvaPolicyNumber(),
                $cashback->getCreatedDate()->format('d-m-Y')
            ));
        }
    }

    public function cashbackIncorrectStatus()
    {
        /** @var CashbackRepository $repo */
        $repo = $this->dm->getRepository(Cashback::class);
        $cashbacks = $repo->findBy(['status' => Cashback::STATUS_PENDING_CLAIMABLE]);
        foreach ($cashbacks as $cashback) {
            $policy = $cashback->getPolicy();
            $correctStatus = false;

            /** @var Cashback $cashback */
            // Cashback pending claimable may be present for active policies as well
            if (in_array($policy->getStatus(), [
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
                Policy::STATUS_EXPIRED_CLAIMABLE,
            ])) {
                $correctStatus = true;
            } elseif ($policy->getStatus() == Policy::STATUS_CANCELLED && $policy->isRefundAllowed()) {
                $correctStatus = true;
            }

            if (!$correctStatus) {
                throw new MonitorException(sprintf(
                    'Cashback status (claimable) for policy id:%s (%s) is incorrect. Policy status %s',
                    $policy->getId(),
                    $policy->getSalvaPolicyNumber(),
                    $policy->getStatus()
                ));
            }
        }

        $cashbacks = $repo->findBy(['status' => Cashback::STATUS_PENDING_WAIT_CLAIM]);
        foreach ($cashbacks as $cashback) {
            if ($cashback->getPolicy()->getStatus() != Policy::STATUS_EXPIRED_WAIT_CLAIM) {
                throw new MonitorException(sprintf(
                    'Cashback status (wait claim) for policy id:%s (%s) is incorrect. Policy status %s',
                    $cashback->getPolicy()->getId(),
                    $cashback->getPolicy()->getSalvaPolicyNumber(),
                    $cashback->getPolicy()->getStatus()
                ));
            }
        }
    }

    public function daviesImport()
    {
        $fileRepo = $this->dm->getRepository(DaviesFile::class);
        $this->hasRecentS3Import($fileRepo);
    }

    public function directgroupImport()
    {
        $fileRepo = $this->dm->getRepository(DirectGroupFile::class);
        $this->hasRecentS3Import($fileRepo);
    }

    /**
     * Check the 'file repo' - ensure a matching document was put there in the last day
     */
    private function hasRecentS3Import($fileRepo)
    {
        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;
        if (!$successFile) {
            throw new MonitorException('Unable to find any successful imports');
        }

        $now = $this->startOfDay(\DateTime::createFromFormat('U', time()));
        $diff = $now->diff($successFile->getCreated());
        if ($diff->days >= 1) {
            $fileDateTime = $successFile->getCreated()->format(\DateTime::ATOM);
            throw new MonitorException('Last successful import on ' . $fileDateTime);
        }
    }

    /**
     * Around 5 Apr 2017, a user who purchase a policy (company policy - setup in backend)
     * failed to have a policy premium > 0 (e.g. it was 0) and hence received an email
     * about not purchasing the policy.
     *
     * Monitor should find policies that have been recently created and validate that intercom
     * has a > 0 premium to ensure this behaviour doesn't occur again
     */
    public function intercomPolicyPremium()
    {
        /** @var PhonePolicyRepository $repo */
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $oneDay = \DateTime::createFromFormat('U', time());
        $oneDay = $oneDay->sub(new \DateInterval('P1D'));

        // delay 10 minutes to allow time to sync
        $tenMinutes = \DateTime::createFromFormat('U', time());
        $tenMinutes = $tenMinutes->sub(new \DateInterval('PT10M'));
        $updatedPolicies = $repo->findAllStatusUpdatedPolicies($oneDay, $tenMinutes);
        $errors = [];
        foreach ($updatedPolicies as $policy) {
            /** @var Policy $policy */
            /** @var mixed $intercomUser */
            $intercomUser = $this->intercom->getIntercomUser($policy->getUser());
            if (is_object($intercomUser)) {
                /** @var mixed $intercomUser */
                /** @var mixed $attributes */
                $attributes = $intercomUser->{'custom_attributes'};
                // only active/unpaid policies and definitely not cancelled
                if ($policy->isActive(true) && $attributes->Premium <= 0) {
                    $this->intercom->queue($policy->getUser());
                    $errors[] = sprintf(
                        'Intercom out of sync: %s has a 0 premium in intercom, yet has a policy. Requeued.',
                        $policy->getUser()->getEmail()
                    );
                } elseif (!$policy->isActive(true) && $attributes->Premium > 0) {
                    // check what the expected premium for the user should be
                    // to ensure we're not checking an older expired policy where the is a renewal in place
                    if ($policy->getUser()->getAnalytics()['annualPremium'] > 0) {
                        continue;
                    }

                    $this->intercom->queue($policy->getUser());
                    $errors[] = sprintf(
                        'Intercom out of sync: %s has a premium in intercom, but policy is not active. Requeued.',
                        $policy->getUser()->getEmail()
                    );
                }
            }
        }

        if ($errors) {
            throw new MonitorException(json_encode($errors));
        }
    }

    public function mixpanelUserCount()
    {
        // acutal 100,000 for plan
        $maxUsers = 90000;
        $total = 0;
        $count = 0;
        while ($total == 0) {
            try {
                $total = $this->mixpanel->getUserCount();
            } catch (\Exception $e) {
                if ($count > 15) {
                    throw $e;
                }
                sleep(2);
            }

            $count++;
        }

        if ($total > $maxUsers) {
            throw new MonitorException(sprintf('User count %d too high (warning %d)', $total, $maxUsers));
        }
    }

    public function policyImeiUpdatedFromClaim()
    {
        $repo = $this->dm->getRepository(Claim::class);
        // TODO: For now, checking all claims is fine - eventually will want to filter out older claims
        // however, we do want to include more recently closed claims as that's the bit that can have issues
        // claim is closed prior to being able to update imei
        $claims = $repo->findAll();
        foreach ($claims as $claim) {
            /** @var Claim $claim */
            $policy = $claim->getPolicy();
            // Only concerned about active (or unpaid) policies here
            if (!in_array($policy->getStatus(), [
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
            ])) {
                continue;
            }

            if ($lastestClaimForPolicy = $policy->getLatestClaim(true)) {
                if ($lastestClaimForPolicy->isIgnoreWarningFlagSet(Claim::WARNING_FLAG_CLAIMS_IMEI_MISMATCH)) {
                    continue;
                }
                if ($policy->getImei() != $lastestClaimForPolicy->getReplacementImei()) {
                    throw new MonitorException(sprintf(
                        'Policy %s has a claim w/replacement imei that does not match current imei',
                        $policy->getId()
                    ));
                }
            }
        }
    }

    public function judopayReceipts()
    {
        $results = $this->judopay->getTransactions(50, false);
        if (isset($results['additional-payments']) && count($results['additional-payments']) > 0) {
            // @codingStandardsIgnoreStart
            throw new MonitorException(sprintf(
                'Judopay is recording more than 1 payment against a policy that indicates a scheduled payment issue. %s',
                json_encode($results['additional-payments'])
            ));
            // @codingStandardsIgnoreEnd
        }

        if (isset($results['missing']) && count($results['missing']) > 0) {
            // @codingStandardsIgnoreStart
            throw new MonitorException(sprintf(
                'Judopay is missing database payment records that indices a mobile payment was received, but not recorded. %s',
                json_encode($results['missing'])
            ));
            // @codingStandardsIgnoreEnd
        }

        if (isset($results['invalid']) && count($results['invalid']) > 0) {
            // @codingStandardsIgnoreStart
            throw new MonitorException(sprintf(
                'Judopay has invalid database payment records. %s',
                json_encode($results['invalid'])
            ));
            // @codingStandardsIgnoreEnd
        }
    }

    public function checkPicSureStatusManual()
    {
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $manual = $repo->findOneBy(['picSureStatus' => PhonePolicy::PICSURE_STATUS_MANUAL]);
        if ($manual) {
            throw new MonitorException('There is a policy that is waiting for pic-sure approval');
        }
    }

    public function checkMixpanelQueue()
    {
        $count = $this->mixpanel->countQueue();
        if ($count > 150) {
            throw new MonitorException(sprintf('There are %d outstanding messages in the queue', $count));
        }
    }

    public function checkIntercomQueue()
    {
        $count = $this->intercom->countQueue();
        if ($count > 150) {
            throw new MonitorException(sprintf('There are %d outstanding messages in the queue', $count));
        }
    }

    public function policyTerms()
    {
        $repo = $this->dm->getRepository(PolicyTerms::class);
        $terms = $repo->findAll();
        $termVersions = [];
        foreach ($terms as $term) {
            if (!in_array($term->getVersion(), array_keys(PolicyTerms::$allVersions))) {
                throw new MonitorException(sprintf(
                    'Policy Terms %s is in db but not present in code',
                    $term->getVersion()
                ));
            }
            $termVersions[] = $term->getVersion();
        }
        foreach (PolicyTerms::$allVersions as $versionName => $version) {
            if (!in_array($versionName, $termVersions)) {
                throw new MonitorException(sprintf(
                    'Policy Terms %s is in code but not present in db',
                    $versionName
                ));
            }
        }
    }

    public function bacsSubmitted()
    {
        $repo = $this->dm->getRepository(AccessPayFile::class);
        /** @var AccessPayFile $unsubmitted */
        $unsubmitted = $repo->findOneBy(['status' => AccessPayFile::STATUS_PENDING]);
        if ($unsubmitted) {
            throw new MonitorException(sprintf(
                'There is a bacs file (%s) that is pending',
                $unsubmitted->getId()
            ));
        }
    }

    public function bankHolidays(\DateTime $date = null)
    {
        if (!$date) {
            $date = \DateTime::createFromFormat('U', time());
        }
        $holidays = DateTrait::getBankHolidays();
        usort($holidays, function ($a, $b) {
            return $a < $b;
        });
        $holiday = $holidays[0];
        //print_r($holiday);
        $diff = $holiday->diff($date);
        //print_r($diff);
        if ($diff->days < 90) {
            throw new MonitorException(sprintf(
                'Last holiday %s is coming up. Add more holidays',
                $holiday->format('d/m/Y')
            ));
        }
    }

    public function bacsMandates()
    {
        /** @var UserRepository $repo */
        $repo = $this->dm->getRepository(User::class);
        $users = $repo->findPendingMandates()->getQuery()->execute();
        if (count($users) > 0) {
            throw new MonitorException(sprintf(
                'There are %d bacs mandates waiting approval',
                count($users)
            ));
        }
    }

    public function bacsPayments($details = false)
    {
        $errors = [];
        $twoDays = \DateTime::createFromFormat('U', time());
        $twoDays = $this->subBusinessDays($twoDays, 2);
        /** @var BacsPaymentRepository $paymentsRepo */
        $paymentsRepo = $this->dm->getRepository(BacsPayment::class);
        foreach ($paymentsRepo->findPayments(\DateTime::createFromFormat('U', time())) as $payment) {
            /** @var BacsPayment $payment */
            if ($payment->getSource() == Payment::SOURCE_ADMIN) {
                continue;
            }

            // TODO: Fixme
            // There's a horrible hack on refund listener where payments must be successful for salva refund amounts
            // to work out.
            if ($payment->getAmount() < 0 && $payment->isSuccess() &&
                $payment->getStatus() == BacsPayment::STATUS_PENDING && $payment->getDate() < $twoDays) {
                continue;
            }

            if ($payment->canAction(\DateTime::createFromFormat('U', time()))) {
                $msg = sprintf('There are bacs payments waiting actioning: %s', $payment->getId());
                $errors[] = $msg;
                if (!$details) {
                    throw new MonitorException($msg);
                }
            }
        }

        if (count($errors) > 0) {
            throw new MonitorException(json_encode($errors, JSON_PRETTY_PRINT));
        }
    }

    public function bacsPendingPayments()
    {
        /** @var BacsPaymentRepository $paymentsRepo */
        $paymentsRepo = $this->dm->getRepository(BacsPayment::class);

        /** @var BacsPayment[] $unpaid */
        $unpaid = $paymentsRepo->findUnprocessedPaymentsOlderThanDays(
            [BacsPayment::STATUS_PENDING],
            BacsPayment::DAYS_PROCESSING
        );

        /** @noinspection LoopWhichDoesNotLoopInspection */
        foreach ($unpaid as $payment) {
            /** @var BacsPayment $payment */
            throw new MonitorException('There are pending bacs payments waiting: ' . $payment->getId());
        }

        /** @var BacsPayment[] $unpaid */
        $unpaid = $paymentsRepo->findUnprocessedPaymentsOlderThanDays(
            [BacsPayment::STATUS_GENERATED],
            BacsPayment::DAYS_REVERSE
        );

        /** @noinspection LoopWhichDoesNotLoopInspection */
        foreach ($unpaid as $payment) {
            /** @var BacsPayment $payment */
            throw new MonitorException('There are generated bacs payments waiting: ' . $payment->getId());
        }
    }

    public function pendingPolicies()
    {
        /** @var PolicyRepository $repo */
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findBy(['status' => Policy::STATUS_PENDING]);
        $oneHourAgo = \DateTime::createFromFormat('U', time());
        $oneHourAgo = $oneHourAgo->sub(new \DateInterval('PT1H'));
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            if (!$policy->getStatusUpdated() || $policy->getStatusUpdated() < $oneHourAgo) {
                throw new MonitorException(sprintf(
                    'Policy %s is pending over 1 hour ago',
                    $policy->getId()
                ));
            }
        }
    }

    public function missingPaymentCommissions()
    {
        // a few payments were missing, but had a later payment to adjust the missing commission figure
        $commissionValidationPaymentExclusions = [
            new \MongoId('5a8a7f55c084c74d28413471'),
            new \MongoId('5aa6dec854e50f46ab3e8874'),
            new \MongoId('5ac61e7a7c62216654636bea'),
            new \MongoId('5ad5e80e75435e73e152874f'),
            new \MongoId('5bd0381fedc29544427b31ab'),
            new \MongoId('5bd03821edc29544427b31af'),
        ];

        $commissionValidationPolicyExclusions = [];
        foreach (Salva::$commissionValidationExclusions as $item) {
            $commissionValidationPolicyExclusions[] = new \MongoId($item);
        }

        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(Payment::class);
        $payments = $repo->findBy([
            'success' => true,
            'totalCommission' => null,
            'type' => ['$nin' => ['potReward', 'sosurePotReward', 'policyDiscount']],
            'amount' => ['$gt' => 2], // we may need to take small offsets; if so, there would not be a commission
            'policy.$id' => ['$nin' => $commissionValidationPolicyExclusions],
            '_id' => ['$nin' => $commissionValidationPaymentExclusions],
            'date' => ['$gt' => new \DateTime('2017-11-01')],
        ]);

        /** @noinspection LoopWhichDoesNotLoopInspection */
        foreach ($payments as $payment) {
            /** @var Payment $payment */
            throw new MonitorException(sprintf(
                'Payment %s is missing a commission amount',
                $payment->getId()
            ));
        }
    }

    public function missingClaimHandler()
    {
        /** @var ClaimRepository $repo */
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findBy([
            'status' => ['$in' => [Claim::STATUS_SUBMITTED, Claim::STATUS_INREVIEW]],
            'handlingTeam' => null
        ]);

        /** @noinspection LoopWhichDoesNotLoopInspection */
        foreach ($claims as $claim) {
            throw new MonitorException(sprintf(
                'Claim %s is missing a handling team',
                $claim->getId()
            ));
        }
    }

    public function outstandingSubmittedClaims(array $tooOldSubmittedClaims = null)
    {
        if ($tooOldSubmittedClaims === null) {
            $tooOldSubmittedClaims = $this->findOldClaimsByStatus([Claim::STATUS_SUBMITTED], 2);
        }

        if (count($tooOldSubmittedClaims) > 0) {
            $sampleClaim = current($tooOldSubmittedClaims);
            $claimId = $sampleClaim->getId();
            throw new MonitorException(
                "At least one Claim (eg: {$claimId}) is still marked as 'Submitted' after 2 business days"
            );
        }
    }

    /**
     * Find claims that are more than or equal $n Business days since 'statusLastUpdated'
     */
    private function findOldClaimsByStatus(array $status, int $businessDaysOld = 2): array
    {
        /** @var ClaimRepository $claimRepository */
        $claimRepository = $this->dm->getRepository(Claim::class);
        $twoBusinessDaysAgo = $this->subBusinessDays(\DateTime::createFromFormat('U', time()), $businessDaysOld);

        return $claimRepository->findBy(
            [
                'status' => ['$in' => $status],
                'statusLastUpdated' => ['$lte' => $twoBusinessDaysAgo],
            ]
        );
    }

    public function salvaPolicy()
    {
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findBy([
            'policyNumber' => new \MongoRegex('/Mob\/*/'),
            'salvaPolicyResults' => ['$lte' => ['$size' => 0]]
        ]);

        if (count($policies) > 0) {
            throw new MonitorException(
                "Policy {$policies[0]->getPolicyNumber()} has no salva policy results!"
            );
        }
    }

    public function invalidPolicy()
    {
        $repo = $this->dm->getRepository(Policy::class);

        $policies = $repo->findBy([
            'policyNumber' => new \MongoRegex('/INVALID\/*/'),
            'salvaPolicyResults' => ['$lte' => ['$size' => 0]]
        ]);

        if (count($policies) == 0) {
            throw new MonitorException(
                "Failed to find any policies!"
            );
        }
    }

    public function salvaStatus()
    {
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findBy([
            'policyNumber' => new \MongoRegex('/Mob\/*/'),
            'salvaStatus' => ['$nin' => [
                null,
                SalvaPhonePolicy::SALVA_STATUS_ACTIVE,
                SalvaPhonePolicy::SALVA_STATUS_CANCELLED]
            ]
        ]);

        if (count($policies) > 0) {
            foreach ($policies as $policy) {
                throw new MonitorException(
                    "Policy {$policy->getPolicyNumber()} is pending review"
                );
            }
        }
    }

    public function policyFiles()
    {
        $repo = $this->dm->getRepository(Policy::class);
        $policyFiles = $repo->findBy([
            'policyNumber' => new \MongoRegex('/Mob\/*/'),
            '$or' => [
                ['policyFiles' => null],
                ['policyFiles' => ['$size' => 0]]
            ]
        ]);

        if (count($policyFiles) > 0) {
            foreach ($policyFiles as $policy) {
                throw new MonitorException(
                    "Policy {$policy->getPolicyNumber()} has no files"
                );
            }
        }
    }

    public function policyPending()
    {
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findBy([
            'policyNumber' => new \MongoRegex('/Mob\/*/'),
            'status' => Policy::STATUS_PENDING
        ]);

        if (count($policies) > 0) {
            foreach ($policies as $policy) {
                throw new MonitorException(
                    "Policy {$policy->getPolicyNumber()} with email {$policy->getUser()->getEmail()} is pending"
                );
            }
        }
    }

    public function duplicateInvites()
    {
        $collection = $this->dm->getDocumentCollection(Invitation::class);
        $builder = $collection->createAggregationBuilder();

        $results = $builder
            ->group()
            ->field('_id')
            ->expression(
                $builder->expr()
                    ->field('email')
                    ->expression('$email')
                    ->field('policy')
                    ->expression('$policy')
            )
            ->field('count')
            ->sum(1)
            ->match()
            ->field('count')
            ->gt(1)
            ->execute(['cursor' => []]);

        if (count($results) > 0) {
            foreach ($results as $result) {
                throw new MonitorException(sprintf(
                    "Found duplicate Invites on email %s",
                    $result['_id']['email']
                ));
            }
        }
    }

    public function checkSoSureRoles()
    {
        $collections = $this->dm->getDocumentDatabase(Policy::class)->listCollections();

        if (count($collections) == 0) {
            throw new MonitorException(
                "No collections found in so-sure database!"
            );
        }

        return $collections;
    }

    public function checkSoSureRole($col)
    {
        $db_name = $col->getDatabase()->getName();

        $res = $this->dm->getConnection()->selectDatabase($db_name)->command([
            'rolesInfo' => 'so-sure-user',
            'showPrivileges' => true
            ]);

        $foundPriv = false;
        if ($res['roles'] && count($res['roles']) > 0) {
            $privileges = $res['roles'][0]['privileges'];

            foreach ($privileges as $priv) {
                if ($col->getName() === $priv['resource']['collection']) {
                    $foundPriv = true;

                    $find = in_array('find', $priv['actions']);
                    $update = in_array('update', $priv['actions']);
                    $remove = in_array('remove', $priv['actions']);

                    if (!($update and $remove) && !$find) {
                        throw new MonitorException(
                            "Missing find/update-remove privledge for {$col->getName()}"
                        );
                    }
                }
            }
        }

        if (!$foundPriv) {
            throw new MonitorException(
                "Missing privledges for {$col->getName()}"
            );
        }
    }

    public function checkAllUserRolePriv()
    {
        $collections = $this->checkSoSureRoles();

        foreach ($collections as $col) {
            $this->checkSoSureRole($col);
        }
    }

    public function checkExpiration()
    {
        $results = $this->dm->createQueryBuilder(Policy::class)
            ->field('status')
            ->in([Policy::STATUS_UNPAID, Policy::STATUS_ACTIVE])
            ->field('end')
            ->lt(\DateTime::createFromFormat('U', time()))
            ->getQuery()
            ->execute();

        foreach ($results as $result) {
            throw new MonitorException(
                "Policy {$result->getPolicyNumber()} is active/unpaid but expired!" . PHP_EOL .
                "Expired since {$result->getEnd()->format('Y-M-D H:m')} !"
            );
        }
    }
}
