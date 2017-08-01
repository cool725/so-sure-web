<?php
namespace AppBundle\Service;

use AppBundle\Document\OptOut\OptOut;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class MailerService
{
    const EMAIL_WEEKLY = 'weekly';

    /** @var \Swift_Mailer */
    protected $mailer;
    protected $smtp;
    protected $templating;
    protected $router;

    /** @var string */
    protected $defaultSenderAddress;

    /** @var string */
    protected $defaultSenderName;

    public function setMailer($mailer)
    {
        $this->mailer = $mailer;
    }

    /**
     * @param \Swift_Mailer $mailer
     * @param               $smtp
     * @param               $templating
     * @param               $router
     * @param string        $defaultSenderAddress
     * @param string        $defaultSenderName
     */
    public function __construct(
        \Swift_Mailer $mailer,
        $smtp,
        $templating,
        $router,
        $defaultSenderAddress,
        $defaultSenderName
    ) {
        $this->mailer = $mailer;
        $this->smtp = $smtp;
        $this->templating = $templating;
        $this->router = $router->getRouter();
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

        // base campaign on template name
        // AppBundle:Email:quote/priceGuarentee.html.twig
        $campaign = $htmlTemplate;
        if (stripos($campaign, ':')) {
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
            $array['unsubscribe_url'] = $this->router->generate(
                'optout_hash',
                $data,
                UrlGeneratorInterface::ABSOLUTE_URL
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
                sprintf('{"campaign_id": "%s"}', substr($campaign, 0, 63))
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
