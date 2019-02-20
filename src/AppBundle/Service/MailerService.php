<?php
namespace AppBundle\Service;

use AppBundle\Classes\SoSure;
use AppBundle\Document\File\EmailFile;
use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\Policy;
use AppBundle\Document\User;
use Aws\S3\S3Client;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;

class MailerService
{
    use RouterTrait;

    const TRUSTPILOT_PURCHASE = '529c0abfefb96008b894ad02';

    const EMAIL_WEEKLY = 'weekly';

    /** @var \Swift_Mailer */
    protected $mailer;

    /**
     * @var \Swift_Transport
     */
    protected $smtp;

    /** @var EngineInterface */
    protected $templating;

    /** @var RouterService */
    protected $routerService;

    /** @var string */
    protected $defaultSenderAddress;

    /** @var string */
    protected $defaultSenderName;

    /** @var MixpanelService */
    protected $mixpanelService;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $environment;

    /** @var DocumentManager */
    protected $dm;

    /** @var bool */
    protected $uploadEmail;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * An array of campaigns that should trigger an analytics event (e.g. Mixpanel)
     * @var array
     */
    public static $analyticsCampaigns = [
        'bacs/bacsPaymentFailed-1',
        'bacs/bacsPaymentFailed-2',
        'bacs/bacsPaymentFailed-3',
        'bacs/bacsPaymentFailed-4',
        'bacs/bacsPaymentFailedMandateCancelled-1',
        'bacs/bacsPaymentFailedMandateCancelled-2',
        'bacs/bacsPaymentFailedMandateCancelled-3',
        'bacs/bacsPaymentFailedMandateCancelled-4',
        'bacs/bacsPaymentFailedWithClaim-1',
        'bacs/bacsPaymentFailedWithClaim-2',
        'bacs/bacsPaymentFailedWithClaim-3',
        'bacs/bacsPaymentFailedWithClaim-4',
        'bacs/bacsPaymentFailedWithClaimMandateCancelled-3',
        'bacs/bacsPaymentFailedWithClaimMandateCancelled-4',
        'card/cardExpiring',
        'card/cardMissing-1',
        'card/cardMissing-2',
        'card/cardMissing-3',
        'card/cardMissing-4',
        'card/cardMissingWithClaim-4',
        'card/failedPayment-1',
        'card/failedPayment-2',
        'card/failedPayment-3',
        'card/failedPayment-4',
        'card/failedPaymentWithClaim-1',
        'card/failedPaymentWithClaim-2',
        'card/failedPaymentWithClaim-3',
        'card/failedPaymentWithClaim-4',
    ];

    /**
     * @param \Swift_Mailer    $mailer
     * @param \Swift_Transport $smtp
     * @param EngineInterface  $templating
     * @param RouterService    $routerService
     * @param string           $defaultSenderAddress
     * @param string           $defaultSenderName
     * @param MixpanelService  $mixpanelService
     * @param S3Client         $s3Client
     * @param string           $environment
     * @param DocumentManager  $dm
     * @param boolean          $uploadEmail
     */
    public function __construct(
        \Swift_Mailer $mailer,
        \Swift_Transport $smtp,
        EngineInterface $templating,
        RouterService $routerService,
        $defaultSenderAddress,
        $defaultSenderName,
        MixpanelService $mixpanelService,
        S3Client $s3Client,
        $environment,
        DocumentManager $dm,
        $uploadEmail
    ) {
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->routerService = $routerService;
        $this->defaultSenderAddress = $defaultSenderAddress;
        $this->defaultSenderName = $defaultSenderName;
        $this->mixpanelService = $mixpanelService;
        $this->s3 = $s3Client;
        $this->environment = $environment;
        $this->dm = $dm;
        $this->uploadEmail = $uploadEmail;
    }

    public function sendTemplateToUser(
        $subject,
        User $user,
        $htmlTemplate,
        $htmlData,
        $textTemplate = null,
        $textData = null,
        $attachmentFiles = null,
        $bcc = null,
        $from = null
    ) {
        return $this->sendTemplate(
            $subject,
            $user->getEmail(),
            $htmlTemplate,
            $htmlData,
            $textTemplate,
            $textData,
            $attachmentFiles,
            $bcc,
            $from,
            $user
        );
    }

