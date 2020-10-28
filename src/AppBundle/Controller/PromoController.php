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
use AppBundle\Document\Reward;
use AppBundle\Document\Lead;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Form\Type\LeadEmailType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use AppBundle\Classes\Competitors;

class PromoController extends BaseController
{
    /**
     * @Route("/promo/{code}", name="promo")
     * @Route("/amazon/{code}", name="amazon_promo")
     * @Route("/pizzaexpress/{code}", name="pizzaexpress_promo")
     * @Route("/xiaomi/{code}", name="xiaomi_promo")
     * @Route("/share/{code}", name="share_promo")
     * @Template
     */
    public function promoAction(Request $request, $code)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Scode::class);
        $phoneRepo = $dm->getRepository(Phone::class);

        $scode = null;
        $custom = null;
        $code = mb_strtoupper($code);

        try {
            if ($scode = $repo->findOneBy(['code' => $code, 'active' => true, 'type' => Scode::TYPE_REWARD])) {
                $reward = $scode->getReward();
                if (!$reward || !$reward->getUser() || !$reward->isOpen(new \DateTime())) {
                    throw new \Exception('Unknown promo code');
                }
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        $session = $this->get('session');
        $session->set('scode', $code);

        $template = 'AppBundle:Promo:promo.html.twig';

        // Check route so we can use custom to update template
        if ($request->get('_route') == 'amazon_promo') {
            $custom = 'amazon';
        } elseif ($request->get('_route') == 'pizzaexpress_promo') {
            $custom = 'pizzaexpress';
        } elseif ($request->get('_route') == 'xiaomi_promo') {
            $custom = 'xiaomi';
        } elseif ($request->get('_route') == 'share_promo') {
            $template = 'AppBundle:Promo:influencer.html.twig';
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

        if ($scode && $request->getMethod() === "GET") {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PROMO_PAGE, [
                'Promo code' => $scode,
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'Attribution Invitation Method' => 'reward',
            ], true);
        }

        $competitorData = new Competitors();

        $data = [
            'scode' => $scode,
            'use_code' => $code,
            'custom' => $custom,
            'lead_form' => $leadForm->createView(),
            'competitor' => $competitorData::$competitorComparisonData,
        ];

        return $this->render($template, $data);
    }
}
