<?php
namespace AppBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use AppBundle\Document\Connection;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Claim;
use AppBundle\Document\Lead;
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

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $excludedPolicyIds
     */
    public function __construct(DocumentManager $dm, LoggerInterface $logger, $excludedPolicyIds)
    {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->excludedPolicyIds = $excludedPolicyIds;
    }

    public function report($start, $end, $excludePolicies = true)
    {
        $policyRepo = $this->dm->getRepository(PhonePolicy::class);
        $connectionRepo = $this->dm->getRepository(Connection::class);
        $invitationRepo = $this->dm->getRepository(Invitation::class);
        $scheduledPaymentRepo = $this->dm->getRepository(ScheduledPayment::class);
        $claimsRepo = $this->dm->getRepository(Claim::class);
        $claims = $claimsRepo->findFNOLClaims($start, $end);
        $claimsTotals = Claim::sumClaims($claims);

        $invalidPolicies = $policyRepo->getActiveInvalidPolicies();
        $invalidPoliciesIds = [];
        foreach ($invalidPolicies as $invalidPolicy) {
            $invalidPoliciesIds[] = new \MongoId($invalidPolicy->getId());
        }
        $scheduledPaymentRepo->setExcludedPolicyIds($invalidPoliciesIds);
        $data['scheduledPayments'] = $scheduledPaymentRepo->getMonthlyValues();

        $excludedPolicyIds = [];
        $excludedPolicies = [];
        if ($excludePolicies) {
            foreach ($this->excludedPolicyIds as $excludedPolicyId) {
                $excludedPolicyIds[] = new \MongoId($excludedPolicyId);
                $policy = $policyRepo->find($excludedPolicyId);
                if ($policy) {
                    $excludedPolicies[] = $policy;
                }
            }
        }

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

        $totalSCodePolicies = $policyRepo->findAllNewPolicies(Lead::LEAD_SOURCE_SCODE);
        $data['totalSCodePolicies'] = $totalSCodePolicies->count();
        $data['totalSCodePoliciesPremium'] = Policy::sumYearlyPremiumPrice($totalInvitationPolicies);
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
            'Cooloff' => Policy::CANCELLED_COOLOFF,
            'BadRisk' => Policy::CANCELLED_BADRISK,
            'Dispossession' => Policy::CANCELLED_DISPOSSESSION,
            'Wreckage' => Policy::CANCELLED_WRECKAGE,
        ];
        foreach ($endingDataset as $key => $cancellationReason) {
            $data[sprintf('total%sPolicies', $key)] = $policyRepo->findAllEndingPolicies($cancellationReason);
            $data[sprintf('ending%sPolicies', $key)] = $policyRepo->findAllEndingPolicies(
                $cancellationReason,
                $start,
                $end
            );
        }

        $data['newPolicies'] = $policyRepo->countAllNewPolicies($end, $start);
        $data['newPoliciesPremium'] = $data['newDirectPoliciesPremium'] + $data['newInvitationPoliciesPremium'] +
            $data['newSCodePoliciesPremium'];
        if ($data['newPolicies'] != 0) {
            $data['newPoliciesAvgPremium'] = $this->toTwoDp($data['newPoliciesPremium'] / $data['newPolicies']);
        }

        $data['totalActivePolicies'] = $policyRepo->countAllActivePolicies();
        $data['totalPolicies'] = $policyRepo->countAllNewPolicies();
        $data['totalPoliciesPremium'] = $data['totalDirectPoliciesPremium'] + $data['totalInvitationPoliciesPremium'] +
            $data['totalSCodePoliciesPremium'];
        if ($data['totalPolicies'] != 0) {
            $data['totalPoliciesAvgPremium'] = $this->toTwoDp($data['totalPoliciesPremium'] / $data['totalPolicies']);
        }

        $data['totalActiveMonthlyPolicies'] = $policyRepo->countAllActivePoliciesByInstallments(12);
        $data['totalActiveYearlyPolicies'] = $policyRepo->countAllActivePoliciesByInstallments(1);

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
        $data['totalAvgHoursToConnect'] = $connectionRepo->avgHoursToConnect();

        return [
            'start' => $start,
            'end' => $end,
            'data' => $data,
            'total_connections' => $totalConnections,
            'new_connections' => $newConnections,
            'excluded_policies' => $excludedPolicies,
            'claims' => $claimsTotals,
        ];
    }
}
