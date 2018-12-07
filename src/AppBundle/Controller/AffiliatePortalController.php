<?php

namespace AppBundle\Controller;

use AppBundle\Form\Type\LeadPortalType;

use AppBundle\Service\IntercomService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Lead;

/**
 * @Route("/pos")
 */
class AffiliatePortalController extends BaseController
{
    /**
     * @Route("/helloz", name="helloz")
     * @Template()
     */
    public function hellozAction(Request $request)
    {
        /** @var DocumentManager $dm */
        $dm = $this->getManager();

        /** @var IntercomService $intercomService */
        $intercomService = $this->get('app.intercom');

        $lead = new Lead();
        $lead->setSource(Lead::LEAD_SOURCE_AFFILIATE);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadPortalType::class, $lead)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                $leadForm->handleRequest($request);

                if ($leadForm->isValid()) {
                    $leadRepo = $dm->getRepository(Lead::class);
                    $existingLead = $leadRepo->findOneBy([
                        'email' => mb_strtolower($lead->getEmail())
                    ]);

                    if (!$existingLead) {
                        $dm->persist($lead);
                        $dm->flush();
                    } else {
                        $lead = $existingLead;
                    }

                    $intercomService->queueLead($lead, IntercomService::QUEUE_LEAD);
                }
            }
        }

        return [
            'lead_form' => $leadForm->createView(),
        ];
    }
}
