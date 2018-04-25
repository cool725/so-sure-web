<?php
namespace AppBundle\Service;

use AppBundle\Document\OptOut\OptOut;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Templating\EngineInterface;

class MailerService
{
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

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * @param \Swift_Mailer    $mailer
     * @param \Swift_Transport $smtp
     * @param EngineInterface  $templating
     * @param RouterService    $routerService
     * @param string           $defaultSenderAddress
     * @param string           $defaultSenderName
     */
    public function __construct(
        \Swift_Mailer $mailer,
        \Swift_Transport $smtp,
        EngineInterface $templating,
        RouterService $routerService,
        $defaultSenderAddress,
        $defaultSenderName
    ) {
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->routerService = $routerService;
        $this->defaultSenderAddress = $defaultSenderAddress;
        $this->defaultSenderName = $defaultSenderName;
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
        $emailType = null,
        $from = null
    ) {
        $this->addUnsubsribeHash($to, $htmlData, $emailType);
        // print $subject;
        // base campaign on template name
        // AppBundle:Email:quote/priceGuarentee.html.twig
        $campaign = $htmlTemplate;
        if (mb_stripos($campaign, ':')) {
            $campaignItems = explode(':', $campaign);
            $campaign = $campaignItems[count($campaignItems) - 1];
        }
        $campaign = explode('.', $campaign)[0];

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

    private function addUnsubsribeHash($to, &$array, $emailType = null)
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
            if ($emailType) {
                $data['cat'] = $this->emailTypeToOptOut($emailType);
            }
            $array['unsubscribe_url'] = $this->routerService->generateUrl(
                'optout_hash',
                $data
            );
        } else {
            $array['unsubscribe_url'] = "mailto:hello@wearesosure.com?Subject=I don't want these emails anymore!";
        }
    }

    private function emailTypeToOptOut($emailType)
    {
        if ($emailType == self::EMAIL_WEEKLY) {
            return OptOut::OPTOUT_CAT_WEEKLY;
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
        $message = \Swift_Message::newInstance()
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
