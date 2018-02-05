<?php
namespace AppBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
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

class ReportingService
{
    use DateTrait;
    use CurrencyTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    protected $excludedPolicyIds;

    protected $environment;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $excludedPolicyIds
     * @parma strign          $environment
     */
    public function __construct(DocumentManager $dm, LoggerInterface $logger, $excludedPolicyIds, $environment)
    {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->excludedPolicyIds = $excludedPolicyIds;
        $this->environment = $environment;
    }

    public function report($start, $end, $isKpi = false)
    {
        $totalEnd = null;
        if ($isKpi) {
            $totalEnd = $end;
        }

        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        $invitationRepo = $this->dm->getRepository(Invitation::class);
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
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
        foreach ($allClaims as $claim) {
            if ($claim->getRecordedDate() > $end) {
                continue;
            }

            $totalClaims++;
            if ($claim->isWithin30DaysOfPolicyInception()) {
                $fnol30Claims++;
            }
        }
        $data['fnol30Claims'] = 100 * $fnol30Claims / $totalClaims;

        $data['claimAttribution'] = [];
        foreach ($approvedClaims as $claim) {
            if ($attribution = $claim->getPolicy()->getUser()->getAttribution()) {
                if (isset($claimAttribution[$attribution->getCampaignSource()])) {
                    $claimAttribution[$attribution->getCampaignSource()]++;
                } else {
                    $claimAttribution[$attribution->getCampaignSource()] = 1;
                }
            }
        }
        $data['claimAttributionJson'] = json_encode($data['claimAttribution']);

        $invalidPolicies = $policyRepo->getActiveInvalidPolicies();
        $invalidPoliciesIds = [];
        foreach ($invalidPolicies as $invalidPolicy) {
            $invalidPoliciesIds[] = new \MongoId($invalidPolicy->getId());
        }
        $scheduledPaymentRepo->setExcludedPolicyIds($invalidPoliciesIds);
        $data['scheduledPayments'] = $scheduledPaymentRepo->getMonthlyValues();

        $excludedPolicyIds = $this->getExcludedPolicyIds($isKpi);
        $excludedPolicies = $this->getExcludedPolicies($isKpi);

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
        $data[sprintf('endingExpiredPolicies', $key)] = $policyRepo->countAllEndingPolicies(
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
        foreach ($renewalPolicies as $renewalPolicy) {
            if ($renewalPolicy->isRenewed()) {
                $data['endingPoliciesRenewed']++;
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

        $data['totalActivePolicies'] = $policyRepo->countAllActivePolicies();
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

        return [
            'start' => $start,
            'end' => $end,
            'data' => $data,
            'excluded_policies' => $excludedPolicies,
            'claims' => $claimsTotals,
            'approvedClaims' => $approvedClaimsTotals,
            'closedClaims' => $closedClaimsTotals,
        ];
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

        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $termsRepo = $this->dm->getRepository(PolicyTerms::class);
        $allTerms = $termsRepo->findAll();
        $data['picsureApproved'] = $policyRepo->countPicSurePolicies(PhonePolicy::PICSURE_STATUS_APPROVED, $allTerms);
        $data['picsureRejected'] = $policyRepo->countPicSurePolicies(PhonePolicy::PICSURE_STATUS_REJECTED, $allTerms);
        $data['picsureInvalid'] = $policyRepo->countPicSurePolicies(PhonePolicy::PICSURE_STATUS_INVALID, $allTerms);
        $data['picsurePreApproved'] = $policyRepo->countPicSurePolicies(
            PhonePolicy::PICSURE_STATUS_PREAPPROVED,
            $allTerms
        );
        $data['picsureUnstarted'] = $policyRepo->countPicSurePolicies(null, $allTerms);

        return $data;
    }

    private function getTotalRunRateByDate($date)
    {
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
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $connectionRepo = $this->dm->getRepository(StandardConnection::class);
        $totalEnd = null;

        $data = [];
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

        return $data;
    }

    public function sumTotalPoliciesPerWeek(\DateTime $end = null)
    {
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);

        $start = new \DateTime('2016-09-12');
        $total = 0;
        if (!$end) {
            $end = new \DateTime();
        }
        $weeks = floor($end->diff($start)->days / 7);
        for ($i = 1; $i <= $weeks; $i++) {
            $start = $start->add(new \DateInterval('P7D'));
            $total += $policyRepo->countAllNewPolicies($start);
        }

        return $total;
    }

    public function payments(\DateTime $date)
    {
        $repo = $this->dm->getRepository(Payment::class);
        $payments = $repo->getAllPaymentsForReport($date);
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
            if (!$payment->getSource() || !$payment->getAmount() || $payment->getAmount() <= 0) {
                continue;
            }
            $day = $payment->getDate()->format('j');
            if ($payment->isSuccess()) {
                $data[$day][$payment->getSource()]['success']++;

                if ($payment->getSource() == Payment::SOURCE_WEB) {
                    if ($payment->getWebType()) {
                        $data[$day][sprintf('web-%s', $payment->getWebType())]['success']++;
                    }

                    $data[$day]['policy-success'][$payment->getId()] = true;
                    unset($data[$day]['policy-failure'][$payment->getId()]);
                }
            } else {
                $data[$day][$payment->getSource()]['failure']++;

                if ($payment->getSource() == Payment::SOURCE_WEB) {
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
            if ($payment->getSource() == Payment::SOURCE_WEB && $payment->getWebType()) {
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
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);

        return $phonePolicyRepo->countAllActivePoliciesToEndOfMonth($date);
    }

    public function getActivePoliciesWithPolicyDiscountCount($date)
    {
        $phonePolicyRepo = $this->dm->getRepository(PhonePolicy::class);

        return $phonePolicyRepo->countAllActivePoliciesWithPolicyDiscountToEndOfMonth($date);
    }

    public function getRewardPotLiability($date, $promoOnly = false)
    {
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

    public function getAllPaymentTotals($isProd, \DateTime $date)
    {
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
        return [
            'all' => Payment::sumPayments($payments, $isProd),
            'judo' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'sosure' => Payment::sumPayments($payments, $isProd, SoSurePayment::class),
            'chargebacks' => Payment::sumPayments($payments, $isProd, ChargebackPayment::class),
            'bacs' => Payment::sumPayments($payments, $isProd, BacsPayment::class),
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
        ];
        // @codingStandardsIgnoreEnd
    }

    /**
     * @param $date Current date - will run report for previous year quarter
     */
    public function getQuarterlyPL(\DateTime $date, \DateTime $now = null)
    {
        if (!$now) {
            $now = new \DateTime();
        }
        list($start, $end) = $this->getQuarterlyPLDates($date);
        $policies = $this->getQuarterlyPolicies($start, $end);
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
        ];
        foreach ($policies as $policy) {
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
            $data['rewardPot'] += $rewardPot;
            $rewardPotInclIptRebate = $this->toTwoDp(
                $rewardPot * (1 + $policy->getPremium()->getIptRate())
            );
            $data['rewardPotInclIptRebate'] += $rewardPotInclIptRebate;
            $newWrittenPremium = $this->toTwoDp(
                $policy->getGwpPaid() - $policy->getCoverholderCommissionPaid() - $rewardPotInclIptRebate
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

        return $data;
    }
    
    private function getQuarterlyPLDates(\DateTime $date)
    {
        // Quarters are defined Sept-Nov, Dec-Feb, Mar-May, Jun-Aug
        $date = clone $date;
        $month = $date->format('n');
        if (in_array($month, [9, 10, 11])) {
            $start = new \DateTime(
                sprintf('%d-09-01 00:00:00', $date->format('Y')),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
            $end = new \DateTime(
                sprintf('%d-12-01 00:00:00', $date->format('Y')),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
        } elseif (in_array($month, [12, 1, 2])) {
            $year = $date->format('Y');
            $month = $date->format('M');
            if ($month != 12) {
                $year = $year - 1;
            }
            $start = new \DateTime(
                sprintf('%d-12-01 00:00:00', $year),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
            $end = new \DateTime(
                sprintf('%d-03-01 00:00:00', $date->format('Y')),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
        } elseif (in_array($month, [3, 4, 5])) {
            $start = new \DateTime(
                sprintf('%d-03-01 00:00:00', $date->format('Y')),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
            $end = new \DateTime(
                sprintf('%d-06-01 00:00:00', $date->format('Y')),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
        } elseif (in_array($month, [6, 7, 8])) {
            $start = new \DateTime(
                sprintf('%d-06-01 00:00:00', $date->format('Y')),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
            $end = new \DateTime(
                sprintf('%d-09-01 00:00:00', $date->format('Y')),
                new \DateTimeZone(SoSure::TIMEZONE)
            );
        }
        $end = $end->sub(new \DateInterval('PT1S'));

        return [$start, $end];
    }

    private function getQuarterlyPolicies(\DateTime $startDate, \DateTime $endDate)
    {
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $policies = $policyRepo->findAllStartedPolicies(null, $startDate, $endDate);

        return $policies;
    }

    private function getCashback(\DateTime $date)
    {
        $cashbackRepo = $this->dm->getRepository(Cashback::class);
        $cashback = $cashbackRepo->getPaidCashback($date);

        return $cashback;
    }

    private function getPayments(\DateTime $date, $type = null, $cashback = null)
    {
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
}
