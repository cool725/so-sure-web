<?php
namespace AppBundle\DataObjects;

use AppBundle\Document\User;

/**
 * Summary of the Policy, suitable to display on a 3rd party site (to an authenticated user)
 */
class PolicySummary
{
    /**
     * @var User
     */
    private $user;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    /**
     * return the summary suitable to show an authenticated user, or service
     */
    public function get(): array
    {
        $userPolicy = $this->user->toApiArray(null, null, null, false);

        return [
            'first_name' => $userPolicy['first_name'],
            'last_name' => $userPolicy['last_name'],
            'postcode' => $userPolicy['addresses'][0]['postcode'] ?? '-',
            'mobile_number' => $userPolicy['mobile_number'],
            'policies' => $this->summaryAllPolicies($userPolicy['policies']),
            'received_invitations' => $this->summaryInvitations($userPolicy['received_invitations']),
            'mobile_number_verified' => $userPolicy['has_mobile_number_verified'] ?? false,
        ];
    }

    private function summaryInvitations(array $invitations): array
    {
        // @todo
        return $invitations;
    }

    private function summaryAllPolicies(array $allPolicies)
    {
        $allPolicies = array_map(
            function ($policy) {
                if (isset($policy['pot'])) {
                    $summary['pot'] = $this->summaryPot($policy['pot']);
                }
                if (isset($policy['sent_invitations'])) {
                    $summary['sent_invitations'] = $this->summarySentInvitations($policy['sent_invitations']);
                }
                if (isset($policy['phone_policy'])) {
                    $summary['phone_policy'] = $this->summaryPhonePolicy($policy['phone_policy']);
                }

                $summary['adjusted_monthly_premium'] = $policy['adjusted_monthly_premium'];
                $summary['adjusted_yearly_premium'] = $policy['adjusted_yearly_premium'];

                return $summary;
            },
            $allPolicies
        );

        return $allPolicies;
    }

    private function summaryPot(array $pot)
    {
        return [
            'connections' => $pot['connections'],
            'max_connections' => $pot['max_connections'],
            'value' => $pot['value'],
            'max_value' => $pot['max_value'],
            'status' => $pot['status'] ?? 'unknown',
            // more ...
        ];
    }

    private function summarySentInvitations(array $sent_invitations): array
    {
        return [
            'sent' => count($sent_invitations),
        ];
    }

    private function summaryPhonePolicy(array $phone_policy): array
    {
        #return $phone_policy;
        return [
            'name' => $phone_policy['name'],
            'picsure_status' => $phone_policy['picsure_status'],
            // more....
        ];
    }
}
