<?php

namespace AppBundle\Controller;

use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Form\Type\LeadPosType;
use Doctrine\ODM\MongoDB\DocumentManager;
use PharIo\Manifest\Email;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Lead;

/**
 * @Route("/pos")
 */
class PosController extends BaseController
{
    // TODO: Move to affiliate company flag
    public static $pos = [
        'helloz' => Lead::SOURCE_POS_HELLOZ,
    ];

    /**
     * @Route("/{name}", name="pos_standard")
     * @Template()
     */
    public function standardAction(Request $request, $name)
    {
        if (!in_array($name, array_keys(self::$pos))) {
            return $this->createNotFoundException('Unknown pos vendor');
        }
        /** @var DocumentManager $dm */
        $dm = $this->getManager();

        $leadData = new \AppBundle\Document\Form\Lead();
        $leadData->setSource(Lead::LEAD_SOURCE_AFFILIATE);
        $leadData->setSourceDetails(self::$pos[$name]);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadPosType::class, $leadData)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                $leadForm->handleRequest($request);

                if ($leadForm->isValid()) {
                    $leadRepo = $dm->getRepository(Lead::class);
                    $existingLead = $leadRepo->findOneBy([
                        'emailCanonical' => $leadData->getEmailCanonical()
                    ]);

                    if (!$existingLead && $leadData->getOptin()) {
                        $lead = $leadData->toLead();
                        $emailOptIn = new EmailOptIn();
                        $emailOptIn->setEmail($lead->getEmail());
                        $emailOptIn->addCategory(EmailOptIn::OPTIN_CAT_MARKETING);
                        $emailOptIn->setLocation(EmailOptIn::OPT_LOCATION_POS);
                        $emailOptIn->setIdentityLog($this->getIdentityLogWeb($request));
                        $lead->addOpt($emailOptIn);
                        $dm->persist($lead);
                        $dm->flush();

                        $this->addFlash('success', sprintf(
                            'Thanks! We will be in touch shortly with more information on so-sure.'
                        ));
                    } else {
                        $this->addFlash('warning', sprintf(
                            'Sorry, it looks like you already signed up'
                        ));
                    }

                    return new RedirectResponse($this->generateUrl('pos_standard', ['name' => $name]));
                } else {
                    $this->addFlash('error', sprintf(
                        'Sorry, we were unable to register you. Please review the form errors below.'
                    ));
                }
            }
        }

        return [
            'lead_form' => $leadForm->createView(),
        ];
    }
}
