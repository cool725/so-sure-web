<?php
namespace AppBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Connection\StandardConnection;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Claim;
use AppBundle\Document\Lead;
use AppBundle\Document\Payment;
use AppBundle\Document\ScheduledPayment;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;

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
                $end
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
        }
        $data['totalEndingPoliciesAdjUpgrade'] = $data['totalEndingPolicies'] - $data['totalUpgradePolicies'];
        $data['endingEndingPoliciesAdjUpgrade'] = $data['endingEndingPolicies'] - $data['endingUpgradePolicies'];
        $data['totalEndingFNOLPoliciesAdjUpgrade'] = $data['totalEndingFNOLPolicies'] -
            $data['totalUpgradeFNOLPolicies'];
        $data['endingEndingFNOLPoliciesAdjUpgrade'] = $data['endingEndingFNOLPolicies'] -
            $data['endingUpgradeFNOLPolicies'];

        $data['newPolicies'] = $policyRepo->countAllNewPolicies($end, $start);
        $data['newPoliciesAdjUpgrade'] = $data['newPolicies'] - $data['endingUpgradePolicies'];
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

        $data['totalRunRate'] = Policy::sumYearlyPremiumPrice($newToDateDirectPolicies, null, true) +
            Policy::sumYearlyPremiumPrice($newToDateInvitationPolicies, null, true) +
            Policy::sumYearlyPremiumPrice($newToDateSCodePolicies, null, true);

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
            if (!$payment->getSource()) {
                continue;
            }
            $day = $payment->getDate()->format('j');
            if ($payment->isSuccess()) {
                $data[$day][$payment->getSource()]['success']++;

                $data[$day]['policy-success'][$payment->getId()] = true;
                unset($data[$day]['policy-failure'][$payment->getId()]);
            } else {
                $data[$day][$payment->getSource()]['failure']++;

                if (!isset($data[$day]['policy-success'][$payment->getId()])) {
                    $data[$day]['policy-failure'][$payment->getId()] = true;
                }
            }
            $data[$day][$payment->getSource()]['total']++;
            $data[$day][$payment->getSource()]['success_percent'] = $data[$day][$payment->getSource()]['success'] /
                $data[$day][$payment->getSource()]['total'];
            $data[$day][$payment->getSource()]['failure_percent'] = $data[$day][$payment->getSource()]['failure'] /
                $data[$day][$payment->getSource()]['total'];
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
}
