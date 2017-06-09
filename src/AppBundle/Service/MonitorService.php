<?php
namespace AppBundle\Service;

use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use AppBundle\Exception\MonitorException;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
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

    protected $intercom;

    protected $mixpanel;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param                 $redis
     * @param                 $intercom
     * @param                 $mixpanel
     */
    public function __construct(
        DocumentManager  $dm,
        LoggerInterface $logger,
        $redis,
        $intercom,
        $mixpanel
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->redis = $redis;
        $this->intercom = $intercom;
        $this->mixpanel = $mixpanel;
    }

    public function run($name)
    {
        return call_user_func([$this, $name]);
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
            if (!$replacementDate ||
                $now->getTimestamp() - $replacementDate->getTimestamp() > 3600) {
                throw new \Exception(sprintf(
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
            throw new \Exception(sprintf(
                'Claim %s is settled, but has not been processed (e.g. pot updated)',
                $claim->getNumber()
            ));
        }
    }

    public function daviesImport()
    {
        $fileRepo = $this->dm->getRepository(DaviesFile::class);
        $successFiles = $fileRepo->findBy(['success' => true], ['created' => 'desc'], 1);
        $successFile = count($successFiles) > 0 ? $successFiles[0] : null;
        if (!$successFile) {
            throw new \Exception('Unable to find any successful imports');
        }

        $now = $this->startOfDay(new \DateTime());
        $diff = $now->diff($successFile->getDate());
        if ($diff->days >= 1) {
            throw new \Exception(sprintf(
                'Last successful import on %s',
                $successFile->getDate()->format(\DateTime::ATOM)
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
        $newPolicies = $repo->findAllNewPolicies(null, $twoDays, $tenMinutes);
        $errors = [];
        foreach ($newPolicies as $newPolicy) {
            // only active policies and definitely not cancelled
            if (in_array($newPolicy->getStatus(), [Policy::STATUS_ACTIVE])) {
                $intercomUser = $this->intercom->getIntercomUser($newPolicy->getUser());
                if (is_object($intercomUser) && $intercomUser->custom_attributes->Premium <= 0) {
                    $errors[] = sprintf(
                        'Intercom out of sync: %s has a 0 premium in intercom, yet has a policy',
                        $newPolicy->getUser()->getEmail()
                    );
                }
            }
        }

        if ($errors) {
            throw new \Exception(json_encode($errors));
        }
    }

    public function mixpanelUserCount()
    {
        // acutal 26000 for plan
        $maxUsers = 25000;
        $total = $this->mixpanel->getUserCount();
        if ($total > $maxUsers) {
            throw new \Exception(sprintf('User count %d too high (warning %d)', $total, $maxUsers));
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
                    throw new \Exception(sprintf(
                        'Policy %s has a claim w/replacement imei that does not match current imei',
                        $policy->getId()
                    ));
                }
            }
        }
    }
}
