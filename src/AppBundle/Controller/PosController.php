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
        $lead->setSourceDetails(Lead::SOURCE_POS_HELLOZ);
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
                        $this->addFlash('success', sprintf(
                            'Successfully added lead %s',
                            $lead->getName()
                        ));

                        $dm->persist($lead);
                        $dm->flush();
                    } else {
                        $this->addFlash('warning', sprintf(
                            'Lead already exists'
                        ));
                    }
                } else {
                    $this->addFlash('error', sprintf(
                        'Form is invalid'
                    ));
                }
            }
        }

        return [
            'lead_form' => $leadForm->createView(),
        ];
    }
}
