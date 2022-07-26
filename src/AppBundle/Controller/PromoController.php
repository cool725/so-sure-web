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
     * @Route("/share/{code}", name="share_promo")
     * @Route("/offer/{code}", name="offer_promo")
     * @Route("/student/{code}", name="student_promo")
     * @Route("/benefithub/{code}", name="benefithub_promo")
     * @Route("/lifeworks/{code}", name="lifeworks_promo")
     * @Route("/idealworld/{code}", name="idealworld_promo")
     * @Route("/ipse/{code}", name="ipse_promo")
     * @Route("/canopy/{code}", name="canopy_promo")
     * @Route("/youatwork/{code}", name="youatwork_promo")
     * @Route("/creditspring/{code}", name="creditspring_promo")
     * @Route("/ben/{code}", name="ben_promo")
     * @Route("/suitsme/{code}", name="suitsme_promo")
     * @Route("/insurtech/{code}", name="insurtech_promo")
     * @Template
     */
    public function promoAction(Request $request, $code)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Scode::class);
        $phoneRepo = $dm->getRepository(Phone::class);

        $scode = null;
        $custom = null;
        $amazonVoucher = null;
        $logo = null;
        $logoWidth = null;

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

        // Redirect to homepage if scode not found
        if (!$scode) {
            return $this->redirectToRoute('homepage');
        }

        $session = $this->get('session');
        $session->set('scode', $code);

        $template = 'AppBundle:Promo:promo.html.twig';

        // Check route so we can use custom to update template
        if ($request->get('_route') == 'amazon_promo') {
            $custom = 'amazon';
            $amazonVoucher = 15;
            $template = 'AppBundle:Promo:promoCard.html.twig';
        } elseif ($request->get('_route') == 'share_promo') {
            $template = 'AppBundle:Promo:influencer.html.twig';
        } elseif ($request->get('_route') == 'student_promo') {
            $template = 'AppBundle:Promo:influencerStudent.html.twig';
        } elseif ($request->get('_route') == 'benefithub_promo') {
            $custom = 'benefithub';
            $amazonVoucher = 15;
            $logo = 'so-sure_benefithub-white-logo.png';
            $logoWidth = '200px';
        } elseif ($request->get('_route') == 'lifeworks_promo') {
            $custom = 'lifeworks';
            $amazonVoucher = 15;
            $logo = 'so-sure_lifeworks-logo.svg';
            $logoWidth = '200px';
        } elseif ($request->get('_route') == 'idealworld_promo') {
            $custom = 'idealworld';
            $amazonVoucher = 10;
            $logo = 'so-sure_idealworld-logo.svg';
            $logoWidth = '113px';
        } elseif ($request->get('_route') == 'ipse_promo') {
            $custom = 'ipse';
            $amazonVoucher = 15;
            $logo = 'so-sure_ipse-logo.svg';
            $logoWidth = '113px';
        } elseif ($request->get('_route') == 'canopy_promo') {
            $custom = 'canopy';
            $logo = 'so-sure_canopy_logo.svg';
            $logoWidth = '113px';
        } elseif ($request->get('_route') == 'youatwork_promo') {
            $custom = 'youatwork';
            $logo = 'so-sure_youatwork_white_logo.svg';
            $logoWidth = '160px';
        } elseif ($request->get('_route') == 'creditspring_promo') {
            $custom = 'creditspring';
            $logo = 'so-sure_creditspring_logo.svg';
            $logoWidth = '133px';
        } elseif ($request->get('_route') == 'ben_promo') {
            $custom = 'ben';
            $logo = 'so-sure_ben_logo.svg';
            $logoWidth = '133px';
        } elseif ($request->get('_route') == 'suitsme_promo') {
            $custom = 'suitsme';
            $logo = 'so-sure_suitsme_logo.svg';
            $logoWidth = '133px';
        } elseif ($request->get('_route') == 'insurtech_promo') {
            $custom = 'insurtech';
            $logo = 'so-sure_insurtech_logo.svg';
            $logoWidth = '200px';
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
            'amazon_voucher' => $amazonVoucher,
            'partner_logo' => $logo,
            'partner_logo_width' => $logoWidth,
        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/idealworld", name="idealworld_forward")
     */
    public function forwardIdealWorld()
    {
        return $this->redirectToRoute('homepag', ['code'=>'IDEALW10'], 301);
    }

    /**
     * Phone Insurance > Make - Promo Page
     * @Route("/phone-insurance-promo/{make}/{code}",
     * name="phone_insurance_make_promo", requirements={"make":"[a-zA-Z]+"})
     */
    public function phoneInsuranceMakePromoAction(Request $request, $make, $code)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $repoScode = $dm->getRepository(Scode::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $noindex = true;
        $scode = null;

        $phones = $repo->findBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make),
        ]);

        if (count($phones) != 0) {
            $phone = $phones[0];
        } else {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
            ]);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find make page for: %s',
                $make
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        try {
            if ($scode = $repoScode->findOneBy(['code' => $code, 'active' => true, 'type' => Scode::TYPE_REWARD])) {
                $reward = $scode->getReward();
                if (!$reward || !$reward->getUser() || !$reward->isOpen(new \DateTime())) {
                    throw new \Exception('Unknown promo code');
                }
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        // Redirect to homepage if scode not found
        if (!$scode) {
            return $this->redirectToRoute('homepage');
        }

        $session = $this->get('session');
        $session->set('scode', $code);

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
            'phone' => $phone,
            'competitor' => $competitorData::$competitorComparisonData,
            'is_noindex' => $noindex,
            'scode' => $scode,
            'use_code' => $code,
            'lead_form' => $leadForm->createView(),
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMakePromo.html.twig', $data);
    }

    /**
     * Phone Insurance > Make, Model - Promo Page
     * @Route("/phone-insurance-promo/{make}/{model}/{code}",
     * name="phone_insurance_make_model_promo", requirements={"make":"[a-zA-Z]+"})
     */
    public function phoneInsuranceMakeModelPromoAction(Request $request, $make, $model, $code)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $repoScode = $dm->getRepository(Scode::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);
        $phone = null;
        $noindex = true;
        $scode = null;

        $phones = $repo->findBy([
            'makeCanonical' => mb_strtolower($make),
            'modelCanonical' => mb_strtolower($decodedModel),
        ]);

        if (count($phones) != 0 && mb_stripos($model, ' ') === false) {
            $phone = $phones[0];
        } else {
            $phone = $repo->findOneBy([
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModelHyph),
            ]);
        }

        if (preg_match("/^[A-Z]/", $make) || preg_match("/^[A-Z]/", $model)) {
            return $this->redirectToRoute('phone_insurance_make_model_promo', [
                'make' => mb_strtolower($make),
                'model' => mb_strtolower($model),
            ], 301);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for make/model page - make: %s model: %s',
                $make,
                $model
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        try {
            if ($scode = $repoScode->findOneBy(['code' => $code, 'active' => true, 'type' => Scode::TYPE_REWARD])) {
                $reward = $scode->getReward();
                if (!$reward || !$reward->getUser() || !$reward->isOpen(new \DateTime())) {
                    throw new \Exception('Unknown promo code');
                }
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        // Redirect to homepage if scode not found
        if (!$scode) {
            return $this->redirectToRoute('homepage');
        }

        $session = $this->get('session');
        $session->set('scode', $code);

        if ($scode && $request->getMethod() === "GET") {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PROMO_PAGE, [
                'Promo code' => $scode,
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'Attribution Invitation Method' => 'reward',
            ], true);
        }

        $competitorData = new Competitors();

        // Get the price service
        $priceService = $this->get('app.price');

        // Check if pricing or enable the upcoming feature
        if (!$phone->getCurrentYearlyPhonePrice()) {
            $upcoming = true;
            $fromPrice = '9.49';
        } else {
            $fromPrice = $phone->getCurrentYearlyPhonePrice()->getMonthlyPremiumPrice();
        }

        // Model template control
        // Hyphenate Model for images/template
        $modelHyph = str_replace('+', '-', $model);
        // List all available hero images otherwise switch to genric
        $availableImages = [
            'iphone-x',
            'iphone-xr',
            'iphone-xs',
            'iphone-7',
            'iphone-8',
            'galaxy-s8',
            'galaxy-s9',
            'galaxy-note-9',
            'pixel',
            'pixel-3-xl',
            'iphone-13',
            'iphone-13-min',
            'iphone-13-pro',
            'iphone-13-pro-max'
        ];

        $data = [
            'phone' => $phone,
            'competitor' => $competitorData::$competitorComparisonData,
            'is_noindex' => $noindex,
            'scode' => $scode,
            'use_code' => $code,
            'phone_price' => $fromPrice,
            'img_url' => $modelHyph,
            'available_images' => $availableImages,
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMakeModelPromo.html.twig', $data);
    }
}
