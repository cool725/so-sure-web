<?php
namespace AppBundle\Service;

use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Document\Payment\BacsIndemnityPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Stats;
use AppBundle\Repository\CashbackRepository;
use AppBundle\Repository\ClaimRepository;
use AppBundle\Repository\ConnectionRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\Invitation\InvitationRepository;
use AppBundle\Repository\PaymentRepository;
use AppBundle\Repository\PhonePolicyRepository;
use AppBundle\Repository\PhoneRepository;
use AppBundle\Repository\ScheduledPaymentRepository;
use AppBundle\Repository\StatsRepository;
use AppBundle\Repository\UserRepository;
use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Claim;
use AppBundle\Document\Cashback;
use AppBundle\Document\Lead;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\SoSurePayment;
use AppBundle\Document\Payment\PotRewardPayment;
use AppBundle\Document\Payment\SoSurePotRewardPayment;
use AppBundle\Document\Payment\PolicyDiscountPayment;
use AppBundle\Document\Payment\PolicyDiscountRefundPayment;
use AppBundle\Document\Payment\ChargebackPayment;
use AppBundle\Document\Payment\DebtCollectionPayment;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\User;
use DateInterval;
use DateTime;
use DateTimeZone;

class ReportingService
{
    const REPORT_KEY_FORMAT = 'Report:%s:%s:%s';
    const REPORT_CACHE_TIME = 3600;
    const REPORT_PERIODS = [
        'last 7 days' => ['start' => '7 days ago', 'end' => 'now'],
        'current month to date' => ['start' => 'first day of this month', 'end' => 'now'],
        'last month' => ['start' => 'first day of -1 month', 'end' => 'first day of this month', 'month' => true],
        'two months ago' => ['start' => 'first day of -2 month', 'end' => 'first day of -1 month', 'month' => true],
        'three months ago' => ['start' => 'first day of -3 month', 'end' => 'first day of -2 month', 'month' => true],
        'four months ago' => ['start' => 'first day of -4 month', 'end' => 'first day of -3 month', 'month' => true]
    ];
    const REPORT_PERIODS_DEFAULT = 'last 7 days';

    use DateTrait;
    use CurrencyTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    protected $excludedPolicyIds;

    /** @var string */
    protected $environment;

