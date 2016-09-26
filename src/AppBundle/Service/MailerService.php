<?php
namespace AppBundle\Service;

class MailerService
{
    /** @var \Swift_Mailer */
    protected $mailer;
    protected $smtp;
    protected $templating;
    protected $router;

    /** @var string */
    protected $defaultSenderAddress;

    /** @var string */
    protected $defaultSenderName;

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
        $textTemplate,
        $textData,
        $attachmentFiles = null
    ) {
        return $this->send(
            $subject,
            $to,
            $this->templating->render($htmlTemplate, $htmlData),
            $this->templating->render($textTemplate, $textData),
            $attachmentFiles
        );
    }

    public function send($subject, $to, $htmlBody, $textBody = null, $attachmentFiles = null)
    {
        $message = \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom([$this->defaultSenderAddress => $this->defaultSenderName])
            ->setTo($to)
            ->setBody($htmlBody, 'text/html');

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
