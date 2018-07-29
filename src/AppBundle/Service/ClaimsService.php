<?php
namespace AppBundle\Service;

use AppBundle\Document\File\ProofOfLossFile;
use AppBundle\Document\File\S3ClaimFile;
use AppBundle\Document\File\S3File;
use AppBundle\Document\ValidatorTrait;
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
use AppBundle\Document\Form\ClaimFnolUpdate;
use AppBundle\Document\File\ProofOfUsageFile;
use AppBundle\Document\File\DamagePictureFile;
use AppBundle\Document\File\ProofOfBarringFile;
use AppBundle\Document\File\ProofOfPurchaseFile;
use AppBundle\Document\File\OtherClaimFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use League\Flysystem\MountManager;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;

class ClaimsService
{
    use ValidatorTrait;

    const S3_POLICY_BUCKET = 'policy.so-sure.com';
    const S3_CLAIMS_FOLDER = 'claim-documents';
    const LOGIN_LINK_TOKEN_EXPIRATION = 7200; // 2 hours

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var MailerService */
    protected $mailer;

    /** @var RouterService */
    protected $routerService;

    /** @var ReceperioService */
    protected $imeiService;

    /** @var Client */
    protected $redis;

    /** @var string */
    protected $environment;

    /** @var MountManager */
    protected $filesystem;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param MailerService    $mailer
     * @param RouterService    $routerService
     * @param ReceperioService $imeiService
     * @param Client           $redis
     * @param string           $environment
     * @param MountManager     $filesystem
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        RouterService $routerService,
        ReceperioService $imeiService,
        $redis,
        $environment,
        MountManager $filesystem
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->routerService = $routerService;
        $this->imeiService = $imeiService;
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
        $claim->setLocation($this->conformAlphanumericSpaceDot($claimFnol->getWhere(), 250));
        $claim->setDescription($this->conformAlphanumericSpaceDot($claimFnol->getMessage(), 5000));
        $claim->setNetwork($claimFnol->getNetwork());
        $claim->setPhoneToReach($claimFnol->getPhone());
        $claim->setTimeToReach($claimFnol->getTimeToReach());
        $claim->setSignature($claimFnol->getSignature());
        $claim->setStatus(Claim::STATUS_FNOL);

