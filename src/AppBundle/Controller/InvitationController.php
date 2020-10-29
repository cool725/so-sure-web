<?php

namespace AppBundle\Controller;

use AppBundle\Exception\InvalidEmailException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Lead;
use AppBundle\Document\Feature;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\MailerService;
use AppBundle\Form\Type\LeadEmailType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use AppBundle\Classes\Competitors;

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
            $flashMessage = 'This invitation to join so-sure is being processed';

            if ($invitation->isAccepted()) {
                $flashType = 'success';
                $flashMessage = 'The invitation to join so-sure has been accepted';
            } elseif ($invitation->isRejected()) {
                $flashType = 'error';
                $flashMessage = 'This invitation to join so-sure was declined';
            } elseif ($invitation->isCancelled()) {
                $flashType = 'error';
                $flashMessage = 'This invitation to join so-sure has already been cancelled';
            }

            $this->addFlash(
                $flashType,
                $flashMessage
            );
        } elseif ($this->getUser() !== null) {
            return $this->redirect($this->getParameter('branch_share_url'));
        } elseif ($invitation && $invitation->isCancelled()) {
            $invitation = null;
            return $this->redirectToRoute('homepage');
        }

        $lead = new Lead();
        $lead->setSource(Lead::SOURCE_INVITE_NOT_READY);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadEmailType::class, $lead)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                try {
                    $leadForm->handleRequest($request);

                    if ($leadForm->isValid()) {
                        $leadRepo = $dm->getRepository(Lead::class);
                        $existingLead = $leadRepo->findOneBy(['email' => mb_strtolower($lead->getEmail())]);
                        if (!$existingLead) {
                            $dm->persist($lead);
                            $dm->flush();
                        } else {
                            $lead = $existingLead;
                        }
                        $days = \DateTime::createFromFormat('U', time());
                        $days = $days->add(new \DateInterval(sprintf('P%dD', 14)));
                        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                        $this->get('app.mixpanel')->queuePersonProperties([
                            '$email' => $lead->getEmail()
                        ], true);

                        $this->addFlash('success', sprintf(
                            "Thanks for registering your interest in so-sure! We'll be in touch soon."
                        ));
                    } else {
                        $this->addFlash('error', sprintf(
                            "Sorry, didn't quite catch that email.  Please try again."
                        ));
                    }
                } catch (InvalidEmailException $ex) {
                    $this->get('logger')->info('Failed validation.', ['exception' => $ex]);
                    $this->addFlash('error', sprintf(
                        "Sorry, didn't quite catch that email.  Please try again."
                    ));
                }
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

        $scode = $invitation->getInviter()->getStandardSCode();
        if ($scode) {
            $this->get('session')->set('scode', $scode->getCode());
        }

        $referralFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_REFERRAL);

        $template = 'AppBundle:Invitation:invitation.html.twig';

        if ($referralFeature) {
            $template = 'AppBundle:Invitation:invitationReferral.html.twig';
        }

        $competitorData = new Competitors();

        $data = [
            'invitation' => $invitation,
            'lead_form' => $leadForm->createView(),
            'competitor' => $competitorData::$competitorComparisonData,
        ];

        return $this->render($template, $data);
    }
}
