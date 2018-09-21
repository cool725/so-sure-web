<?php

namespace App\Controller\Admin\Policy;

use AppBundle\Document\Policy;
use Doctrine\ODM\MongoDB\DocumentManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

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

        $allowedStatusMap = [
            'active' => Policy::STATUS_ACTIVE,
            'unpaid' => Policy::STATUS_UNPAID,
        ];

        if (!isset($allowedStatusMap[$policyNewStatus])) {
            throw new \RuntimeException("Cannot set policy status to '{$policyNewStatus}'");
        }

        $policy->setStatus($policyNewStatus);
        $this->documentManager->persist($policy);
        $this->documentManager->flush();

        return $this->redirectToRoute('admin_policy', ['id' => $policy->getId()]);
    }
}