        return $claim;
    }

    public function updateDamageDocuments(Claim $claim, ClaimFnolDamage $claimDamage, $submit = false)
    {
        $claim->setTypeDetails($claimDamage->getTypeDetails());
        $claim->setTypeDetailsOther($claimDamage->getTypeDetailsOther());
        $claim->setMonthOfPurchase($claimDamage->getMonthOfPurchase());
        $claim->setYearOfPurchase($claimDamage->getYearOfPurchase());
        $claim->setPhoneStatus($claimDamage->getPhoneStatus());

        if ($claimDamage->getProofOfUsage()) {
            $proofOfUsage = new ProofOfUsageFile();
            $proofOfUsage->setBucket(self::S3_POLICY_BUCKET);
            $proofOfUsage->setKey($claimDamage->getProofOfUsage());
            $claim->addFile($proofOfUsage);
        }
        if ($claimDamage->getProofOfPurchase()) {
            $proofOfPurchase = new ProofOfPurchaseFile();
            $proofOfPurchase->setBucket(self::S3_POLICY_BUCKET);
            $proofOfPurchase->setKey($claimDamage->getProofOfPurchase());
            $claim->addFile($proofOfPurchase);
        }
        if ($claimDamage->getPictureOfPhone()) {
            $pictureOfPhone = new DamagePictureFile();
            $pictureOfPhone->setBucket(self::S3_POLICY_BUCKET);
            $pictureOfPhone->setKey($claimDamage->getPictureOfPhone());
            $claim->addFile($pictureOfPhone);
        }
        if ($submit) {
            $claim->setSubmissionDate(new \DateTime());
            $claim->setStatus(Claim::STATUS_SUBMITTED);
            $this->notifyClaimSubmission($claim);
        }

        $this->dm->flush();
    }

    public function updateTheftLossDocuments(Claim $claim, ClaimFnolTheftLoss $claimTheftLoss, $submit = false)
    {
        $claim->setHasContacted($claimTheftLoss->getHasContacted());
        $claim->setContactedPlace($claimTheftLoss->getContactedPlace());
        $claim->setBlockedDate($claimTheftLoss->getBlockedDate());
        $claim->setReportedDate($claimTheftLoss->getReportedDate());
        $claim->setReportType($claimTheftLoss->getReportType());
        $claim->setCrimeRef($claimTheftLoss->getCrimeReferenceNumber());
        $claim->setForce($claimTheftLoss->getForce());

        $validCrimeRef = $this->imeiService->validateCrimeRef($claim->getForce(), $claim->getCrimeRef());
        $claim->setValidCrimeRef($validCrimeRef);

        if ($claimTheftLoss->getProofOfUsage()) {
            $proofOfUsage = new ProofOfUsageFile();
            $proofOfUsage->setBucket(self::S3_POLICY_BUCKET);
            $proofOfUsage->setKey($claimTheftLoss->getProofOfUsage());
            $claim->addFile($proofOfUsage);
        }

        if ($claimTheftLoss->getProofOfBarring()) {
            $proofOfBarring = new ProofOfBarringFile();
            $proofOfBarring->setBucket(self::S3_POLICY_BUCKET);
            $proofOfBarring->setKey($claimTheftLoss->getProofOfBarring());
            $claim->addFile($proofOfBarring);
        }

        if ($claimTheftLoss->getProofOfPurchase()) {
            $proofOfPurchase = new ProofOfPurchaseFile();
            $proofOfPurchase->setBucket(self::S3_POLICY_BUCKET);
            $proofOfPurchase->setKey($claimTheftLoss->getProofOfPurchase());
            $claim->addFile($proofOfPurchase);
        }

        if ($claimTheftLoss->getProofOfLoss()) {
            $proofOfLoss = new ProofOfLossFile();
            $proofOfLoss->setBucket(self::S3_POLICY_BUCKET);
            $proofOfLoss->setKey($claimTheftLoss->getProofOfLoss());
            $claim->addFile($proofOfLoss);
        }

        if ($submit) {
            $claim->setSubmissionDate(new \DateTime());
            $claim->setStatus(Claim::STATUS_SUBMITTED);
            $this->notifyClaimSubmission($claim);
        }

        $this->dm->flush();
    }

    public function updateDocuments(Claim $claim, ClaimFnolUpdate $claimUpdate)
    {
        $attachments = [];
        if ($claimUpdate->getProofOfUsage()) {
            $proofOfUsage = new ProofOfUsageFile();
            $proofOfUsage->setBucket(self::S3_POLICY_BUCKET);
            $proofOfUsage->setKey($claimUpdate->getProofOfUsage());
            $proofOfUsage->setClaim($claim);
            $claim->addFile($proofOfUsage);
            $attachments[] = $proofOfUsage;
        }
        if ($claimUpdate->getPictureOfPhone()) {
            $pictureOfPhone = new DamagePictureFile();
            $pictureOfPhone->setBucket(self::S3_POLICY_BUCKET);
            $pictureOfPhone->setKey($claimUpdate->getPictureOfPhone());
            $claim->addFile($pictureOfPhone);
            $attachments[] = $pictureOfPhone;
        }
        if ($claimUpdate->getProofOfBarring()) {
            $proofOfBarring = new ProofOfBarringFile();
            $proofOfBarring->setBucket(self::S3_POLICY_BUCKET);
            $proofOfBarring->setKey($claimUpdate->getProofOfBarring());
            $claim->addFile($proofOfBarring);
            $attachments[] = $proofOfBarring;
        }
        if ($claimUpdate->getProofOfPurchase()) {
            $proofOfPurchase = new ProofOfPurchaseFile();
            $proofOfPurchase->setBucket(self::S3_POLICY_BUCKET);
            $proofOfPurchase->setKey($claimUpdate->getProofOfPurchase());
            $claim->addFile($proofOfPurchase);
            $attachments[] = $proofOfPurchase;
        }
        if ($claimUpdate->getProofOfLoss()) {
            $proofOfLoss = new ProofOfLossFile();
            $proofOfLoss->setBucket(self::S3_POLICY_BUCKET);
            $proofOfLoss->setKey($claimUpdate->getProofOfLoss());
            $claim->addFile($proofOfLoss);
            $attachments[] = $proofOfLoss;
        }
        if ($claimUpdate->getOther()) {
            $other = new OtherClaimFile();
            $other->setBucket(self::S3_POLICY_BUCKET);
            $other->setKey($claimUpdate->getOther());
            $claim->addFile($other);
            $attachments[] = $other;
        }
        $this->dm->flush();

        $this->notifyClaimAdditionalDocuments($claim, $attachments);
    }

    public function addClaim(Policy $policy, Claim $claim)
    {
        $repo = $this->dm->getRepository(Claim::class);

        $duplicates = $repo->findBy(['number' => (string) $claim->getNumber()]);
        if (count($duplicates) > 0) {
            return false;
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

        $duplicates = $repo->findBy(['number' => (string) $claim->getNumber()]);
        foreach ($duplicates as $duplicate) {
            if ($policy->getId() != $duplicate->getPolicy()->getId()) {
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

    public function notifyFnolSubmission(Claim $claim)
    {
        $this->mailer->sendTemplate(
            'Your claim with so-sure',
            $claim->getPolicy()->getUser()->getEmail(),
            'AppBundle:Email:claim/fnolInitialResponse.html.twig',
            ['data' => $claim],
            'AppBundle:Email:claim/fnolInitialResponse.txt.twig',
            ['data' => $claim]
        );
    }

    private function downloadAttachmentFiles(Claim $claim)
    {
        $files = [];
        foreach ($claim->getAttachmentFiles() as $file) {
            /** @var S3ClaimFile $file */
            $files[] = $this->downloadS3($file);
        }

        return $files;
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
            ['data' => $claim],
            null,
            null,
            $this->downloadAttachmentFiles($claim)
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

    public function notifyClaimAdditionalDocuments(Claim $claim, array $attachments)
    {
        $localAttachments = [];
        foreach ($attachments as $attachment) {
            /** @var S3ClaimFile $attachment */
            $localAttachments[] = $this->downloadS3($attachment);
        }
        $subject = sprintf(
            'Additional Documents for Policy %s',
            $claim->getPolicy()->getPolicyNumber()
        );
        $this->mailer->sendTemplate(
            $subject,
            'update-claim@wearesosure.com',
            'AppBundle:Email:claim/fnolToClaims.html.twig',
            ['data' => $claim],
            null,
            null,
            $localAttachments
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
            $this->redis->setex($token, self::LOGIN_LINK_TOKEN_EXPIRATION, $user->getId());

            $data = [
                'username' => $user->getName(),
                'tokenUrl' => $this->routerService->generateUrl(
                    'claim_login',
                    ['tokenId' => $token]
                ),
                'tokenValid' => self::LOGIN_LINK_TOKEN_EXPIRATION / 3600, // hours
                'tokenValidTimeframe' => 'hours'
            ];

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

    public function uploadS3($filename, $s3filename, $userId, $extension)
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

    public function downloadS3(S3File $s3File)
    {
        $filename = $s3File->getFilename();
        if (!$filename || mb_strlen($filename) == 0) {
            $key = explode('/', $s3File->getKey());
            $filename = $key[count($key) - 1];
        }
        $tempFile = sprintf('%s/%s', sys_get_temp_dir(), $filename);

        /** @var Filesystem $fs */
        $fs = $this->filesystem->getFilesystem('s3policy_fs');
        /** @var AwsS3Adapter $s3Adapater */
        $s3Adapater = $fs->getAdapter();

        $key = str_replace(sprintf('%s/', $this->environment), '', $s3File->getKey());

        if (!$s3Adapater->has($key)) {
            throw new \Exception(sprintf('URL not found %s', $key));
        }

        $contents = $s3Adapater->read($key);
        file_put_contents($tempFile, $contents);

        return $tempFile;
    }
}
