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
        $dm = $this->getManager();
        $repo = $dm->getRepository(Invitation::class);
        $invitation = $repo->find($id);
        $phoneRepo = $dm->getRepository(Phone::class);
        $deviceAtlas = $this->get('app.deviceatlas');

        if ($invitation && $invitation->isSingleUse() && $invitation->isInviteeProcessed()) {
            return $this->render('AppBundle:Invitation:processed.html.twig', [
                'invitation' => $invitation,
            ]);
        } elseif ($this->getUser() !== null) {
            // If user is on their mobile, use branch to redirect to app
            if ($deviceAtlas->isMobile($request)) {
                return $this->redirect($this->getParameter('branch_share_url'));
            }
            // otherwise, the standard invitation is ok for now
            // TODO: Once invitations can be accepted on the web,
            // we should use branch for everything
        } elseif ($invitation && $invitation->isCancelled()) {
            // If invitation was cancelled, don't mention who invited them (clear the invitation),
            // but still try to convert user
            $invitation = null;
        }

        $declineForm = $this->get('form.factory')
            ->createNamedBuilder('decline_form')
            ->add('decline', SubmitType::class, array(
                'label' => "No thanks!",
                'attr' => ['class' => 'btn btn-danger'],
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
        
        return array(
            'invitation' => $invitation,
            'form' => $declineForm->createView(),
        );
    }
}
