<?php

namespace AppBundle\Listener;

use AppBundle\Document\User;
use AppBundle\Event\UserEvent;
use AppBundle\Event\CompanyEvent;
use AppBundle\Service\MailerService;
use AppBundle\Service\SanctionsService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Psr\Log\LoggerInterface;

class SanctionsListener
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var MailerService */
    protected $mailer;

    protected $sanctions;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param MailerService    $mailer
     * @param SanctionsService $sanctions
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        MailerService $mailer,
        SanctionsService $sanctions
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->mailer = $mailer;
        $this->sanctions = $sanctions;
    }

    /**
     * @param UserEvent $event
     */
    public function onUserCreatedEvent(UserEvent $event)
    {
        $user = $event->getUser();
        $matches = $this->sanctions->checkUser($user);
        if (count($matches) > 0) {
            $this->mailer->sendTemplate(
                sprintf('Sanctions Verification Required for %s', $user->getName()),
                'tech@so-sure.com',
                'AppBundle:Email:user/admin_sanctions.html.twig',
                ['user' => $user, 'matches' => json_encode($matches)]
            );
        }
    }

    /**
     * @param CompanyEvent $event
     */
    public function onCompanyCreatedEvent(CompanyEvent $event)
    {
        $company = $event->getCompany();
        $matches = $this->sanctions->checkCompany($company);
        if (count($matches) > 0) {
            $this->mailer->sendTemplate(
                sprintf('Sanctions Verification Required for %s', $company->getName()),
                'tech@so-sure.com',
                'AppBundle:Email:company/admin_sanctions.html.twig',
                ['company' => $company, 'matches' => json_encode($matches)]
            );
        }
    }
}
