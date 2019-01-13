<?php

namespace AppBundle\Listener;

use Psr\Log\LoggerInterface;
use Swift_Events_SendEvent;
use Swift_Events_SendListener;

class MailerListener implements Swift_Events_SendListener
{
    /** @var LoggerInterface|null */
    protected $logger;
    protected $spoolPath;
    
    public function __construct(LoggerInterface $logger = null, $spoolPath = null)
    {
        $this->logger = $logger;
        $this->spoolPath = $spoolPath;
    }

    public function beforeSendPerformed(Swift_Events_SendEvent $evt)
    {
        // Needs to be implemented
    }

    public function sendPerformed(Swift_Events_SendEvent $evt)
    {
        $result = 'Unknown';
        $level = 'info';
        $respool = false;
        if ($evt->getResult() == Swift_Events_SendEvent::RESULT_PENDING) {
            $result = 'Pending';
        } elseif ($evt->getResult() == Swift_Events_SendEvent::RESULT_SPOOLED) {
            $result = 'Spooled';
        } elseif ($evt->getResult() == Swift_Events_SendEvent::RESULT_SUCCESS) {
            $result = 'Sent';
        } elseif ($evt->getResult() == Swift_Events_SendEvent::RESULT_TENTATIVE) {
            $result = 'Tentative';
        } elseif ($evt->getResult() == Swift_Events_SendEvent::RESULT_FAILED) {
            $result = 'Failed';
            $level = 'error';
            $respool = true;
        }

        if ($respool && $this->spoolPath) {
            $spool = new \Swift_FileSpool($this->spoolPath);
            $message = $evt->getMessage();
            /*
            $message->getHeaders()->get('To')->setNameAddresses($evt->getFailedRecipients());
            $message->getHeaders()->get('Cc')->setNameAddresses(null);
            $message->getHeaders()->get('Bcc')->setNameAddresses(['bcc@so-sure.com']);
            */
            $spool->queueMessage($message);
        }

        $msg = sprintf(
            'Email %s %s [%s]',
            $result,
            json_encode($evt->getMessage()->getTo()),
            $evt->getMessage()->getSubject()
        );

        if ($this->logger) {
            $msg = sprintf(
                'Email %s %s [%s]',
                $result,
                json_encode($evt->getMessage()->getTo()),
                $evt->getMessage()->getSubject()
            );
            call_user_func([$this->logger, $level], $msg);
        }
    }
}