    public function sendTemplate(
        $subject,
        $to,
        $htmlTemplate,
        $htmlData,
        $textTemplate = null,
        $textData = null,
        $attachmentFiles = null,
        $bcc = null,
        $from = null,
        User $user = null
    ) {
        $this->addUnsubsribeHash($to, $htmlData);

        $campaign = $this->getCampaign($htmlTemplate);

        if ($user && in_array($campaign, self::$analyticsCampaigns)) {
            $this->mixpanelService->queueTrackWithUser(
                $user,
                MixpanelService::EVENT_EMAIL,
                ['campaign' => $campaign]
            );
        }

        $text = null;
        if ($textTemplate && $textData) {
            $text = $this->templating->render($textTemplate, $textData);
            $this->addUnsubsribeHash($to, $textData);
        }

        return $this->send(
            $subject,
            $to,
            $this->templating->render($htmlTemplate, $htmlData),
            $text,
            $attachmentFiles,
            $bcc,
            $from,
            $campaign,
            $user
        );
    }

    private function addUnsubsribeHash($to, &$array)
    {
        $hash = null;
        // TODO: Add swiftmailer header check
        if (is_string($to)) {
            $hash = urlencode(base64_encode($to));
        } elseif (is_array($to)) {
            $hash = urlencode(base64_encode(array_keys($to)[0]));
        }
        if ($hash) {
            $data = ['hash' => $hash];
            $array['unsubscribe_url'] = $this->routerService->generateUrl(
                'optout_hash',
                $data
            );
        } else {
            $array['unsubscribe_url'] = "mailto:hello@wearesosure.com?Subject=I don't want these emails anymore!";
        }
    }

    public function send(
        $subject,
        $to,
        $htmlBody,
        $textBody = null,
        $attachmentFiles = null,
        $bcc = null,
        $from = null,
        $campaign = null,
        User $user = null
    ) {
        if (!$from) {
            $from = [$this->defaultSenderAddress => $this->defaultSenderName];
        }
        $message = new \Swift_Message();
        $message
            ->setSubject($subject)
            ->setFrom($from)
            ->setTo($to)
            ->setBody($htmlBody, 'text/html');

        if ($campaign) {
            $headers = $message->getHeaders();
            $headers->addTextHeader(
                'X-MSYS-API',
                sprintf('{"campaign_id": "%s"}', mb_substr($campaign, 0, 63))
            );
        }

        if ($bcc) {
            $message->setBcc($bcc);
        }

        if ($textBody) {
            $message->addPart($textBody, 'text/plain');
        }

        if ($attachmentFiles) {
            // If there's attachments, make sure we send directly to smtp, instead of queueing
            $mailer = new \Swift_Mailer($this->smtp);
            foreach ($attachmentFiles as $attachmentFile) {
                $message->attach(\Swift_Attachment::fromPath($attachmentFile));
            }
        } else {
            $mailer = $this->mailer;
        }

        $mailer->send($message);

        if ($this->uploadEmail && $user) {
            $this->uploadS3($user, $to, $subject, $message->toString());
        }

        if ($attachmentFiles) {
            foreach ($attachmentFiles as $attachmentFile) {
                unlink($attachmentFile);
            }
        }
    }

    public function trustpilot(Policy $policy, $template)
    {
        $body = $this->templating->render(
            'AppBundle:Email:system/trustpilot.html.twig',
            ['policy' => $policy, 'template_id' => $template]
        );

        return $this->send(
            'Data Export',
            'f9e2e9f7ce@invite.trustpilot.com',
            $body
        );
    }

    public function uploadS3(
        User $user,
        $to,
        $subject,
        $data
    ) {
        $date = \DateTime::createFromFormat('U', time());

        $filename = sprintf('email-%s-%s.eml', $user->getId(), $date->format('U'));
        $file = sprintf('%s/%s', sys_get_temp_dir(), $filename);
        file_put_contents($file, $data);

        $s3Key = $this->getS3Key($user, $filename);

        $this->s3->putObject(array(
            'Bucket' => SoSure::S3_BUCKET_POLICY,
            'Key' => $s3Key,
            'SourceFile' => $file,
        ));

        $emailFile = new EmailFile();
        $emailFile->setBucket(SoSure::S3_BUCKET_POLICY);
        $emailFile->setKey($s3Key);
        $emailFile->setDate($date);
        $emailFile->addMetadata('subject', $subject);
        $emailFile->addMetadata('to', $to);

        $user->addUserFile($emailFile);

        $this->dm->persist($emailFile);
        $this->dm->flush();

        unlink($file);

        return $s3Key;
    }

    public function getS3Key(User $user, $filename)
    {
        $date = \DateTime::createFromFormat('U', time());
        return sprintf('%s/email/user-%s/%s', $this->environment, $user->getId(), $filename);
    }
}
