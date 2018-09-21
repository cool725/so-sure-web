<?php

namespace App\Controller\Admin\Policy;

use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\DocumentNotFoundException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use UnexpectedValueException;

/**
 * @Route("/admin/policy/new-status/{id}/{policyNewStatus}", name="admin_policy_set_status")
 * @Security("has_role('ROLE_ADMIN')")
 */
class SetPolicyStatusByDescription extends AbstractController
{
    /** @var DocumentManager */
    private $documentManager;

    public function __construct(DocumentManager $documentManager)
    {
        $this->documentManager = $documentManager;
    }

    public function __invoke($id, $policyNewStatus): Response
    {
        $policyRepo = $this->documentManager->getRepository(Policy::class);
        /** @var Policy $policy */
        $policy = $policyRepo->find($id);
        $this->assertTransitionAllowed($policy, $policyNewStatus);

        $policy->setStatus($policyNewStatus);
        $this->documentManager->flush();

        return $this->redirectToRoute('admin_policy', ['id' => $policy->getId()]);
    }

    private function assertTransitionAllowed($policy, $requestedStatus)
    {
        if (!$policy) {
            throw new DocumentNotFoundException("Policy not found");
        }

        if (!$this->transitionValid($policy, $requestedStatus)) {
            throw new UnexpectedValueException("Cannot set policy status from the current value to '{$requestedStatus}'");
        }
    }

    private function transitionValid(Policy $policy, string $requestedStatus): bool
    {
        $allowedTransitionsMap = [
            Policy::STATUS_ACTIVE => Policy::STATUS_UNPAID,
            Policy::STATUS_UNPAID => Policy::STATUS_ACTIVE,
        ];

        $currentStatus = $policy->getStatus();

        return $allowedTransitionsMap[$currentStatus] === $requestedStatus;
    }
}
