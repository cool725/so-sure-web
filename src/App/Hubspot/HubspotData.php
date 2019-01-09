<?php
namespace App\Hubspot;

use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use CensusBundle\Service\SearchService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * Sets up data into the right form that it can be used by hubspot.
 */
class HubspotData
{
    /**@var SearchService */
    private $searchService;

    /**
     * builds the service so it can use the search service.
     * @param SearchService $searchService is the search service to be used.
     */
    public function __construct(SearchService $searchService)
    {
        $this->searchService = $searchService;
    }

    /**
     * builds data array of user as desired by hubspot.
     * @param User $user is the user that the data will be based on.
     * @return array of the data.
     */
    public function getHubspotUserArray(User $user)
    {
        $data = array_merge(
            $this->getHubspotUserDetails($user),
            $this->getHubspotAddressRelated($user),
            $this->getHubspotMisc($user),
            $this->getHubspotPolicies($user),
            $this->getLifecycleStage($user)
        );

        // TODO: add the custom fields

        return array_filter($data);
    }

    /**
     * fff
     */
    private function getHubspotUserDetails(User $user)
    {
        $data = [
            $this->hubSpotProperty("firstname", $user->getFirstName()),
            $this->hubSpotProperty("lastname", $user->getLastName()),
            $this->hubSpotProperty("email", $user->getEmailCanonical()),
            $this->hubSpotProperty("mobilephone", $user->getMobileNumber()),
            $this->hubSpotProperty("gender", $user->getGender()),
        ];
        if ($user->getBirthday()) {
            $data[] = $this->hubSpotProperty("date_of_birth", $user->getBirthday()->format("U") * 1000);
        }
        return $data;
    }

    /**
     * Data based on user's address.
     */
    private function getHubspotAddressRelated(User $user)
    {
        $data = [];
        if ($user->getBillingAddress()) {
            $data[] = $this->hubSpotProperty("billing_address", $user->getBillingAddress());
            if ($census = $this->searchService->findNearest($user->getBillingAddress()->getPostcode())) {
                $data[] = $this->hubSpotProperty("census_subgroup", $census->getSubGroup());
            }
            if ($income = $this->searchService->findIncome($user->getBillingAddress()->getPostcode())) {
                $data[] = $this->hubSpotProperty("total_weekly_income", $income->getTotal()->getIncome());
            }
        }
        return $data;
    }

    private function getHubspotPolicies(User $user)
    {
        return [$this->hubspotProperty("policyCount", null)];  // look at the 'Hubspot Deals api' to do anything with policies.

        /** @var Collection<Policy|PhonePolicy> $policies */
        $policies = $user->getAllPolicies() ?? []; // ->getDisplayablePoliciesSorted();
        $claims = $this->getClaims($policies);

        /** @var Policy[]|PhonePolicy[]|Collection $policies */
        $policies = $policies->toArray();
        // sort ascending. Oldest first
        usort($policies, function (PhonePolicy $policyA, PhonePolicy $policyB) {
            return $policyA->getCreated() <=> $policyB->getCreated();
        });

        // @todo - how to represent multiple policies?
        $policyNumber = 1;
        $hubspotPolicies = [];
        foreach ($policies as $policy) {
            $name = 'policyNumber'. $policyNumber;
            $hubspotPolicies[$name] = $this->hubspotPolicySummary($policy);
            $policyNumber ++;
        }

        // policy & claims
        $data = [
            'policyCount' => $this->hubSpotProperty('policy_count', count($policies)),
            'claimsCount' => $this->hubSpotProperty('claims_count', count($claims)),
            'policySummaries' => $this->hubSpotProperty('policy_summaries', $hubspotPolicies),
        ];

        return $data;
    }

    private function hubspotPolicySummary(PhonePolicy $policy)
    {
        $displayFormat = 'd M Y H:i';
        $start = $policy->getStart()->format($displayFormat);
        $end = $policy->getEnd()->format($displayFormat);

        return [
            'policy_number' => $policy->getPolicyNumber(),
            'policy_phone' => (string) $policy->getPhone(),
            'policy_status' => $policy->getStatus() .
                ($policy->getCancelledReason() ? '/ '.$policy->getCancelledReason() : ''),
            'policy_dates' => $start . ' - ' . $end
        ];
    }

    public function getLifecycleStage(User $user)
    {
        $userStage = Api::QUOTE;    // safe default - they don't have a policy yet

        do {
            if ($user->hasActivePolicy()) {
                $userStage = Api::PURCHASED;
                break;
            }
            if ($user->hasCancelledPolicy()) {
                $userStage = Api::CANCELLED;
                break;
            }
            // @todo many other conditions required here for the various combinations
            #if ($user->) {
            #    $userStage = Api::CANCELLED;
            #}
        } while (false);    // a block we can `break` out of. If it gets more complex, make it a function to return from
        return [$this->hubspotProperty("sosure_lifecycle_stage", $userStage)];
    }

    private function getClaims(Collection $policies = null): Collection
    {
        if (! $policies) {
            return new ArrayCollection();
        }

        $claims = new ArrayCollection();
        foreach ($policies as $policy) {
            /** @var Collection<Policy> $policy */
            foreach ($policy->getClaims() as $pol) {
                /** @var Policy $pol */
                $claims[] = $pol->getClaims();
            }
        }

        return $claims->filter(function (Claim $claim = null) {
            return $claim !== null;
        });
    }

    private function getHubspotMisc(User $user)
    {
        $hasFacebook = false;
        if ($user->getFacebookId()) {
            $hasFacebook = true;
        }
        $data = [
            $this->hubSpotProperty("attribution", $user->getAttribution() ?? ''),
            $this->hubSpotProperty("latestattribution", $user->getLatestAttribution() ?? ''),
            $this->hubSpotProperty("facebook", $hasFacebook ? "yes" : "no"),
        ];
        if ($hasFacebook) {
            $data['hs_facebookid'] = $this->hubspotProperty("hs_facebookid", $user->getFacebookId());
        }
        return $data;
    }

    private function hubSpotProperty(string $fieldName, $value): array
    {
        if ($value !== null) {
            return ['property' => $fieldName, 'value' => $value];
        }

        return [];
    }
}
