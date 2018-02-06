<?php
namespace AppBundle\Service;

use AppBundle\Document\Cashback;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\MonitorException;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\MultiPay;
use AppBundle\Document\Claim;
use AppBundle\Document\File\DaviesFile;
use AppBundle\Document\DateTrait;

class MonitorService
{
    use DateTrait;

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

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
     * @param                 $redis
     * @param IntercomService $intercom
     * @param MixpanelService $mixpanel
     * @param JudopayService  $judopay
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $redis,
        $intercom,
        $mixpanel,
        $judopay
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->intercom = $intercom;
        $this->mixpanel = $mixpanel;
        $this->judopay = $judopay;
    }

    public function run($name)
    {
        if (method_exists($this, $name)) {
            return call_user_func([$this, $name]);
        } else {
            throw new \Exception(sprintf('Unknown monitor %s', $name));
        }
    }

    public function multipay()
    {
        $repo = $this->dm->getRepository(Policy::class);
        $policies = $repo->findBy(['status' => Policy::STATUS_MULTIPAY_REQUESTED]);
        foreach ($policies as $policy) {
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
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findMissingReceivedDate();
        $now = new \DateTime();
        foreach ($claims as $claim) {
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
        $repo = $this->dm->getRepository(Claim::class);
        $claims = $repo->findSettledUnprocessed();
        foreach ($claims as $claim) {
            throw new MonitorException(sprintf(
                'Claim %s is settled, but has not been processed (e.g. pot updated)',
                $claim->getNumber()
            ));
        }
    }

    public function cashbackPastDue()
    {
        $repo = $this->dm->getRepository(Cashback::class);
        $cashbacks = $repo->getLateCashback();
        foreach ($cashbacks as $cashback) {
            throw new MonitorException(sprintf(
                'Cashback for policy id:%s (%s) is late (%s)',
                $cashback->getPolicy()->getId(),
                $cashback->getPolicy()->getSalvaPolicyNumber(),
                $cashback->getCreatedDate()->format('d-m-Y')
            ));
        }
    }

    public function daviesImport()
    {
        $fileRepo = $this->dm->getRepository(DaviesFile::class);
        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;
        if (!$successFile) {
            throw new MonitorException('Unable to find any successful imports');
        }

        $now = $this->startOfDay(new \DateTime());
        $diff = $now->diff($successFile->getCreated());
        if ($diff->days >= 1) {
            throw new MonitorException(sprintf(
                'Last successful import on %s',
                $successFile->getCreated()->format(\DateTime::ATOM)
            ));
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
        $repo = $this->dm->getRepository(PhonePolicy::class);
        $twoDays = new \DateTime();
        $twoDays = $twoDays->sub(new \DateInterval('P2D'));

        // delay 10 minutes to allow time to sync
        $tenMinutes = new \DateTime();
        $tenMinutes = $tenMinutes->sub(new \DateInterval('PT10M'));
        $updatedPolicies = $repo->findAllStatusUpdatedPolicies($twoDays, $tenMinutes);
        $errors = [];
        foreach ($updatedPolicies as $policy) {
            $intercomUser = $this->intercom->getIntercomUser($policy->getUser());
            if (is_object($intercomUser)) {
                // only active/unpaid policies and definitely not cancelled
                if ($policy->isActive(true) && $intercomUser->custom_attributes->Premium <= 0) {
                    $this->intercom->queue($policy->getUser());
                    $errors[] = sprintf(
                        'Intercom out of sync: %s has a 0 premium in intercom, yet has a policy. Requeued.',
                        $policy->getUser()->getEmail()
                    );
                } elseif (!$policy->isActive(true) && $intercomUser->custom_attributes->Premium > 0) {
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
        // acutal 50,000 for plan
        $maxUsers = 48000;
        $total = $this->mixpanel->getUserCount();
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
            $policy = $claim->getPolicy();
            // Only concerned about active (or unpaid) policies here
            if (!in_array($policy->getStatus(), [
                Policy::STATUS_ACTIVE,
                Policy::STATUS_UNPAID,
            ])) {
                continue;
            }

            // If a claim occurs and the policy is then updated to a new imei after the claim
            // our test will fail. For now, just exclude those policies from the test
            // TODO: Come up with a better solution
            if (in_array($policy->getId(), ['586e75c31d255d1fd6143cf5'])) {
                continue;
            }

            if ($lastestClaimForPolicy = $policy->getLatestClaim(true)) {
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
        $results = $this->judopay->getTransactions(20, false);
        if (isset($results['additional-payments']) && count($results['additional-payments']) > 0) {
            // @codingStandardsIgnoreStart
            throw new MonitorException(sprintf(
                'Judopay is recording more than 1 payment against a policy that indicates a scheduled payment issue. %s',
                json_encode($results['additional-payments'])
            ));
            // @codingStandardsIgnoreEnd
        } elseif (isset($results['missing']) && count($results['missing']) > 0) {
            // @codingStandardsIgnoreStart
            throw new MonitorException(sprintf(
                'Judopay is missing database payment records that indices a mobile payment was received, but not recorded. %s',
                json_encode($results['missing'])
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

    public function policyTerms()
    {
        $repo = $this->dm->getRepository(PolicyTerms::class);
        $terms = $repo->findAll();
        $termVersions = [];
        foreach ($terms as $term) {
            if (!in_array($term->getVersion(), PolicyTerms::$allVersions)) {
                throw new MonitorException(sprintf(
                    'Policy Terms %s is in db but not present in code',
                    $term->getVersion()
                ));
            }
            $termVersions[] = $term->getVersion();
        }
        foreach (PolicyTerms::$allVersions as $version) {
            if (!in_array($version, $termVersions)) {
                throw new MonitorException(sprintf(
                    'Policy Terms %s is in code but not present in db',
                    $version
                ));
            }
        }
    }
}
