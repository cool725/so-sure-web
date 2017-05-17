<?php

namespace AppBundle\Listener;

use Psr\Log\LoggerInterface;
use Swift_Events_SendEvent;
use Swift_Events_SendListener;

class MailerListener implements Swift_Events_SendListener
{
    /** @var LoggerInterface */
    protected $logger;
    
    public function __construct(LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    public function beforeSendPerformed(Swift_Events_SendEvent $evt)
    {
        // Needs to be implemented
    }

    public function sendPerformed(Swift_Events_SendEvent $evt)
    {
        $result = 'Unknown';
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
        }
        if ($this->logger) {
            $this->logger->info(sprintf(
                'Email %s %s [%s]',
                $result,
                json_encode($evt->getMessage()->getTo()),
                $evt->getMessage()->getSubject()
            ));
        }
    }
}
