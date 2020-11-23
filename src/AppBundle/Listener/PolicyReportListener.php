<?php

namespace AppBundle\Listener;

use AppBundle\Document\Charge;
use AppBundle\Event\UserEvent;
use AppBundle\Service\SmsService;
use AppBundle\Service\BranchService;
use AppBundle\Service\PolicyService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\PolicyEvent;

/**
 * Listens for changes that affect the policy reports and recaches the relevant lines.
 */
class PolicyReportListener
{
    /** @var PolicyService $policyService */
    protected $policyService;

    /** @var LoggerInterface $logger */
    protected $logger;

    /**
     * Creates the listener and provides for it's needs.
     * @param PolicyService   $policyService is used to do stuff to policies.
     * @param LoggerInterface $logger        logs.
     */
    public function __construct(
        PolicyService $policyService,
        LoggerInterface $logger
    ) {
        $this->policyService = $policyService;
        $this->logger = $logger;
    }

    public function userRefresh(UserEvent $event)
    {
        foreach ($user->getPolicies() as $policy) {
            $this->policyService->generateReportLines($policy);
        }
    }

    public function policyRefresh(PolicyEvent $event)
    {
        $this->policyService->generateReportLines($event->getPolicy());
    }
}
