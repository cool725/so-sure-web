<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

use AppBundle\Document\Lead;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;

class ExitPopupController extends BaseController
{
    /**
     * @Route("/exit-popup-lead", name="exit_popup_lead")
     */
    public function exitPopupLead(Request $request)
    {
        $dm = $this->getManager();
        if ('POST' === $request->getMethod()) {
            $email = $request->get('email');
            if (!$email) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Error missing email'
                ]);
            }
            if ($email) {
                try {
                    $lead = new Lead();
                    $lead->setEmail($email);
                    $lead->setSource(Lead::SOURCE_EXIT_POPUP);
                    $leadRepo = $dm->getRepository(Lead::class);
                    $existingLead = $leadRepo->findOneBy(['email' => mb_strtolower($lead->getEmail())]);
                    if (!$existingLead) {
                        $dm->persist($lead);
                        $dm->flush();
                    } else {
                        $lead = $existingLead;
                    }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        '$email' => $lead->getEmail()
                    ], true);
                    // A/B On popup text
                    $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_EXIT_POPUP_MULTI);
                    return new JsonResponse([
                        'success' => true,
                        'data' => sprintf('Lead created for %s', $lead->getEmail())
                    ]);
                } catch (\Exception $e) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $e->getMessage()
                    ]);
                }
            }
        }
    }
}
