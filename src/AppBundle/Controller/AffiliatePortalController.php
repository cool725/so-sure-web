<?php

namespace AppBundle\Controller;

use AppBundle\Form\Type\LeadPortalType;

use AppBundle\Document\LeadPortal;
use AppBundle\Service\IntercomService;
use AppBundle\Service\MailerService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use AppBundle\Document\Lead;

class AffiliatePortalController extends BaseController
{
    /**
     * @Route("/helloz", name="helloz")
     * @Template()
     */
    public function hellozAction(Request $request)
    {
        $dm = $this->getManager();

        $lead = new Lead();
        $lead->setSource(Lead::LEAD_SOURCE_AFFILIATE);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadPortalType::class, $lead)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                $leadForm->handleRequest($request);

                if ($leadForm->isValid()) {
                    $dm->flush;
                }
            }
        }

        return [
            'lead_form' => $leadForm->createView(),
        ];
    }
}
