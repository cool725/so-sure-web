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
use AppBundle\Service\SixpackService;
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
            $flashType = 'warning';
            $flashMessage = 'Hmm, it looks like this invitation to join so-sure has already been processed';

            if ($invitation->isAccepted()) {
                $flashType = 'success';
                $flashMessage = 'This invitation to join so-sure has already been accepted already';
            } elseif ($invitation->isRejected()) {
                $flashType = 'error';
                $flashMessage = 'Hmm, it looks like this invitation to join so-sure has already been declined';
            } elseif ($invitation->isCancelled()) {
                $flashType = 'error';
                $flashMessage = 'Hmm, it looks like this invitation to join so-sure has already been cancelled';
            }

            $this->addFlash(
                $flashType,
                $flashMessage
            );
            return $this->render('AppBundle:Invitation:invitation.html.twig', ['id' => $id]);
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
                'label' => "Not interested!",
                'attr' => ['class' => 'btn-simple-link text-white'],
            ))
            ->getForm();

        if ($request->request->has('decline_form')) {
            $declineForm->handleRequest($request);
            if ($declineForm->isSubmitted() && $declineForm->isValid()) {
                $invitationService = $this->get('app.invitation');
                $invitationService->reject($invitation);
                $declined = true;
                $this->addFlash(
                    'error',
                    'You have declined this invitation.'
                );

                return $this->redirectToRoute('invitation', [
                    'id' => $id,
                ]);
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

        $landingText = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_EMAIL_LANDING_TEXT,
            ['email-landing-text-a', 'email-landing-text-b']
        );

        return array(
            'invitation' => $invitation,
            'form' => $declineForm->createView(),
            'landing_text' => $landingText,
            'declined' => $declined,
        );
    }
}
