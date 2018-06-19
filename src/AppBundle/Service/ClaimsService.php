<?php
namespace AppBundle\Service;

use Predis\Client;
use AppBundle\Repository\PhonePolicyRepository;
use Psr\Log\LoggerInterface;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Claim;
use AppBundle\Document\LostPhone;
use AppBundle\Document\User;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Document\Form\ClaimFnol;
use AppBundle\Document\Form\ClaimFnolDamage;
use AppBundle\Document\Form\ClaimFnolTheftLoss;
use AppBundle\Document\File\ProofOfUsageFile;
use AppBundle\Document\File\DamagePictureFile;
use AppBundle\Document\File\ProofOfBarringFile;
use AppBundle\Document\File\ProofOfPurchaseFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use League\Flysystem\MountManager;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;

class ClaimsService
{

    const S3_CLAIMS_FOLDER = 'claim-documents';

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var MailerService */
    protected $mailer;

    /** @var RouterService */
    protected $routerService;

    /** @var Client */
    protected $redis;

    /** @var string */
    protected $environment;

    /** @var MountManager */
    protected $filesystem;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param MailerService   $mailer
     * @param RouterService   $routerService
     * @param Client          $redis
     * @param string          $environment
     * @param MountManager    $filesystem
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        RouterService $routerService,
        $redis,
        $environment,
        MountManager $filesystem
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->routerService = $routerService;
        $this->redis = $redis;
        $this->environment = $environment;
        $this->filesystem = $filesystem;
    }

    public function createClaim(ClaimFnol $claimFnol)
    {
        $claim = new Claim();
        
        $claim->setType($claimFnol->getType());
        $claim->setIncidentDate($claimFnol->getWhen());
        $claim->setIncidentTime($claimFnol->getTime());
        $claim->setLocation($claimFnol->getWhere());
        $claim->setDescription($claimFnol->getMessage());
        $claim->setNetwork($claimFnol->getNetwork());
        $claim->setPhoneToReach($claimFnol->getPhone());
        $claim->setTimeToReach($claimFnol->getTimeToReach());
        $claim->setSignature($claimFnol->getSignature());

        return $claim;
    }

    public function updateDamageDocuments(Claim $claim, ClaimFnolDamage $claimDamage)
    {
        $claim->setTypeDetails($claimDamage->getTypeDetails());
        $claim->setTypeDetailsOther($claimDamage->getTypeDetailsOther());
        $claim->setMonthOfPurchase($claimDamage->getMonthOfPurchase());
        $claim->setYearOfPurchase($claimDamage->getYearOfPurchase());
        $claim->setPhoneStatus($claimDamage->getPhoneStatus());
        $claim->setIsUnderWarranty($claimDamage->getIsUnderWarranty());

        if ($claimDamage->getProofOfUsage()) {
            $proofOfUsage = new ProofOfUsageFile();
            $proofOfUsage->setBucket('policy.so-sure.com');
            $proofOfUsage->setKey($claimDamage->getProofOfUsage());
            $claim->getPolicy()->addPolicyFile($proofOfUsage);
        }
        if ($claimDamage->getPictureOfPhone()) {
            $pictureOfPhone = new DamagePictureFile();
            $pictureOfPhone->setBucket('policy.so-sure.com');
            $pictureOfPhone->setKey($claimDamage->getPictureOfPhone());
            $claim->getPolicy()->addPolicyFile($pictureOfPhone);
        }
        $claim->setSubmissionDate(new \DateTime());
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $this->dm->flush();

        $this->notifyClaimSubmission($claim);
    }

    public function updateTheftLossDocuments(Claim $claim, ClaimFnolTheftLoss $claimTheftLoss)
    {
        $claim->setHasContacted($claimTheftLoss->getHasContacted());
        $claim->setContactedPlace($claimTheftLoss->getContactedPlace());
        $claim->setBlockedDate($claimTheftLoss->getBlockedDate());
        $claim->setReportedDate($claimTheftLoss->getReportedDate());
        $claim->setReportType($claimTheftLoss->getReportType());
        $claim->setCrimeRef($claimTheftLoss->getCrimeReferenceNumber());
        $claim->setPoliceLossReport($claimTheftLoss->getPoliceLossReport());

        if ($claimTheftLoss->getProofOfUsage()) {
            $proofOfUsage = new ProofOfUsageFile();
            $proofOfUsage->setBucket('policy.so-sure.com');
            $proofOfUsage->setKey($claimTheftLoss->getProofOfUsage());
            $claim->getPolicy()->addPolicyFile($proofOfUsage);
        }

        $proofOfBarring = new ProofOfBarringFile();
        $proofOfBarring->setBucket('policy.so-sure.com');
        $proofOfBarring->setKey($claimTheftLoss->getProofOfBarring());
        $claim->getPolicy()->addPolicyFile($proofOfBarring);

        if ($claimTheftLoss->getProofOfPurchase()) {
            $proofOfPurchase = new ProofOfPurchaseFile();
            $proofOfPurchase->setBucket('policy.so-sure.com');
            $proofOfPurchase->setKey($claimTheftLoss->getProofOfPurchase());
            $claim->getPolicy()->addPolicyFile($proofOfPurchase);
        }
        $claim->setSubmissionDate(new \DateTime());
        $claim->setStatus(Claim::STATUS_SUBMITTED);
        $this->dm->flush();

        $this->notifyClaimSubmission($claim);
    }

    public function addClaim(Policy $policy, Claim $claim)
    {
        $repo = $this->dm->getRepository(Claim::class);

        // Claim state for same claim number may change
        // (not yet sure if we want a new claim record vs update claim record)
        // Regardless, same claim number for different policies is not allowed
        // Also same claim number on same policy with same state is not allowed
        $duplicates = $repo->findBy(['number' => (string) $claim->getNumber()]);
        foreach ($duplicates as $duplicate) {
            if ($policy->getId() != $duplicate->getPolicy()->getId()) {
                return false;
            }
            if ($claim->getStatus() == $duplicate->getStatus()) {
                return false;
            }
        }

        $policy->addClaim($claim);
        $this->dm->flush();

        $this->processClaim($claim);
        if ($claim->getShouldCancelPolicy()) {
            $this->notifyPolicyShouldBeCancelled($policy, $claim);
        }

        return true;
    }

    public function updateClaim(Policy $policy, Claim $claim)
    {
        $repo = $this->dm->getRepository(Claim::class);

        // Claim state for same claim number may change
        // (not yet sure if we want a new claim record vs update claim record)
        // Regardless, same claim number for different policies is not allowed
        // Also same claim number on same policy with same state is not allowed
        $duplicates = $repo->findBy(['number' => (string) $claim->getNumber()]);
        foreach ($duplicates as $duplicate) {
            if ($policy->getId() != $duplicate->getPolicy()->getId()) {
                return false;
            }
            if ($claim->getStatus() == $duplicate->getStatus()) {
                return false;
            }
        }

        $this->dm->flush();

        $this->processClaim($claim);
        if ($claim->getShouldCancelPolicy()) {
            $this->notifyPolicyShouldBeCancelled($policy, $claim);
        }

        return true;
    }

    public function processClaim(Claim $claim)
    {
        $this->sendPicSureNotification($claim);
        if ($claim->getProcessed() || !$claim->isMonetaryClaim()) {
            return false;
        }

        if (!$claim->getPolicy() instanceof PhonePolicy) {
            throw new \Exception('not policy');
        }
        /** @var PhonePolicy $policy */
        $policy = $claim->getPolicy();
        $claim->getPolicy()->updatePotValue();
        $this->dm->flush();
        $this->notifyMonetaryClaim($claim->getPolicy(), $claim, true);
        foreach ($claim->getPolicy()->getConnections() as $networkConnection) {
            if ($networkConnection instanceof RewardConnection) {
                $networkConnection->clearValue();
                continue;
            }
            $networkConnection->getLinkedPolicy()->updatePotValue();
            $this->dm->flush();
            $this->notifyMonetaryClaim($networkConnection->getLinkedPolicy(), $claim, false);
        }

        if ($policy->canAdjustPicSureStatusForClaim()) {
            $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED);
        }
        $claim->setProcessed(true);
        $this->recordLostPhone($claim->getPolicy(), $claim);
        $this->dm->flush();
        return true;
    }

    public function sendPicSureNotification(Claim $claim)
    {
        if ($claim->getStatus() == Claim::STATUS_APPROVED &&
            $claim->getApprovedDate() &&
            $claim->getApprovedDate()->diff(new \DateTime())->days < 2) {
            /** @var PhonePolicy $policy */
            $policy = $claim->getPolicy();
            if ($policy->getPicSureStatus() == PhonePolicy::PICSURE_STATUS_APPROVED
                && $policy->getPicSureApprovedDate()) {
                $picSureApprovedDate = $policy->getPicSureApprovedDate();
                $diff = $picSureApprovedDate->diff(new \DateTime());
                if ($diff->days < 30) {
                    try {
                        $subject = 'Pic-sure validated claim needs review';
                        $templateHtml = "AppBundle:Email:claim/checkRecentPicSureApproved.html.twig";
                        $this->mailer->sendTemplate(
                            $subject,
                            'tech@so-sure.com',
                            $templateHtml,
                            ['policy' => $claim->getPolicy()]
                        );
                    } catch (\Exception $ex) {
                        $this->logger->error(
                            "Error sending pic-sure validated claim review email.",
                            ['exception' => $ex]
                        );
                    }
                }
            }
        }
    }

    public function recordLostPhone(Policy $policy, Claim $claim)
    {
        if (!$claim->isOwnershipTransferClaim()) {
            return;
        }

        /** @var PhonePolicy $phonePolicy */
        $phonePolicy = $policy;

        // Check if phone has been 'lost' multiple times
        $repo = $this->dm->getRepository(LostPhone::class);
        /** @var LostPhone $lost */
        $lost = $repo->findOneBy(['imei' => $phonePolicy->getImei()]);
        if ($lost) {
            $this->logger->error(sprintf(
                'Imei (%s) that was previously reported as lost is being reported as lost again.',
                $phonePolicy->getImei()
            ));
        }

        $lost = new LostPhone();
        $lost->populate($phonePolicy);
        $this->dm->persist($lost);
        $this->dm->flush();

        return $lost;
    }

    public function notifyMonetaryClaim(Policy $policy, Claim $claim, $isClaimer)
    {
        try {
            $subject = sprintf(
                'Your friend, %s, has made a claim.',
                $claim->getPolicy()->getUser()->getName()
            );
            $templateHtml = "AppBundle:Email:claim/friend.html.twig";
            $templateText = "AppBundle:Email:claim/friend.txt.twig";
            if ($isClaimer) {
                $subject = sprintf(
                    "Sorry to hear something happened to your phone. We hope you're okay."
                );
                $templateHtml = "AppBundle:Email:claim/self.html.twig";
                $templateText = "AppBundle:Email:claim/self.txt.twig";
            }

            $this->mailer->sendTemplate(
                $subject,
                $policy->getUser()->getEmail(),
                $templateHtml,
                ['claim' => $claim, 'policy' => $policy],
                $templateText,
                ['claim' => $claim, 'policy' => $policy]
            );
        } catch (\Exception $e) {
            $this->logger->error(sprintf("Error in notifyMonetaryClaim. Ex: %s", $e->getMessage()));
        }
    }

    public function notifyPolicyShouldBeCancelled(Policy $policy, Claim $claim)
    {
        try {
            $subject = sprintf(
                'Policy %s should be cancelled',
                $claim->getPolicy()->getPolicyNumber()
            );
            if ($this->environment != 'prod') {
                $subject = sprintf('[%s] %s', $this->environment, $subject);
            }
            $templateHtml = "AppBundle:Email:claim/shouldBeCancelled.html.twig";

            $this->mailer->sendTemplate(
                $subject,
                'support@wearesosure.com',
                $templateHtml,
                ['claim' => $claim, 'policy' => $policy]
            );
        } catch (\Exception $e) {
            $this->logger->error("Error in notifyPolicyShouldBeCancelled.", ['exception' => $e]);
        }
    }

    public function notifyClaimSubmission(Claim $claim)
    {
        $subject = sprintf(
            'New Claim from %s/%s',
            $claim->getPolicy()->getUser()->getName(),
            $claim->getPolicy()->getPolicyNumber()
        );
        $this->mailer->sendTemplate(
            $subject,
            'new-claim@wearesosure.com',
            'AppBundle:Email:claim/fnolToClaims.html.twig',
            ['data' => $claim]
        );

        $this->mailer->sendTemplate(
            'Your claim with so-sure',
            $claim->getPolicy()->getUser()->getEmail(),
            'AppBundle:Email:claim/fnolResponse.html.twig',
            ['data' => $claim],
            'AppBundle:Email:claim/fnolResponse.txt.twig',
            ['data' => $claim]
        );
    }

    public function setMailerMailer($mailer)
    {
        $this->mailer->setMailer($mailer);
    }

    public function sendUniqueLoginLink(User $user)
    {
        try {
            $token = md5(sprintf('%s%s', time(), $user->getEmail()));
            $this->redis->setex($token, 900, $user->getId());

            $data = [
                'username' => $user->getName(),
                'tokenUrl' => $this->routerService->generate(
                    'claim_login',
                    ['tokenId' => $token]
                ),
                'tokenValid' => 15
            ];

            var_dump($data);

            $this->mailer->sendTemplate(
                'Your link to proceed with your claim',
                $user->getEmail(),
                "AppBundle:Email:claim/loginLink.html.twig",
                $data,
                "AppBundle:Email:claim/loginLink.txt.twig",
                $data
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error in sendUniqueLoginLink.", ['exception' => $e]);
        }

        return false;
    }

    public function getUserIdFromLoginLinkToken($tokenId)
    {
        return $this->redis->get($tokenId);
    }

    public function withdrawClaim(Claim $claim)
    {
        try {
            $claim->setStatus(Claim::STATUS_WITHDRAWN);
            $this->dm->flush();
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public function saveFile($filename, $s3filename, $userId, $extension)
    {
        /** @var Filesystem $fs */
        $fs = $this->filesystem->getFilesystem('s3policy_fs');
        /** @var AwsS3Adapter $s3Adapater */
        $s3Adapater = $fs->getAdapter();
        $bucket = $s3Adapater->getBucket();
        $pathPrefix = $s3Adapater->getPathPrefix();
        $key = sprintf(
            '%s/%s/%s.%s',
            self::S3_CLAIMS_FOLDER,
            $userId,
            $s3filename,
            $extension
        );
        $stream = fopen($filename, 'r+');
        $fs->writeStream($key, $stream);
        fclose($stream);

        return sprintf("%s/%s", $this->environment, $key);
    }
}
