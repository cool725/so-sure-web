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
     */
    public function hellozAction(Request $request)
    {
        $lead = new LeadPortal();
        $lead->setSource(LeadPortal::SOURCE_HELLO_Z);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadPortalType::class, $lead)
            ->getForm();

        $data = [
            'lead_form' => $leadForm->createView(),
        ];

        return $this->render('AppBundle:AffiliatePortal:helloz.html.twig', $data);
    }
}
