<?php

namespace AppBundle\Listener;

use AppBundle\Service\PolicyService;
use Psr\Log\LoggerInterface;
use AppBundle\Event\ConnectionEvent;

class PolicyListener
{
    /** @var PolicyService */
    protected $policyService;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param PolicyService   $policyService
     * @param LoggerInterface $logger
     */
    public function __construct(
        PolicyService $policyService,
        LoggerInterface $logger
    ) {
        $this->policyService = $policyService;
        $this->logger = $logger;
    }

    /**
     * @param ConnectionEvent $event
     */
    public function onConnectionReducedEvent(ConnectionEvent $event)
    {
        $connection = $event->getConnection();
        // There are cases where a connection value might be reduced or eliminated:
        // 1) Policy cancellation
        // 2) Policy is not renewed
        // 3) Policy is renewed, but connection is not renewed and so value is dropped/removed

        // Claims should not affect the connection value itself, but rather impact on the pot value

        // The connection affected should the source policy and the linked policy is the policy
        // that was actually cancelled/not renewed/etc

        $this->policyService->connectionReduced($connection);
    }
}
