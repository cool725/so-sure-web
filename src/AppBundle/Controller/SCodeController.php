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
use AppBundle\Document\SCode;
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

class SCodeController extends BaseController
{
    /**
     * @Route("/scode/{code}", name="scode")
     * @Template
     */
    public function scodeAction(Request $request, $code)
    {
        $geoip = $this->get('app.geoip');
        //$ip = "82.132.221.1";
        $ip = $request->getClientIp();
        $isUK = $geoip->findCountry($ip) == "GB";

        $dm = $this->getManager();
        $repo = $dm->getRepository(SCode::class);
        $phoneRepo = $dm->getRepository(Phone::class);

        $scode = null;
        try {
            if ($scode = $repo->findOneBy(['code' => $code])) {
                // make sure to get policy user in code first rather than in twig in case policy/user was deleted
                if (in_array($scode->getType(), [SCode::TYPE_STANDARD, SCode::TYPE_MULTIPAY])) {
                    if (!$scode->getPolicy() || !$scode->getPolicy()->getUser()) {
                        throw new \Exception('Unknown scode');
                    }
                } elseif (in_array($scode->getType(), [SCode::TYPE_REWARD])) {
                    if (!$scode->getReward() || !$scode->getReward()->getUser()) {
                        throw new \Exception('Unknown scode');
                    }
                }
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        if ($scode && !$isUK) {
            // @codingStandardsIgnoreStart
            $this->addFlash('error-raw', sprintf(
                '<i class="fa fa-warning"></i> Sorry, we currently only offer policies to UK residents. If you are a UK resident, you may continue below.'
            ));
            // @codingStandardsIgnoreEnd
        }

        $session = $this->get('session');
        $session->set('scode', $code);

        if ($scode && $request->getMethod() === "GET") {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_INVITATION_PAGE, [
                'Invitation Method' => 'scode',
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'Attribution Invitation Method' => 'scode',
            ], true);
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

        if ($scode && $this->getUser()) {
            // Let the user just invite the person directly
            return new RedirectResponse($this->generateUrl('user_home'));
        }

        $competitionFeature = $this->get('app.feature')->isEnabled(Feature::FEATURE_INVITE_PAGES_COMPETITION);

        $template = 'AppBundle:SCode:scode.html.twig';
        $heroImageExp = null;

        if ($competitionFeature) {
            $template = 'AppBundle:SCode:scodeCompetition.html.twig';
        }

        $data = [
            'scode'     => $scode,
            'user_code' => $code,
            'lead_form' => $leadForm->createView(),
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'O2',
        ];

        return $this->render($template, $data);
    }

    private function competitorsData()
    {
        $competitor = [
            'PYB' => [
                'name' => 'Protect Your Bubble',
                'days' => '<strong>1 - 5</strong> days <div>depending on stock</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 4
            ],
            'GC' => [
                'name' => 'Gadget<br>Cover',
                'days' => '<strong>5 - 7</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>18 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 2,
            ],
            'SS' => [
                'name' => 'Simplesurance',
                'days' => '<strong>3 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1,
            ],
            'CC' => [
                'name' => 'CloudCover',
                'days' => '<strong>3 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>6 months</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 3,
            ],
            'END' => [
                'name' => 'Endsleigh',
                'days' => '<strong>1 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-check',
                'oldphones' => 'fa-check',
                'phoneage' => '<strong>3 years</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1,
            ],
            'LICI' => [
                'name' => 'Loveit<br>coverIt.co.uk',
                'days' => '<strong>1 - 5</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'fa-times',
                'phoneage' => '<strong>3 years</strong> <div>from purchase</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 2,
            ],
            'O2' => [
                'name' => 'O2',
                'days' => '<strong>1 - 7</strong> <div>working days</div>',
                'cashback' => 'fa-times',
                'cover' => 'fa-times',
                'oldphones' => 'From 02 only',
                'phoneage' => '<strong>29 days</strong> <div>O2 phones only</div>',
                'saveexcess' => 'fa-times',
                'trustpilot' => 1.5,
            ]
        ];

        return $competitor;
    }
}
