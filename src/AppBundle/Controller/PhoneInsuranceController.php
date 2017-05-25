<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\LeadEmailType;
use AppBundle\Form\Type\RegisterUserType;
use AppBundle\Form\Type\PhoneMakeType;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\SmsAppLinkType;

use AppBundle\Document\Form\Register;
use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\User;
use AppBundle\Document\Lead;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\PolicyTerms;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use AppBundle\Service\IntercomService;

class PhoneInsuranceController extends BaseController
{
    use PhoneTrait;

    /**
     * @Route("/phone-insurance/water-damage", name="phone_insurance_water_damage",
     *          options={"sitemap"={"priority":"0.5","changefreq":"monthly"}})
     * @Template()
     */
    public function waterDamageAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/theft", name="phone_insurance_theft",
     *          options={"sitemap"={"priority":"0.5","changefreq":"monthly"}})
     * @Template()
     */
    public function theftAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/loss", name="phone_insurance_loss",
     *          options={"sitemap"={"priority":"0.5","changefreq":"monthly"}})
     * @Template()
     */
    public function lossAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/cracked-screen", name="phone_insurance_cracked_screen",
     *          options={"sitemap"={"priority":"0.5","changefreq":"monthly"}})
     * @Template()
     */
    public function crackedScreenAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/broken-phone", name="phone_insurance_broken_phone",
     *          options={"sitemap"={"priority":"0.5","changefreq":"monthly"}})
     * @Template()
     */
    public function brokenPhoneAction()
    {
        return array();
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/phone-insurance/{id}", name="quote_phone", requirements={"id":"[0-9a-f]{24,24}"})
     * @Route("/phone-insurance/{make}+{model}+{memory}GB", name="quote_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     * @Route("/phone-insurance/{make}+{model}", name="quote_make_model",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/insure/{make}+{model}+{memory}GB", name="insure_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     * @Route("/insure/{make}+{model}", name="insure_make_model",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     */
    public function quoteAction(Request $request, $id = null, $make = null, $model = null, $memory = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        if ($id) {
            $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_HOMEPAGE_AA);

            $phone = $repo->find($id);
            if ($phone->getMemory()) {
                return $this->redirectToRoute('quote_make_model_memory', [
                    'make' => $phone->getMake(),
                    'model' => $phone->getEncodedModel(),
                    'memory' => $phone->getMemory(),
                ], 301);
            } else {
                return $this->redirectToRoute('quote_make_model', [
                    'make' => $phone->getMake(),
                    'model' => $phone->getEncodedModel(),
                ], 301);
            }
        } elseif ($memory) {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => $make,
                'model' => $decodedModel,
                'memory' => (int) $memory
            ]);
            // check for historical urls
            if (!$phone || stripos($model, ' ') !== false) {
                $phone = $repo->findOneBy([
                    'active' => true,
                    'make' => $make,
                    'model' => $model,
                    'memory' => (int) $memory
                ]);
                if ($phone) {
                    return $this->redirectToRoute('quote_make_model_memory', [
                        'make' => $phone->getMake(),
                        'model' => $phone->getEncodedModel(),
                        'memory' => $phone->getMemory(),
                    ], 301);
                }
            }
        } else {
            $phones = $repo->findBy(
                ['active' => true, 'make' => $make, 'model' => $decodedModel],
                ['memory' => 'asc'],
                1
            );
            if (count($phones) != 0 && stripos($model, ' ') === false) {
                $phone = $phones[0];
            } else {
                // check for historical urls
                $phone = $repo->findOneBy(['active' => true, 'make' => $make, 'model' => $model]);
                if ($phone) {
                    return $this->redirectToRoute('quote_make_model', [
                        'make' => $phone->getMake(),
                        'model' => $phone->getEncodedModel()
                    ], 301);
                }
            }
        }
        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for id: %s make: %s model: %s mem: %s',
                $id,
                $make,
                $model,
                $memory
            ));

            return new RedirectResponse($this->generateUrl('homepage'));
        }

        $session = $request->getSession();
        $session->set('quote', $phone->getId());
        if ($phone->getMemory()) {
            $session->set('quote_url', $this->generateUrl('quote_make_model_memory', [
                'make' => $phone->getMake(),
                'model' => $phone->getEncodedModel(),
                'memory' => $phone->getMemory(),
            ], UrlGeneratorInterface::ABSOLUTE_URL));
        } else {
            $session->set('quote_url', $this->generateUrl('quote_make_model', [
                'make' => $phone->getMake(),
                'model' => $phone->getEncodedModel(),
            ], UrlGeneratorInterface::ABSOLUTE_URL));
        }

        if (in_array($request->get('_route'), ['insure_make_model_memory', 'insure_make_model'])) {
            $exp = $this->get('app.sixpack')->participate(
                SixpackService::EXPERIMENT_LANDING_HOME,
                ['landing', 'home'],
                true
            );
            if ($exp == 'home') {
                return new RedirectResponse($this->generateUrl('homepage'));
            }
        }

        $user = new User();

        $form = $this->get('form.factory')
            ->createNamedBuilder('launch', LaunchType::class, $user)
            ->getForm();

        $lead = new Lead();
        $lead->setSource(Lead::SOURCE_SAVE_QUOTE);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadEmailType::class, $lead)
            ->getForm();
        $buyForm = $this->get('form.factory')
            ->createNamedBuilder('buy_form')
            ->add('buy_tablet', SubmitType::class)
            ->add('slider_used', HiddenType::class)
            ->add('claim_used', HiddenType::class)
            ->getForm();
        $buyBannerForm = $this->get('form.factory')
            ->createNamedBuilder('buy_form_banner')
            ->add('buy', SubmitType::class)
            ->add('slider_used', HiddenType::class)
            ->add('claim_used', HiddenType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('launch')) {
                $form->handleRequest($request);
                if ($form->isValid()) {
                    $launchUser = $this->get('app.user.launch');
                    $existingUser = $launchUser->addUser($user)['user'];
                }

                if ($existingUser) {
                    return $this->redirectToRoute('launch_share', ['id' => $existingUser->getId()]);
                }
            } elseif ($request->request->has('lead_form')) {
                $leadForm->handleRequest($request);
                if ($leadForm->isValid()) {
                    $leadRepo = $dm->getRepository(Lead::class);
                    $existingLead = $leadRepo->findOneBy(['email' => strtolower($lead->getEmail())]);
                    if (!$existingLead) {
                        $dm->persist($lead);
                        $dm->flush();
                    } else {
                        $lead = $existingLead;
                    }
                    $sevenDays = new \DateTime();
                    $sevenDays = $sevenDays->add(new \DateInterval('P7D'));
                    $mailer = $this->get('app.mailer');
                    $mailer->sendTemplate(
                        sprintf('Your saved so-sure quote for %s', $phone),
                        $lead->getEmail(),
                        'AppBundle:Email:quote/priceGuarentee.html.twig',
                        ['phone' => $phone, 'sevenDays' => $sevenDays, 'quoteUrl' => $session->get('quote_url')],
                        'AppBundle:Email:quote/priceGuarentee.txt.twig',
                        ['phone' => $phone, 'sevenDays' => $sevenDays, 'quoteUrl' => $session->get('quote_url')]
                    );
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        '$email' => $lead->getEmail()
                    ], true);
                    $this->get('app.intercom')->queueLead($lead, IntercomService::QUEUE_EVENT_SAVE_QUOTE, [
                        'quoteUrl' => $session->get('quote_url'),
                        'phone' => $phone->__toString(),
                    ]);

                    $this->addFlash('success', sprintf(
                        "Thanks! Your quote is guaranteed now and we'll send you an email confirmation."
                    ));
                } else {
                    $this->addFlash('error', sprintf(
                        "Sorry, didn't quite catch that email.  Please try again."
                    ));
                }
            } elseif ($request->request->has('buy_form')) {
                $buyForm->handleRequest($request);
                if ($buyForm->isValid()) {
                    $properties = [];
                    if ($buyForm->get('buy_tablet')->isClicked()) {
                        $properties['Location'] = 'main';
                    }

                    if ($buyForm->getData()['slider_used']) {
                        $properties['Played with Slider'] = true;
                    }
                    if ($buyForm->getData()['claim_used']) {
                        $properties['Played with Claims'] = true;
                    }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        return $this->redirectToRoute('purchase_step_policy');
                    } else {
                        return $this->redirectToRoute('purchase');
                    }
                }
            } elseif ($request->request->has('buy_form_banner')) {
                $buyBannerForm->handleRequest($request);
                if ($buyBannerForm->isValid()) {
                    $properties = [];
                    $properties['Location'] = 'banner';
                    if ($buyBannerForm->getData()['slider_used']) {
                        $properties['Played with Slider'] = true;
                    }
                    if ($buyForm->getData()['claim_used']) {
                        $properties['Played with Claims'] = true;
                    }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        return $this->redirectToRoute('purchase_step_policy');
                    } else {
                        return $this->redirectToRoute('purchase');
                    }
                }
            }
        }

        // if no price, will be sample policy of £100 annually
        $maxPot = $phone->getCurrentPhonePrice() ? $phone->getCurrentPhonePrice()->getMaxPot() : 80;
        $maxConnections = $phone->getCurrentPhonePrice() ? $phone->getCurrentPhonePrice()->getMaxConnections() : 8;
        $annualPremium = $phone->getCurrentPhonePrice() ? $phone->getCurrentPhonePrice()->getYearlyPremiumPrice() : 100;
        $maxComparision = $phone->getMaxComparision() ? $phone->getMaxComparision() : 80;

        // only need to run this once - if its a post, then ignore
        if ('GET' === $request->getMethod() && $phone->getCurrentPhonePrice()) {
            $event = MixpanelService::EVENT_QUOTE_PAGE;
            if (in_array($request->get('_route'), ['insure_make_model_memory', 'insure_make_model'])) {
                $event = MixpanelService::EVENT_LANDING_PAGE;
            }
            $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                'Device Selected' => $phone->__toString(),
                'Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'First Device Selected' => $phone->__toString(),
                'First Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            ], true);
        }

        $data = array(
            'phone' => $phone,
            'phone_price' => $phone->getCurrentPhonePrice(),
            'policy_key' => $this->getParameter('policy_key'),
            'connection_value' => PhonePolicy::STANDARD_VALUE,
            'annual_premium' => $annualPremium,
            'max_connections' => $maxConnections,
            'max_pot' => $maxPot,
            'form' => $form->createView(),
            'lead_form' => $leadForm->createView(),
            'buy_form' => $buyForm->createView(),
            'buy_form_banner' => $buyBannerForm->createView(),
            'phones' => $repo->findBy(
                ['active' => true, 'make' => $make, 'model' => $decodedModel],
                ['memory' => 'asc']
            ),
            'comparision' => $phone->getComparisions(),
            'comparision_max' => $maxComparision,
            'coming_soon' => $phone->getCurrentPhonePrice() ? false : true,
        );

        if (in_array($request->get('_route'), ['insure_make_model_memory', 'insure_make_model'])) {
            // return $this->render('AppBundle:PhoneInsurance:insuranceLanding.html.twig', $data);
            return $this->render('AppBundle:PhoneInsurance:quote.html.twig', $data);
        } else {
            return $this->render('AppBundle:PhoneInsurance:quote.html.twig', $data);
        }

        // //if ($phone->getCurrentPhonePrice()) {
        //     return $this->render('AppBundle:PhoneInsurance:quote.html.twig', $data);
        // //} else {
        // //    return $this->render('AppBundle:Default:quotePhoneUpcoming.html.twig', $data);
        // //}
    }
}
