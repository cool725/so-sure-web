<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Service\MixpanelService;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class InvitationController extends BaseController
{
    /**
     * @Route("/invitation/{id}", name="invitation")
     * @Template
     */
    public function invitationAction(Request $request, $id)
    {
        $geoip = $this->get('app.geoip');
        //$ip = "82.132.221.1";
        $ip = $request->getClientIp();
        $isUK = $geoip->findCountry($ip) == "GB";

        $dm = $this->getManager();
        $repo = $dm->getRepository(Invitation::class);
        $invitation = $repo->find($id);
        $phoneRepo = $dm->getRepository(Phone::class);

        if ($invitation && $invitation->isSingleUse() && $invitation->isInviteeProcessed()) {
            return $this->render('AppBundle:Invitation:processed.html.twig', [
                'invitation' => $invitation,
            ]);
        } elseif ($this->getUser() !== null) {
            return $this->redirect($this->getParameter('branch_share_url'));
        } elseif ($invitation && $invitation->isCancelled()) {
            // If invitation was cancelled, don't mention who invited them (clear the invitation),
            // but still try to convert user
            $invitation = null;
        }

        $declineForm = $this->get('form.factory')
            ->createNamedBuilder('decline_form')
            ->add('decline', SubmitType::class, array(
                'label' => "No thanks!",
                'attr' => ['class' => 'btn btn-danger btn-rounded'],
            ))
            ->getForm();

        if ($request->request->has('decline_form')) {
            $declineForm->handleRequest($request);
            if ($declineForm->isSubmitted() && $declineForm->isValid()) {
                $invitationService = $this->get('app.invitation');
                $invitationService->reject($invitation);
                $this->addFlash(
                    'error',
                    'You have declined this invitation.'
                );

                return $this->redirectToRoute('invitation', ['id' => $id]);
            }
        }

        if ($invitation && $request->getMethod() === "GET") {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_INVITATION_PAGE, [
                'Invitation Method' => $invitation->getChannel(),
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'Attribution Invitation Method' => $invitation->getChannel(),
            ], true);
        }

        if ($invitation && !$isUK) {
            // @codingStandardsIgnoreStart
            $this->addFlash('error', sprintf(
                '<i class="fa fa-warning"></i> Sorry, we currently only offer policies to UK residents. If you are a UK resident, you may continue below.'
            ));
            // @codingStandardsIgnoreEnd
        }

        return array(
            'invitation' => $invitation,
            'form' => $declineForm->createView(),
        );
    }
}
