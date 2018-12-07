<?php

namespace AppBundle\Controller;

use AppBundle\Form\Type\LeadPosType;
use Doctrine\ODM\MongoDB\DocumentManager;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Lead;

/**
 * @Route("/pos")
 */
class PosController extends BaseController
{
    /**
     * @Route("/helloz", name="helloz")
     * @Template()
     */
    public function hellozAction(Request $request)
    {
        /** @var DocumentManager $dm */
        $dm = $this->getManager();

        $lead = new Lead();
        $lead->setSource(Lead::LEAD_SOURCE_AFFILIATE);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadPosType::class, $lead)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                $leadForm->handleRequest($request);

                if ($leadForm->isValid()) {
                    $leadRepo = $dm->getRepository(Lead::class);
                    $existingLead = $leadRepo->findOneBy([
                        'emailCanonical' => $lead->getEmailCanonical()
                    ]);

                    if (!$existingLead) {
                        $dm->persist($lead);
                        $dm->flush();
                    }
                }
            }
        }

        return [
            'lead_form' => $leadForm->createView(),
        ];
    }
}
