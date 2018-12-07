<?php
namespace AppBundle\Service;

use AppBundle\Document\Opt\OptOut;
use AppBundle\Document\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;

class MailerService
{
    use RouterTrait;

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

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * An array of campaigns that should trigger an analytics event (e.g. Mixpanel)
     * @var array
     */
    public static $analyticsCampaigns = [
        'card/cardExpiring',
        'card/failedPayment-1',
        'card/failedPayment-2',
        'card/failedPayment-3',
        'card/failedPayment-4',
        'policy/failedPaymentWithClaim-1',
        'policy/failedPaymentWithClaim-2',
        'policy/failedPaymentWithClaim-3',
        'policy/failedPaymentWithClaim-4',
    ];

    /**
     * @param \Swift_Mailer    $mailer
     * @param \Swift_Transport $smtp
     * @param EngineInterface  $templating
     * @param RouterService    $routerService
     * @param string           $defaultSenderAddress
     * @param string           $defaultSenderName
     * @param MixpanelService  $mixpanelService
     */
    public function __construct(
        \Swift_Mailer $mailer,
        \Swift_Transport $smtp,
        EngineInterface $templating,
        RouterService $routerService,
        $defaultSenderAddress,
        $defaultSenderName,
        MixpanelService $mixpanelService
    ) {
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->routerService = $routerService;
        $this->defaultSenderAddress = $defaultSenderAddress;
        $this->defaultSenderName = $defaultSenderName;
        $this->mixpanelService = $mixpanelService;
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

        if ($textTemplate && $textData) {
            $this->addUnsubsribeHash($to, $textData);

            return $this->send(
                $subject,
                $to,
                $this->templating->render($htmlTemplate, $htmlData),
                $this->templating->render($textTemplate, $textData),
                $attachmentFiles,
                $bcc,
                $from,
                $campaign
            );
        } else {
            return $this->send(
                $subject,
                $to,
                $this->templating->render($htmlTemplate, $htmlData),
                null,
                $attachmentFiles,
                $bcc,
                $from,
                $campaign
            );
        }
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
        $campaign = null
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

        if ($attachmentFiles) {
            foreach ($attachmentFiles as $attachmentFile) {
                unlink($attachmentFile);
            }
        }
    }
}