    /** @var Client */
    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $excludedPolicyIds
     * @param string          $environment
     * @param Client          $redis
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $excludedPolicyIds,
        $environment,
        Client $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->excludedPolicyIds = $excludedPolicyIds;
        $this->environment = $environment;
        $this->redis = $redis;
    }

    public function report($start, $end, $isKpi = false, $useCache = true)
    {
        $data = [];

        $redisKey = sprintf(
            self::REPORT_KEY_FORMAT,
            $start->format('Y-m-d.hi'),
            $end->format('Y-m-d.hi'),
            $isKpi ? 'kpiYES': 'kpiNo'
        );
        if ($useCache === true && $this->redis->exists($redisKey)) {
            return unserialize($this->redis->get($redisKey));
        }

        $data['dataFetchedAt'] = new DateTime();

        $totalEnd = null;
        if ($isKpi) {
            $totalEnd = $end;
        }
        $endActivation = clone $start;
        $endActivation = $endActivation->sub(SoSure::getActivationInterval());
        //$endActivation = $this->endOfDay($endActivation);
        $startActivation = clone $endActivation;
        $startActivation = $startActivation->sub(new \DateInterval('P7D'));
        //$startActivation = $this->startOfDay($startActivation);

        $endHardActivation = clone $start;
        $endHardActivation = $endHardActivation->sub(SoSure::getHardActivationInterval());
        //$endHardActivation = $this->endOfDay($endHardActivation);
        $startHardActivation = clone $endHardActivation;
        $startHardActivation = $startHardActivation->sub(new \DateInterval('P7D'));
        //$startHardActivation = $this->startOfDay($startHardActivation);

        $rolling12Months = clone $end;
        $rolling12Months = $rolling12Months->sub(new \DateInterval('P1Y'));
        $rolling3Months = clone $start;
        $rolling3Months = $rolling3Months->sub(new \DateInterval('P3M'));

        /** @var UserRepository $userRepo */
        $userRepo = $this->dm->getRepository(User::class);
        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        /** @var InvitationRepository $invitationRepo */
        $invitationRepo = $this->dm->getRepository(Invitation::class);
        /** @var ClaimRepository $claimsRepo */
        $claimsRepo = $this->dm->getRepository(Claim::class);
        $claims = $claimsRepo->findFNOLClaims($start, $end);
        $claimsTotals = Claim::sumClaims($claims);
        $approvedClaims = $claimsRepo->findApprovedClaims($start, $end);
        $approvedClaimsTotals = Claim::sumClaims($approvedClaims);
        $closedClaims = $claimsRepo->findClosedClaims($start, $end);
        $closedClaimsTotals = Claim::sumClaims($closedClaims);
        $allClaims = $claimsRepo->findAll();

        // fnol 30 day approved %
        $fnol30Claims = 0;
        $totalClaims = 0;
        $rolling12MonthClaims = [];
        foreach ($allClaims as $claim) {
            if ($claim->getRecordedDate() > $end) {
                continue;
            }

            $totalClaims++;
            if ($claim->isWithin30DaysOfPolicyInception()) {
                $fnol30Claims++;
            }
            if ($claim->getRecordedDate() >= $rolling12Months) {
                $rolling12MonthClaims[] = $claim;
            }
        }
        $data['fnol30Claims'] = $totalClaims ? 100 * $fnol30Claims / $totalClaims : "-";

        $data['claimAttribution'] = Claim::attributeClaims($approvedClaims);
        $data['claimAttributionText'] = $this->arrayToString($data['claimAttribution']);
        $data['rolling12MonthClaims'] = Claim::attributeClaims($rolling12MonthClaims, true, true);
        $data['rolling12MonthClaimAttributionText'] = $this->arrayToString($data['rolling12MonthClaims']);

        $data['newUsers'] = $userRepo->findNewUsers($start, $end)->count();

        $excludedPolicyIds = $this->getExcludedPolicyIds($isKpi);
        $excludedPolicies = $this->getExcludedPolicies($isKpi);

        $policyRepo->setExcludedPolicyIds($excludedPolicyIds);
        $invitationRepo->setExcludedPolicyIds($excludedPolicyIds);
        // Doesn't make sense to exclude as will skew all figures
        // $connectionRepo->setExcludedPolicyIds($excludedPolicyIds);

        $values = $policyRepo->getPotValues();
        //\Doctrine\Common\Util\Debug::dump($values);
        $pot = $values ? $values[0] : null;
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

        $newToDateDirectPolicies = $policyRepo->findAllNewPolicies(null, null, $end);

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

        $newToDateInvitationPolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_INVITATION, null, $end);

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

        $newToDateSCodePolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_SCODE, null, $end);

        $totalSCodePolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_SCODE);
        $data['totalSCodePolicies'] = $totalSCodePolicies->count();
        $data['totalSCodePoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalSCodePolicies);
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
            'Upgrade' => Policy::CANCELLED_UPGRADE,
            'Cooloff' => Policy::CANCELLED_COOLOFF,
            'BadRisk' => Policy::CANCELLED_BADRISK,
            'Dispossession' => Policy::CANCELLED_DISPOSSESSION,
            'Wreckage' => Policy::CANCELLED_WRECKAGE,
        ];
        foreach ($endingDataset as $key => $cancellationReason) {
            $data[sprintf('total%sPolicies', $key)] = $policyRepo->countAllEndingPolicies($cancellationReason);
            $data[sprintf('ending%sPolicies', $key)] = $policyRepo->countAllEndingPolicies(
                $cancellationReason,
                $start,
                $end,
                false
            );
            $data[sprintf('total%sFNOLPolicies', $key)] = count(
                $policyRepo->findAllEndingPolicies($cancellationReason, true)
            );
            $data[sprintf('ending%sFNOLPolicies', $key)] = count($policyRepo->findAllEndingPolicies(
                $cancellationReason,
                true,
                $start,
                $end
            ));
            $data[sprintf('desiredEnding%sPolicies', $key)] = count($policyRepo->findAllEndingPolicies(
                $cancellationReason,
                true,
                $start,
                $end,
                true
            ));
        }
        $data['endingExpiredPolicies'] = $policyRepo->countAllEndingPolicies(
            null,
            $start,
            $end,
            true
        );

        $data['totalEndingPoliciesAdjUpgrade'] = $data['totalEndingPolicies'] - $data['totalUpgradePolicies'];
        $data['endingEndingPoliciesAdjUpgrade'] = $data['endingEndingPolicies'] - $data['endingUpgradePolicies'];
        $data['totalEndingFNOLPoliciesAdjUpgrade'] = $data['totalEndingFNOLPolicies'] -
            $data['totalUpgradeFNOLPolicies'];
        $data['endingEndingFNOLPoliciesAdjUpgrade'] = $data['endingEndingFNOLPolicies'] -
            $data['endingUpgradeFNOLPolicies'];

        $renewalPolicies = $policyRepo->findAllEndingPolicies(
            null,
            false,
            $start,
            $end,
            false,
            false
        );
        $data['endingPoliciesRenewed'] = 0;
        $data['endingPoliciesRenewedDeclined'] = 0;
        foreach ($renewalPolicies as $renewalPolicy) {
            if ($renewalPolicy->isRenewed()) {
                $data['endingPoliciesRenewed']++;
            }
            if (!$renewalPolicy->canRenew(null, false) && in_array($renewalPolicy->getStatus(), [
                Policy::STATUS_EXPIRED,
                Policy::STATUS_EXPIRED_CLAIMABLE,
                Policy::STATUS_EXPIRED_WAIT_CLAIM,
             ])) {
                $data['endingPoliciesRenewedDeclined']++;
            }
        }

        $data['newPolicies'] = $policyRepo->countAllNewPolicies($end, $start);
        $data['newPoliciesAdjUpgrade'] = $data['newPolicies'] - $data['endingUpgradePolicies'];
        $data['newPoliciesAdjUpgradeRenewals'] = $data['newPolicies'] - $data['endingUpgradePolicies'] -
            $data['endingPoliciesRenewed'];
        $data['newPoliciesPremium'] = $data['newDirectPoliciesPremium'] + $data['newInvitationPoliciesPremium'] +
            $data['newSCodePoliciesPremium'];
        if ($data['newPolicies'] != 0) {
            $data['newPoliciesAvgPremium'] = $this->toTwoDp($data['newPoliciesPremium'] / $data['newPolicies']);
        } else {
            $data['newPoliciesAvgPremium'] = null;
        }

        $data['activatedPoliciesActivated'] = $policyRepo->countAllNewPolicies(
            $endActivation,
            $startActivation,
            Policy::METRIC_ACTIVATION
        );
        $data['activatedPoliciesTotal'] = $policyRepo->countAllNewPolicies(
            $endActivation,
            $startActivation
        );
        $data['activatedEndingUpgradePoliciesActivated'] = $policyRepo->countAllEndingPolicies(
            Policy::CANCELLED_UPGRADE,
            $startActivation,
            $endActivation,
            false,
            Policy::METRIC_ACTIVATION
        );
        $data['activatedEndingUpgradePoliciesTotal'] = $policyRepo->countAllEndingPolicies(
            Policy::CANCELLED_UPGRADE,
            $startActivation,
            $endActivation,
            false
        );
        $data['activatedEndingRenewalPoliciesActivated'] = $policyRepo->countAllEndingPolicies(
            null,
            $startActivation,
            $endActivation,
            true,
            Policy::METRIC_RENEWAL
        );
        $data['activatedEndingRenewalPoliciesTotal'] = $policyRepo->countAllEndingPolicies(
            null,
            $startActivation,
            $endActivation,
            true
        );

        $data['hardActivatedPoliciesActivated'] = $policyRepo->countAllNewPolicies(
            $endHardActivation,
            $startHardActivation,
            Policy::METRIC_HARD_ACTIVATION
        );
        $data['hardActivatedPoliciesTotal'] = $policyRepo->countAllNewPolicies(
            $endHardActivation,
            $startHardActivation
        );
        $data['hardActivatedEndingUpgradePoliciesActivated'] = $policyRepo->countAllEndingPolicies(
            Policy::CANCELLED_UPGRADE,
            $startHardActivation,
            $endHardActivation,
            false,
            Policy::METRIC_HARD_ACTIVATION
        );
        $data['hardActivatedEndingUpgradePoliciesTotal'] = $policyRepo->countAllEndingPolicies(
            Policy::CANCELLED_UPGRADE,
            $startHardActivation,
            $endHardActivation,
            false
        );
        $data['hardActivatedEndingRenewalPoliciesActivated'] = $policyRepo->countAllEndingPolicies(
            null,
            $startHardActivation,
            $endHardActivation,
            true,
            Policy::METRIC_RENEWAL
        );
        $data['hardActivatedEndingRenewalPoliciesTotal'] = $policyRepo->countAllEndingPolicies(
            null,
            $startHardActivation,
            $endHardActivation,
            true
        );

        $data['activatedPoliciesActivatedAdjUpgradeRenewals'] = $data['activatedPoliciesActivated'] -
            $data['hardActivatedEndingUpgradePoliciesActivated'] -
            $data['activatedEndingRenewalPoliciesActivated'];
        $data['activatedPoliciesTotalAdjUpgradeRenewals'] = $data['activatedPoliciesTotal'] -
            $data['hardActivatedEndingUpgradePoliciesTotal'] -
            $data['activatedEndingRenewalPoliciesTotal'];
        $data['activatedPoliciesActivatedAdjUpgradeRenewalsPercent'] =
            $data['activatedPoliciesTotalAdjUpgradeRenewals'] != 0 ?
            100 * $data['activatedPoliciesActivatedAdjUpgradeRenewals'] /
                $data['activatedPoliciesTotalAdjUpgradeRenewals'] :
            null;

        $data['hardActivatedPoliciesAdjUpgradeRenewals'] = $data['hardActivatedPoliciesActivated'] -
            $data['hardActivatedEndingUpgradePoliciesActivated'] -
            $data['hardActivatedEndingRenewalPoliciesActivated'];
        $data['hardActivatedPoliciesTotalAdjUpgradeRenewals'] = $data['hardActivatedPoliciesTotal'] -
            $data['hardActivatedEndingUpgradePoliciesTotal'] -
            $data['hardActivatedEndingRenewalPoliciesTotal'];
        $data['hardActivatedPoliciesAdjUpgradeRenewalsPercent'] =
            $data['hardActivatedPoliciesTotalAdjUpgradeRenewals'] != 0 ?
            100 * $data['hardActivatedPoliciesAdjUpgradeRenewals'] /
                $data['hardActivatedPoliciesTotalAdjUpgradeRenewals'] :
            null;

        $activePolicyHolders = [];
        $activePolicies = $policyRepo->findAllActivePoliciesByInstallments(null, $end);
        foreach ($activePolicies as $activePolicy) {
            if (!in_array($activePolicy->getUser()->getId(), $activePolicyHolders)) {
                $activePolicyHolders[] = $activePolicy->getUser()->getId();
            }
        }
        $data['totalActivePolicyHolders'] = count($activePolicyHolders);
        $data['totalActivePolicies'] = $policyRepo->countAllActivePolicies(null, $end);
        $data['totalPolicies'] = $policyRepo->countAllNewPolicies();
        $data['totalPoliciesAdjUpgrade'] = $data['totalPolicies'] - $data['totalUpgradePolicies'];
        $data['totalPoliciesPremium'] = $data['totalDirectPoliciesPremium'] + $data['totalInvitationPoliciesPremium'] +
            $data['totalSCodePoliciesPremium'];
        if ($data['totalPolicies'] != 0) {
            $data['totalPoliciesAvgPremium'] = $this->toTwoDp($data['totalPoliciesPremium'] / $data['totalPolicies']);
        } else {
            $data['totalPoliciesAvgPremium'] = null;
        }

        $data['totalActiveMonthlyPolicies'] = $policyRepo->countAllActivePoliciesByInstallments(12);
        $data['totalActiveYearlyPolicies'] = $policyRepo->countAllActivePoliciesByInstallments(1);

        // For reporting, connection numbers should be seen as a 2 way connection
        $data['newTotalConnections'] = $connectionRepo->count($start, $end, null) / 2;
        $data['newActiveConnections'] = $connectionRepo->count($start, $end, false) / 2;
        $data['newEndedConnections'] = $connectionRepo->count($start, $end, true) / 2;
        $data['totalTotalConnections'] = $connectionRepo->count(null, $totalEnd, null) / 2;
        $data['totalActiveConnections'] = $connectionRepo->count(null, $totalEnd, false) / 2;
        $data['totalEndedConnections'] = $connectionRepo->count(null, $totalEnd, true) / 2;

        $policies = [];
        foreach ($connectionRepo->connectedByDate(null, $end, null) as $connection) {
            if (!in_array($connection->getSourcePolicy()->getId(), $policies)) {
                $policies[] = $connection->getSourcePolicy()->getId();
            }
            if (!in_array($connection->getLinkedPolicy()->getId(), $policies)) {
                $policies[] = $connection->getLinkedPolicy()->getId();
            }
        }
        $data['newTotalPoliciesWithConnections'] = count($policies);

        $data['newInvitations'] = $invitationRepo->count(null, $start, $end);
        $data['totalInvitations'] = $invitationRepo->count(null, null, $totalEnd);

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

        $data['totalAvgHoursToConnect'] = $connectionRepo->avgHoursToConnect();

        $data['totalRunRate'] = $this->getTotalRunRate(
            $newToDateDirectPolicies,
            $newToDateInvitationPolicies,
            $newToDateSCodePolicies
        );

        $data = array_merge($data, $this->getCancelledAndPaymentOwed());
        $data = array_merge($data, $this->getPicSureData());

        $rolling12MonthClaims = $claimsRepo->findFNOLClaims($rolling12Months, $end);
        $rolling12MonthClaimsTotals = Claim::sumClaims($rolling12MonthClaims);
        $data['rolling-yearly-claims-totals'] = $rolling12MonthClaimsTotals['approved-settled'];

        $results = [
            'startActivation' => $startActivation,
            'endActivation' => $endActivation,
            'endActivationDisp' => (clone $endActivation)->sub(new \DateInterval('PT1S')),
            'startHardActivation' => $startHardActivation,
            'endHardActivation' => $endHardActivation,
            'endHardActivationDisp' => (clone $endHardActivation)->sub(new \DateInterval('PT1S')),
            'data' => $data,
            'excluded_policies' => $excludedPolicies,
            'claims' => $claimsTotals,
            'approvedClaims' => $approvedClaimsTotals,
            'closedClaims' => $closedClaimsTotals,
        ];
        $this->redis->setex($redisKey, self::REPORT_CACHE_TIME, serialize($results));

        return $results;
    }

    public function getCancelledAndPaymentOwed()
    {
        $data = [];

        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $data['cancelledAndPaymentOwed'] = 0;
        $cancelledPolicies = $policyRepo->findBy(['status' => Policy::STATUS_CANCELLED]);
        foreach ($cancelledPolicies as $cancelledPolicy) {
            if ($cancelledPolicy->isCancelledAndPaymentOwed()) {
                $data['cancelledAndPaymentOwed']++;
            }
        }

        return $data;
    }

    public function getPicSureData()
    {
        $data = [];

        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $termsRepo = $this->dm->getRepository(PolicyTerms::class);
        /** @var array $allTerms */
        $allTerms = $termsRepo->findAll();
        $data['picsureApprovedTotal'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_APPROVED,
            $allTerms
        );
        $data['picsureApprovedActive'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_APPROVED,
            $allTerms,
            true
        );
        $data['picsureRejectedTotal'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_REJECTED,
            $allTerms
        );
        $data['picsureRejectedActive'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_REJECTED,
            $allTerms,
            true
        );
        $data['picsureInvalidTotal'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_INVALID,
            $allTerms
        );
        $data['picsureInvalidActive'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_INVALID,
            $allTerms,
            true
        );
        $data['picsurePreApprovedTotal'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_PREAPPROVED,
            $allTerms
        );
        $data['picsurePreApprovedActive'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_PREAPPROVED,
            $allTerms,
            true
        );
        $data['picsureClaimsApprovedTotal'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED,
            $allTerms
        );
        $data['picsureClaimsApprovedActive'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED,
            $allTerms,
            true
        );
        $data['picsureUnstartedTotal'] = $policyRepo->countPicSurePolicies(null, $allTerms);
        $data['picsureUnstartedActive'] = $policyRepo->countPicSurePolicies(null, $allTerms, true);

        return $data;
    }

    private function getTotalRunRateByDate($date)
    {
        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $newToDateDirectPolicies = $policyRepo->findAllNewPolicies(null, null, $date);
        $newToDateInvitationPolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_INVITATION, null, $date);
        $newToDateSCodePolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_SCODE, null, $date);

        return $this->getTotalRunRate(
            $newToDateDirectPolicies,
            $newToDateInvitationPolicies,
            $newToDateSCodePolicies
        );
    }

    private function getTotalRunRate($newToDateDirectPolicies, $newToDateInvitationPolicies, $newToDateSCodePolicies)
    {
        return Policy::sumYearlyPremiumPrice($newToDateDirectPolicies, null, true) +
            Policy::sumYearlyPremiumPrice($newToDateInvitationPolicies, null, true) +
            Policy::sumYearlyPremiumPrice($newToDateSCodePolicies, null, true);
    }

    private function getExcludedPolicies($isKpi)
    {
        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $excludedPolicies = [];
        if (!$isKpi) {
            foreach ($this->getExcludedPolicyIds($isKpi) as $excludedPolicyId) {
                if ($policy = $policyRepo->find($excludedPolicyId->__toString())) {
                    $excludedPolicies[] = $policy;
                }
            }
        }

        return $excludedPolicies;
    }

    private function getExcludedPolicyIds($isKpi)
    {
        $excludedPolicyIds = [];
        if (!$isKpi) {
            foreach ($this->excludedPolicyIds as $excludedPolicyId) {
                $excludedPolicyIds[] = new \MongoId($excludedPolicyId);
            }
        }

        return $excludedPolicyIds;
    }

    public function connectionReport()
    {
        $today = new DateTime();
        $redisKey = sprintf(
            self::REPORT_KEY_FORMAT,
            'ConnectionReport',
            'Cached',
            $today->format('Y-m-d')
        );
        if ($this->redis->exists($redisKey)) {
            return unserialize($this->redis->get($redisKey));
        }

        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        /** @var ConnectionRepository $connectionRepo */
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        $totalEnd = null;

        $data = [];
        $data['dataFetchedAt'] = new DateTime();

        $data['totalTotalConnections'] = $connectionRepo->count(null, $totalEnd, null) / 2;

        $policies = $policyRepo->findAllPolicies($this->environment);
        for ($i = 0; $i <= 10; $i++) {
            $data['policyConnections'][$i]['total'] = 0;
            $data['policyConnections'][$i]['1claim'] = 0;
            $data['policyConnections'][$i]['2+claims'] = 0;
        }
        $data['policyConnections']['total']['total'] = 0;
        $data['policyConnections']['total']['1claim'] = 0;
        $data['policyConnections']['total']['2+claims'] = 0;
        /** @var Policy $policy */
        foreach ($policies as $policy) {
            $connections = count($policy->getConnections());
            if ($connections > 10) {
                $connections = 10;
            }

            $data['policyConnections'][$connections]['total']++;
            $data['policyConnections']['total']['total']++;

            $claims = count($policy->getMonetaryClaimed(false));
            if ($claims == 1) {
                $data['policyConnections'][$connections]['1claim']++;
                $data['policyConnections']['total']['1claim']++;
            } elseif ($claims > 1) {
                $data['policyConnections'][$connections]['2+claims']++;
                $data['policyConnections']['total']['2+claims']++;
            }
        }

        /*
        $data['policyConnections']['total'] = $data['totalPolicies'] + count($this->getExcludedPolicyIds(false));
        $data['policyConnections'][0] = $data['policyConnections']['total'];
        $data['policyConnections']['10+'] = 0;
        for ($i = 1; $i <= 30; $i++) {
            $data['policyConnections'][$i] = $connectionRepo->countByConnection($i, $start, $end);
            $data['policyConnections'][0] -= $data['policyConnections'][$i];
            if ($i >= 10) {
                $data['policyConnections']['10+'] += $data['policyConnections'][$i];
            }
        }
        */
        if ($data['policyConnections']['total']['total'] != 0) {
            $data['totalAvgConnections'] = $data['totalTotalConnections'] /
                $data['policyConnections']['total']['total'];
        } else {
            $data['totalAvgConnections'] = null;
        }

        $weighted = 0;
        for ($i = 0; $i < 10; $i++) {
            $weighted += $i * $data['policyConnections'][$i]['total'];
        }
        if ($data['policyConnections']['total'] != 0) {
            $data['totalWeightedAvgConnections'] = $weighted / $data['policyConnections']['total']['total'];
        } else {
            $data['totalWeightedAvgConnections'] = null;
        }

        $this->redis->setex($redisKey, self::REPORT_CACHE_TIME, serialize($data));

        return $data;
    }

    public function sumTotalPoliciesPerWeek(\DateTime $end = null)
    {
        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);

        $start = new \DateTime('2016-09-12');
        $total = 0;
        if (!$end) {
            $end = \DateTime::createFromFormat('U', time());
        }
        $weeks = floor($end->diff($start)->days / 7);
        for ($i = 1; $i <= $weeks; $i++) {
            $start = $start->add(new \DateInterval('P7D'));
            $total += $policyRepo->countAllNewPolicies($start);
        }

        return $total;
    }

    public function payments(\DateTime $date, $judoOnly = false, $checkoutOnly = false)
    {
        /** @var PaymentRepository $repo */
        $repo = $this->dm->getRepository(Payment::class);
        $payments = $repo->getAllPaymentsForReport($date, $judoOnly, $checkoutOnly);
        $sources = [
            Payment::SOURCE_TOKEN,
            Payment::SOURCE_WEB,
            Payment::SOURCE_WEB_API,
            Payment::SOURCE_MOBILE,
            Payment::SOURCE_APPLE_PAY,
            Payment::SOURCE_ANDROID_PAY,
            Payment::SOURCE_SOSURE,
            Payment::SOURCE_BACS,
            Payment::SOURCE_SYSTEM,
            Payment::SOURCE_ADMIN,
            sprintf('web-%s', JudopayService::WEB_TYPE_STANDARD),
            sprintf('web-%s', JudopayService::WEB_TYPE_UNPAID),
            sprintf('web-%s', JudopayService::WEB_TYPE_REMAINDER),
        ];
        $data = [];
        for ($i = 1; $i <= 31; $i++) {
            $data[$i] = [];
            foreach ($sources as $source) {
                $data[$i][$source] = [];
                $data[$i][$source]['success'] = 0;
                $data[$i][$source]['failure'] = 0;
                $data[$i][$source]['total'] = 0;
                $data[$i][$source]['success_percent'] = null;
                $data[$i][$source]['failure_percent'] = null;
            }
        }
        foreach ($payments as $payment) {
            /** @var Payment $payment */
            if (!$payment->getSource() || !$payment->getAmount() || $payment->getAmount() <= 0) {
                continue;
            }

            $day = $payment->getDate()->format('j');
            if ($payment->isSuccess()) {
                $data[$day][$payment->getSource()]['success']++;

                if (($payment instanceof JudoPayment || $payment instanceof CheckoutPayment) &&
                    $payment->getSource() == Payment::SOURCE_WEB) {
                    if ($payment->getWebType()) {
                        $data[$day][sprintf('web-%s', $payment->getWebType())]['success']++;
                    }

                    $data[$day]['policy-success'][$payment->getId()] = true;
                    unset($data[$day]['policy-failure'][$payment->getId()]);
                }
            } else {
                $data[$day][$payment->getSource()]['failure']++;

                if (($payment instanceof JudoPayment || $payment instanceof CheckoutPayment) &&
                    $payment->getSource() == Payment::SOURCE_WEB) {
                    if ($payment->getWebType()) {
                        $data[$day][sprintf('web-%s', $payment->getWebType())]['failure']++;
                    }

                    if (!isset($data[$day]['policy-success'][$payment->getId()])) {
                        $data[$day]['policy-failure'][$payment->getId()] = true;
                    }
                }
            }
            $data[$day][$payment->getSource()]['total']++;
            $data[$day][$payment->getSource()]['success_percent'] = $data[$day][$payment->getSource()]['success'] /
                $data[$day][$payment->getSource()]['total'];
            $data[$day][$payment->getSource()]['failure_percent'] = $data[$day][$payment->getSource()]['failure'] /
                $data[$day][$payment->getSource()]['total'];
            if (($payment instanceof JudoPayment || $payment instanceof CheckoutPayment) &&
                $payment->getSource() == Payment::SOURCE_WEB &&
                $payment->getWebType()) {
                $webSource = sprintf('web-%s', $payment->getWebType());
                $data[$day][$webSource]['total']++;
                $data[$day][$webSource]['success_percent'] = $data[$day][$webSource]['success'] /
                    $data[$day][$webSource]['total'];
                $data[$day][$webSource]['failure_percent'] = $data[$day][$webSource]['failure'] /
                    $data[$day][$webSource]['total'];
            }
        }
        for ($day = 1; $day <= 31; $day++) {
            $data[$day]['policy']['success'] = isset($data[$day]['policy-success']) ?
                count($data[$day]['policy-success']) : 0;
            $data[$day]['policy']['failure'] = isset($data[$day]['policy-failure']) ?
                count($data[$day]['policy-failure']) : 0;
            $data[$day]['policy']['total'] = $data[$day]['policy']['success'] + $data[$day]['policy']['failure'] ;
            $data[$day]['policy']['success_percent'] = $data[$day]['policy']['total'] > 0 ?
                $data[$day]['policy']['success'] / $data[$day]['policy']['total'] : 0;
            $data[$day]['policy']['failure_percent'] =  $data[$day]['policy']['total'] > 0 ?
                $data[$day]['policy']['failure'] / $data[$day]['policy']['total'] : 0;
        }

        return $data;
    }

    public function getActivePoliciesCount($date)
    {
        /** @var PhonePolicyRepository $phonePolicyRepo */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);

        return $phonePolicyRepo->countAllActivePoliciesToEndOfMonth($date);
    }

    public function getActivePoliciesWithPolicyDiscountCount($date)
    {
        /** @var PhonePolicyRepository $phonePolicyRepo */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);

        return $phonePolicyRepo->countAllActivePoliciesWithPolicyDiscountToEndOfMonth($date);
    }

    public function getRewardPotLiability($date, $promoOnly = false)
    {
        /** @var PhonePolicyRepository $phonePolicyRepo */
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $phonePolicyRepo->findPoliciesForRewardPotLiability($this->endOfMonth($date));
        $rewardPotLiability = 0;
        foreach ($policies as $policy) {
            if ($promoOnly) {
                $rewardPotLiability += $policy->getPromoPotValue();
            } else {
                $rewardPotLiability += $policy->getPotValue();
            }
        }

        return $rewardPotLiability;
    }

    public function getAllPaymentTotals($isProd, \DateTime $date, $useCache = true)
    {
        $redisKey = sprintf(
            self::REPORT_KEY_FORMAT,
            'allPaymentsTotal',
            $isProd ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );
        if ($useCache === true && $this->redis->exists($redisKey)) {
            return unserialize($this->redis->get($redisKey));
        }

        $payments = $this->getPayments($date);
        $potRewardPayments = $this->getPayments($date, 'potReward');
        $potRewardPaymentsCashback = $this->getPayments($date, 'potReward', true);
        $potRewardPaymentsDiscount = $this->getPayments($date, 'potReward', false);
        $soSurePotRewardPayments = $this->getPayments($date, 'sosurePotReward');
        $soSurePotRewardPaymentsCashback = $this->getPayments($date, 'sosurePotReward', true);
        $soSurePotRewardPaymentsDiscount = $this->getPayments($date, 'sosurePotReward', false);
        $policyDiscountPayments = $this->getPayments($date, 'policyDiscount');
        $policyDiscountRefundPayments = $this->getPayments($date, 'policyDiscountRefund');
        $totalRunRate = $this->getTotalRunRateByDate($this->endOfMonth($date));

        // @codingStandardsIgnoreStart
        $data = [
            'all' => Payment::sumPayments($payments, $isProd),
            'judo' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'checkout' => Payment::sumPayments($payments, $isProd, CheckoutPayment::class),
            'sosure' => Payment::sumPayments($payments, $isProd, SoSurePayment::class),
            'chargebacks' => Payment::sumPayments($payments, $isProd, ChargebackPayment::class),
            'bacs' => Payment::sumPayments($payments, $isProd, BacsPayment::class),
            'bacsIndemnity' => Payment::sumPayments($payments, $isProd, BacsIndemnityPayment::class),
            'potReward' => Payment::sumPayments($potRewardPayments, $isProd, PotRewardPayment::class),
            'potRewardCashback' => Payment::sumPayments($potRewardPaymentsCashback, $isProd, PotRewardPayment::class),
            'potRewardDiscount' => Payment::sumPayments($potRewardPaymentsDiscount, $isProd, PotRewardPayment::class),
            'sosurePotReward' => Payment::sumPayments($soSurePotRewardPayments, $isProd, SoSurePotRewardPayment::class),
            'sosurePotRewardCashback' => Payment::sumPayments($soSurePotRewardPaymentsCashback, $isProd, SoSurePotRewardPayment::class),
            'sosurePotRewardDiscount' => Payment::sumPayments($soSurePotRewardPaymentsDiscount, $isProd, SoSurePotRewardPayment::class),
            'policyDiscounts' => Payment::sumPayments($policyDiscountPayments, $isProd, PolicyDiscountPayment::class),
            'policyDiscountRefunds' => Payment::sumPayments($policyDiscountRefundPayments, $isProd, PolicyDiscountRefundPayment::class),
            'totalRunRate' => $totalRunRate,
            'totalCashback' => Cashback::sumCashback($this->getCashback($date)),
            'salvaPaymentFile' => $this->getSalvaPaymentFile($date),
        ];
        // @codingStandardsIgnoreEnd
        $this->redis->setex($redisKey, self::REPORT_CACHE_TIME, serialize($data));

        return $data;
    }

    public function getStats(\DateTime $date)
    {
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);
        /** @var StatsRepository $repo */
        $repo = $this->dm->getRepository(Stats::class);
        /** @var Stats[] $stats */
        $stats = $repo->getStatsByRange($start, $end);

        return Stats::sum($stats);
    }

    /**
     * @param \DateTime      $date Current date - will run report for previous year quarter
     * @param \DateTime|null $now  Optional - when is now
     */
    public function getUnderWritingReporting(\DateTime $date, \DateTime $now = null)
    {
        if (!$now) {
            $now = \DateTime::createFromFormat('U', time());
        }
        $start = $this->startOfMonth($date);
        $end = $this->endOfMonth($date);
        $policies = $this->getAllStartedPolicies($start, $end);
        $data = [
            'start' => $start,
            'end' => $end,
            'month' => $date->format('n'),
            'year' => $date->format('Y'),
            'allowed' => $now->diff($end)->y > 0,
            'premiumReceived' => 0,
            'premiumOutstanding' => 0,
            'premiumTotal' => 0,
            'claimsCost' => 0,
            'claimsReserves' => 0,
            'claimsTotal' => 0,
            'lossRatioOverall' => 0,
            'lossRatioEarned' => 0,
            'policies' => 0,
        ];
        foreach ($policies as $policy) {
            /** @var Policy $policy */
            $data['policies']++;
            $data['premiumReceived'] += $policy->getPremiumPaid();
            $data['premiumOutstanding'] += $policy->getUnderwritingOutstandingPremium();
            $data['premiumTotal'] += $this->toTwoDp($policy->getPremiumPaid() +
                $policy->getUnderwritingOutstandingPremium());
            $claimsCost = 0;
            $claimsReserves = 0;
            foreach ($policy->getClaims() as $claim) {
                /** @var Claim $claim */
                $claimsCost += $claim->getTotalIncurred();
                $claimsReserves += $claim->getReservedValue();
            }
            $data['claimsCost'] += $claimsCost;
            $data['claimsReserves'] += $claimsReserves;
            $data['claimsTotal'] += $claimsCost + $claimsReserves;
        }

        if ($data['premiumTotal'] != 0) {
            $data['lossRatioOverall'] = $this->toTwoDp($data['claimsCost'] / $data['premiumTotal']);
        }
        if ($data['premiumReceived'] != 0) {
            $data['lossRatioEarned'] = $this->toTwoDp($data['claimsCost'] / $data['premiumReceived']);
        }

        return $data;
    }

    /**
     * @param \DateTime      $date Current date - will run report for previous year quarter
     * @param \DateTime|null $now  Optional - when is now
     */
    public function getQuarterlyPL(\DateTime $date, \DateTime $now = null)
    {
        $redisKey = sprintf(
            self::REPORT_KEY_FORMAT,
            'QuarterlyPLReport',
            'Cached',
            $date->format('Y-m')
        );
        if ($this->redis->exists($redisKey)) {
            return unserialize($this->redis->get($redisKey));
        }

        if (!$now) {
            $now = \DateTime::createFromFormat('U', time());
        }
        list($start, $end) = $this->getQuarterlyPLDates($date);
        $policies = $this->getAllStartedPolicies($start, $end);
        $data = [
            'start' => $start,
            'end' => $end,
            'month' => $date->format('n'),
            'year' => $date->format('Y'),
            'allowed' => $now->diff($end)->y > 0,
            'gwp' => 0,
            'coverholderCommission' => 0,
            'brokerCommission' => 0,
            'net' => 0,
            'claimsCost' => 0,
            'claimsReserves' => 0,
            'rewardPot' => 0,
            'rewardPotInclIptRebate' => 0,
            'netWrittenPremium' => 0,
            'underwriterPreferredReturn' => 0,
            'underwriterReturn' => 0,
            'profit' => 0,
            'profitSalva' => 0,
            'profitSoSure' => 0,
            'rewardPotExcludingIptRebate' => 0,
            'policies' => 0,
        ];
        foreach ($policies as $policy) {
            $data['policies']++;
            $data['gwp'] += $policy->getGwpPaid();
            $data['coverholderCommission'] += $policy->getCoverholderCommissionPaid();
            $data['brokerCommission'] += $policy->getBrokerCommissionPaid();
            $net = $policy->getGwpPaid() - $policy->getCoverholderCommissionPaid() -
                $policy->getBrokerCommissionPaid();
            $data['net'] += $net;
            $claimsCost = 0;
            $claimsReserves = 0;
            foreach ($policy->getClaims() as $claim) {
                $claimsCost += $claim->getTotalIncurred() + $claim->getClaimHandlingFees();
                $claimsReserves += $claim->getReservedValue();
            }
            $data['claimsCost'] += $claimsCost;
            $data['claimsReserves'] += $claimsReserves;
            $rewardPot = (0 - $policy->getAdjustedRewardPotPaymentAmount());
            $rewardPotIptRebate = $this->toTwoDp(
                $rewardPot * $policy->getPremium()->getIptRate() / (1 + $policy->getPremium()->getIptRate())
            );
            $rewardPotExcludingIptRebate = $this->toTwoDp($rewardPot - $rewardPotIptRebate);
            $data['rewardPot'] += $rewardPot;
            $data['rewardPotExcludingIptRebate'] += $rewardPotExcludingIptRebate;
            $newWrittenPremium = $this->toTwoDp(
                $policy->getGwpPaid() - $policy->getCoverholderCommissionPaid() - $rewardPot
            );
            $data['netWrittenPremium'] += $newWrittenPremium;
            $underwritersPreferredReturn = $this->toTwoDp(
                $newWrittenPremium * 0.08
            );
            $data['underwriterPreferredReturn'] += $underwritersPreferredReturn;
            $underwriterReturn = $this->toTwoDp(
                $underwritersPreferredReturn - $policy->getBrokerCommissionPaid()
            );
            $data['underwriterReturn'] += $underwriterReturn;
            $data['profit'] += $this->toTwoDp(
                $net - $rewardPot - $claimsCost - $claimsReserves - $underwriterReturn
            );
        }

        if ($data['profit'] > 0) {
            $data['profitSalva'] += $this->toTwoDp($data['profit'] * 0.4);
            $data['profitSoSure'] += $this->toTwoDp($data['profit'] * 0.6);
        }

        $this->redis->setex($redisKey, self::REPORT_CACHE_TIME, serialize($data));

        return $data;
    }

    private function getQuarterlyPLDates(\DateTime $date)
    {
        $start = null;
        $end = null;
        // Quarters are defined Sept-Nov, Dec-Feb, Mar-May, Jun-Aug
        $date = clone $date;
        $month = $date->format('n');
        if (in_array($month, [9, 10, 11])) {
            $start = new \DateTime(
                sprintf('%d-09-01 00:00:00', $date->format('Y')),
                SoSure::getSoSureTimezone()
            );
            $end = new \DateTime(
                sprintf('%d-12-01 00:00:00', $date->format('Y')),
                SoSure::getSoSureTimezone()
            );
        } elseif (in_array($month, [12, 1, 2])) {
            $year = $date->format('Y');
            $month = $date->format('M');
            if ($month != 12) {
                $year = $year - 1;
            }
            $start = new \DateTime(
                sprintf('%d-12-01 00:00:00', $year),
                SoSure::getSoSureTimezone()
            );
            $end = new \DateTime(
                sprintf('%d-03-01 00:00:00', $date->format('Y')),
                SoSure::getSoSureTimezone()
            );
        } elseif (in_array($month, [3, 4, 5])) {
            $start = new \DateTime(
                sprintf('%d-03-01 00:00:00', $date->format('Y')),
                SoSure::getSoSureTimezone()
            );
            $end = new \DateTime(
                sprintf('%d-06-01 00:00:00', $date->format('Y')),
                SoSure::getSoSureTimezone()
            );
        } elseif (in_array($month, [6, 7, 8])) {
            $start = new \DateTime(
                sprintf('%d-06-01 00:00:00', $date->format('Y')),
                SoSure::getSoSureTimezone()
            );
            $end = new \DateTime(
                sprintf('%d-09-01 00:00:00', $date->format('Y')),
                SoSure::getSoSureTimezone()
            );
        }
        if ($end) {
            $end = $end->sub(new \DateInterval('PT1S'));
        }

        return [$start, $end];
    }

    private function getAllStartedPolicies(\DateTime $startDate, \DateTime $endDate)
    {
        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $policyRepo->findAllStartedPolicies(null, $startDate, $endDate);

        return $policies;
    }

    private function getCashback(\DateTime $date)
    {
        /** @var CashbackRepository $cashbackRepo */
        $cashbackRepo = $this->dm->getRepository(Cashback::class);
        $cashback = $cashbackRepo->getPaidCashback($date);

        return $cashback;
    }

    private function getPayments(\DateTime $date, $type = null, $cashback = null)
    {
        /** @var PaymentRepository $paymentRepo */
        $paymentRepo = $this->dm->getRepository(Payment::class);
        $payments = $paymentRepo->getAllPayments($date, $type);
        if ($cashback !== null) {
            $allPayments = [];
            foreach ($payments as $payment) {
                if ($payment->getPolicy()->hasCashback() === $cashback) {
                    $allPayments[] = $payment;
                }
            }

            return $allPayments;
        }

        return $payments;
    }

    private function getSalvaPaymentFile(\DateTime $date)
    {
        $files = $this->getSalvaPaymentFiles($date);
        if ($files && count($files) > 0) {
            foreach ($files as $file) {
                return $file;
            }
        }

        return null;
    }

    private function getSalvaPaymentFiles(\DateTime $date)
    {
        /** @var S3FileRepository $repo */
        $repo = $this->dm->getRepository(SalvaPaymentFile::class);

        return $repo->getAllFiles($date, 'salvaPayment');
    }

    private function arrayToString($array, $keyValueSeperator = '=', $lineSeperator = '; ')
    {
        $data = [];
        foreach ($array as $key => $value) {
            $data[] = sprintf("%s%s%s", $key, $keyValueSeperator, $value);
        }

        return implode($lineSeperator, $data);
    }

    public function getScheduledPayments() // @todo : iterable
    {
        $redisKey = sprintf('ScheduledPaymentsReport');
        if ($this->redis->exists($redisKey)) {
            return unserialize($this->redis->get($redisKey));
        }

        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $invalidPolicies = $policyRepo->getActiveInvalidPolicies();

        $invalidPoliciesIds = [];
        foreach ($invalidPolicies as $invalidPolicy) {
            $invalidPoliciesIds[] = new \MongoId($invalidPolicy->getId());
        }

        /** @var ScheduledPaymentRepository $scheduledPaymentRepo */
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        $scheduledPaymentRepo->setExcludedPolicyIds($invalidPoliciesIds);

        $results = $scheduledPaymentRepo->getMonthlyValues();
        #$results['dataFetchedAt'] = new DateTime();

        $this->redis->setex($redisKey, self::REPORT_CACHE_TIME, serialize($results));

        return $results;
    }

    /**
     * Creates a report in the cumulative style used by dylan, and monthly values calculated in the way that we use
     * side by side over a series of months.
     * @param \DateTime $start    is the starting month.
     * @param \DateTime $end      is the ending month.
     * @param boolean   $useCache says whether we should try to load from the cache or just skip that.
     * @return array containing the full report.
     */
    public function getCumulativePolicies($start, $end, $useCache = true)
    {
        $start = $this->startOfMonth($start);
        $end = $this->startOfDay($end);
        $key = sprintf(self::REPORT_KEY_FORMAT, $start->format('Y-m-d.hi'), $end->format('Y-m-d.hi'), "cumulative");
        if ($useCache === true && $this->redis->exists($key)) {
            return unserialize($this->redis->get($key));
        }
        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $report = [];
        $runningTotal = $this->totalAtPoint($start);
        while ($start < $end) {
            $endOfMonth = $this->endOfMonth($start);
            if ($endOfMonth > $end) {
                $endOfMonth = $end;
            }
            $month = [];
            $month["open"] = $runningTotal;
            $month["new"] = $policyRepo->countAllStartedPolicies(null, $start, $endOfMonth);
            $month["expired"] = $policyRepo->countEndingByStatus(Policy::$expirationStatuses, $start, $endOfMonth);
            $month["cancelled"] = $policyRepo->countEndingByStatus(Policy::STATUS_CANCELLED, $start, $endOfMonth);
            $runningTotal += $month["new"];
            $runningTotal -= $month["expired"];
            $runningTotal -= $month["cancelled"];
            $month["close"] = $runningTotal;
            $month["upgrade"] = $policyRepo->countAllEndingPolicies(
                Policy::CANCELLED_UPGRADE,
                $start,
                $endOfMonth,
                false
            );
            $month["newAdjusted"] = $month["new"] - $month["upgrade"];
            $month["cancelledAdjusted"] = $month["cancelled"] - $month["upgrade"];
            $month["queryOpen"] = $this->totalAtPoint($start);
            $month["queryClose"] = $this->totalAtPoint($endOfMonth);
            $report[$start->format("F Y")] = $month;
            $start = $endOfMonth;
        }
        $this->redis->setex($key, self::REPORT_CACHE_TIME, serialize($report));
        return $report;
    }

    /**
     * Gives a list of time periods to report on.
     * @return array which associates a name for each period to another array containing string representations of the
     *               start and end of that period.
     */
    public static function getPeriodList()
    {
        $periods = [];
        foreach (self::REPORT_PERIODS as $key => $periodChoice) {
            if (array_key_exists("month", $periodChoice)) {
                $start = (new \DateTime($periodChoice["start"]))->format("F Y");
                $periods[$start] = $key;
            } else {
                $periods[$key] = $key;
            }
        }
        return $periods;
    }

    /**
     * gives you a period of time with an optional starting date and an optional
     * ending date, start date is rounded to the beginning of the given day, and
      * end date is rounded to the end of the preceding day.
     * @param string $period is the string name of a period as defined in
     *                       REPORT_PERIODS constant
     * @return array containing the new start and end dates and a boolean telling you if this period is a whole month.
     */
    public static function getLastPeriod($period): array
    {
        if (!array_key_exists($period, static::REPORT_PERIODS)) {
            throw new \InvalidArgumentException(
                "{$period} is not a valid period as defined in ReportingService::REPORT_PERIODS"
            );
        }
        $month = false;
        if (array_key_exists('month', static::REPORT_PERIODS[$period])) {
            $month = static::REPORT_PERIODS[$period]["month"];
        }
        $start = new DateTime(static::REPORT_PERIODS[$period]['start'], new DateTimeZone(SoSure::TIMEZONE));
        $end = new DateTime(static::REPORT_PERIODS[$period]['end'], new DateTimeZone(SoSure::TIMEZONE));
        $start->setTime(0, 0, 0);
        $end->setTime(0, 0, 0);
        return [$start, $end, $month];
    }

    /**
     * Gives you the total number of policies that are going.
     * @param \DateTime $date is the date to look at.
     * @return int the number of policies.
     */
    private function totalAtPoint(\DateTime $date)
    {
        /** @var PhonePolicyRepository $policyRepo */
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        return $policyRepo->countAllNewPolicies($date) - (
            $policyRepo->countEndingByStatus(Policy::$expirationStatuses, null, $date) +
            $policyRepo->countEndingByStatus(Policy::STATUS_CANCELLED, null, $date)
        );
    }
}
