<?php

namespace AppBundle\Controller;

use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\InvalidFullNameException;
use AppBundle\Exception\ValidationException;
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
use AppBundle\Document\Opt\EmailOptOut;
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
     * @Route("/phone-insurance/{make}", name="quote_make", requirements={"make":"[a-zA-Z]+"})
     * @Route("/insure/{make}", name="insure_make", requirements={"make":"[a-zA-Z]+"})
     * @Template
     */
    public function makeInsurance(Request $request, $make)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(
            ['make' => $make, 'active' => true, 'highlight' => true],
            ['releaseDate' => 'desc', 'initialPrice' => 'desc']
        );
        if (count($phones) == 0) {
            throw $this->createNotFoundException('No phones with make are available');
        }

        $phonesMem = [];
        foreach ($phones as $phone) {
            /** @var Phone $phone */
            if (!isset($phonesMem[$phone->getName()])) {
                $phonesMem[$phone->getName()] = [
                    'make' => $phone->getMake(),
                    'model' => $phone->getModel(),
                    'currentPhonePrice' => $phone->getCurrentPhonePrice(),
                    'imageUrlWithFallback' => $phone->getImageUrlWithFallback(),
                ];
            }
            $phonesMem[$phone->getName()]['mem'][$phone->getMemory()] = $this->generateUrl(
                'quote_make_model_memory',
                [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getModelCanonical(),
                    'memory' => $phone->getMemory()
                ]
            );
            ksort($phonesMem[$phone->getName()]['mem']);
        }

        $template = 'AppBundle:PhoneInsurance:makeInsurance.html.twig';

        if (in_array($request->get('_route'), ['insure_make'])) {
             $template = 'AppBundle:PhoneInsurance:makeInsuranceBottom.html.twig';
        }

        $event = MixpanelService::EVENT_MANUFACTURER_PAGE;

        if (in_array($request->get('_route'), ['insure_make'])) {
             $event = MixpanelService::EVENT_CPC_MANUFACTURER_PAGE;
        }

        $this->get('app.mixpanel')->queueTrackWithUtm($event, [
            'Manufacturer' => $make,
        ]);

        $data = ['phones' => $phonesMem, 'make' => $make];

        return $this->render($template, $data);
    }

    /**
     * @Route("/purchase-phone/{make}+{model}+{memory}GB", name="purchase_phone_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function purchasePhoneAction(Request $request, $make = null, $model = null, $memory = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $phone = $repo->findOneBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make),
            'modelCanonical' => mb_strtolower($decodedModel),
            'memory' => (int) $memory
        ]);
        if (!$phone) {
            throw $this->createNotFoundException('Unable to locate phone');
        }

        $quoteUrl = $this->setPhoneSession($request, $phone);
        if (in_array($request->get('_route'), ['purchase_phone_make_model_memory'])) {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, [
                'Location' => 'offsite'
            ]);

            // Multipolicy should skip user details
            if ($this->getUser() && $this->getUser()->hasPolicy()) {
                // don't check for partial partial as quote phone may be different from partial policy phone
                return $this->redirectToRoute('purchase_step_policy');
            } else {
                return $this->redirectToRoute('purchase');
            }
        }
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
     * @Route("/insurance-phone/{make}+{model}+{memory}GB", name="test_insurance_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     * @Route("/insurance/{make}+{model}+{memory}GB", name="insurance_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function quoteAction(Request $request, $id = null, $make = null, $model = null, $memory = null)
    {
        if (in_array($request->get('_route'), ['insure_make_model_memory', 'insure_make_model'])) {
            $exp = $this->sixpack(
                $request,
                SixpackService::EXPERIMENT_CPC_QUOTE_HOMEPAGE,
                ['homepage', 'quote']
            );
            if ($exp == 'homepage') {
                return new RedirectResponse($this->generateUrl('homepage'));
            } elseif ($memory) {
                return new RedirectResponse($this->generateUrl('quote_make_model_memory', [
                    'make' => $make,
                    'model' => $model,
                    'memory' => $memory,
                ]));
            } else {
                return new RedirectResponse($this->generateUrl('quote_make_model', [
                    'make' => $make,
                    'model' => $model,
                ]));
            }
        }

        if (in_array($request->get('_route'), ['test_insurance_make_model_memory'])) {
            $adLanding = $this->sixpack(
                $request,
                SixpackService::EXPERIMENT_AD_LANDING,
                ['ad-homepage', 'ad-landing']
            );
            if ($adLanding == 'ad-landing') {
                return $this->redirectToRoute('insurance_make_model_memory', [
                    'make' => $make,
                    'model' => $model,
                    'memory' => $memory,
                ]);
            } else {
                return $this->redirectToRoute('homepage');
            }
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        if ($id) {
            /** @var Phone $phone */
            $phone = $repo->find($id);
            if ($phone->getMemory()) {
                return $this->redirectToRoute('quote_make_model_memory', [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getEncodedModelCanonical(),
                    'memory' => $phone->getMemory(),
                ], 301);
            } else {
                return $this->redirectToRoute('quote_make_model', [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getEncodedModelCanonical(),
                ], 301);
            }
        } elseif ($memory) {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModel),
                'memory' => (int) $memory
            ]);
            // check for historical urls
            if (!$phone || mb_stripos($model, ' ') !== false) {
                $phone = $repo->findOneBy([
                    'active' => true,
                    'makeCanonical' => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($model),
                    'memory' => (int) $memory
                ]);
                if ($phone) {
                    return $this->redirectToRoute('quote_make_model_memory', [
                        'make' => $phone->getMakeCanonical(),
                        'model' => $phone->getEncodedModelCanonical(),
                        'memory' => $phone->getMemory(),
                    ], 301);
                }
            }
        } else {
            $phones = $repo->findBy(
                [
                    'active' => true,
                    'makeCanonical' => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($decodedModel)
                ],
                ['memory' => 'asc'],
                1
            );
            if (count($phones) != 0 && mb_stripos($model, ' ') === false) {
                $phone = $phones[0];
            } else {
                // check for historical urls
                $phone = $repo->findOneBy([
                    'active' => true,
                    'makeCanonical' => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($model)
                ]);
                if ($phone) {
                    return $this->redirectToRoute('quote_make_model', [
                        'make' => $phone->getMakeCanonical(),
                        'model' => $phone->getEncodedModelCanonical()
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
        } elseif (!$phone->isSameMakeModelCanonical($make, $model)) {
            return $this->redirectToRoute('quote_make_model_memory', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical(),
                'memory' => $phone->getMemory(),
            ], 301);
        }

        $quoteUrl = $this->setPhoneSession($request, $phone);

        $user = new User();

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
                        $days = new \DateTime();
                        $days = $days->add(new \DateInterval(sprintf('P%dD', 7)));
                        $mailer = $this->get('app.mailer');
                        $mailer->sendTemplate(
                            sprintf('Your saved so-sure quote for %s', $phone),
                            $lead->getEmail(),
                            'AppBundle:Email:quote/priceGuarentee.html.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl],
                            'AppBundle:Email:quote/priceGuarentee.txt.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl]
                        );
                        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                        $this->get('app.mixpanel')->queuePersonProperties([
                            '$email' => $lead->getEmail()
                        ], true);

                        $this->addFlash('success', sprintf(
                            "Thanks! Your quote is guaranteed now and we'll send you an email confirmation."
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
                        // don't check for partial partial as quote phone may be different from partial policy phone
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
                        // don't check for partial partial as quote phone may be different from partial policy phone
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
        $expIntercom = null;

        // only need to run this once - if its a post, then ignore
        if ('GET' === $request->getMethod() && $phone->getCurrentPhonePrice()) {
            $event = MixpanelService::EVENT_QUOTE_PAGE;
            if (in_array($request->get('_route'), ['insure_make_model_memory', 'insure_make_model'])) {
                $event = MixpanelService::EVENT_CPC_QUOTE_PAGE;
            }
            $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                'Device Selected' => $phone->__toString(),
                'Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'First Device Selected' => $phone->__toString(),
                'First Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            ], true);

            // Deprecated - Landing Page event - Keep for awhile for a transition period
            // TODO: Remove
            if ($event == MixpanelService::EVENT_CPC_QUOTE_PAGE) {
                $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                    'Device Selected' => $phone->__toString(),
                    'Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
                ]);
            }

            $this->get('app.sixpack')->convert(
                SixpackService::EXPERIMENT_HOMEPAGE_AA_V2
            );
        }

        $replacement = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_PHONE_REPLACEMENT_MATCHING_ADVERT,
            ['default', 'next-working-day', 'seventytwo-hours'],
            SixpackService::LOG_MIXPANEL_CONVERSION,
            null,
            "0.00000001"
        );

        // $moneyBackGuarantee = $this->sixpack(
        //     $request,
        //     SixpackService::EXPERIMENT_MONEY_BACK_GUARANTEE,
        //     ['no-money-back-guarantee', 'money-back-guarantee']
        // );

        // $expContent = $this->getSessionSixpackTest(
        //     $request,
        //     SixpackService::EXPERIMENT_AB_NEW_CONTENT,
        //     ['old-content-no-nav', 'new-content-with-nav']
        // );

        $data = array(
            'phone'            => $phone,
            'phone_price'      => $phone->getCurrentPhonePrice(),
            'policy_key'       => $this->getParameter('policy_key'),
            'connection_value' => PhonePolicy::STANDARD_VALUE,
            'annual_premium'   => $annualPremium,
            'max_connections'  => $maxConnections,
            'max_pot'          => $maxPot,
            'lead_form'        => $leadForm->createView(),
            'buy_form'         => $buyForm->createView(),
            'buy_form_banner'  => $buyBannerForm->createView(),
            'phones'           => $repo->findBy(
                [
                    'active'         => true,
                    'makeCanonical'  => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($decodedModel)
                ],
                ['memory' => 'asc']
            ),
            'comparision'     => $phone->getComparisions(),
            'comparision_max' => $maxComparision,
            'coming_soon'     => $phone->getCurrentPhonePrice() ? false : true,
            'slider_test'     => 'slide-me',
            // 'moneyBackGuarantee' => $moneyBackGuarantee,
            'replacement'     => $replacement,
            // 'ab_content'  => $expContent,
        );

        // Adwords landingpage test
        if (in_array($request->get('_route'), ['insurance_make_model_memory'])) {
            return $this->render('AppBundle:PhoneInsurance:adlanding.html.twig', $data);
        } elseif ($expContent == 'new-content-with-nav') {
            // If A/B content test
            return $this->render('AppBundle:PhoneInsurance:quoteContent.html.twig', $data);
        } else {
            // Default
            return $this->render('AppBundle:PhoneInsurance:quote.html.twig', $data);
        }
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
     *
     * @Route("/phone-insurance/{id}/learn-more", name="learn_more_phone", requirements={"id":"[0-9a-f]{24,24}"})
     * @Route("/phone-insurance/{make}+{model}+{memory}GB/learn-more", name="learn_more_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     * @Route("/phone-insurance/{make}+{model}/learn-more", name="learn_more_make_model",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     */
    public function learnMoreAction($id = null, $make = null, $model = null, $memory = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        if ($id) {
            $phone = $repo->find($id);
            if ($phone->getMemory()) {
                return $this->redirectToRoute('learn_more_make_model_memory', [
                    'make' => $phone->getMake(),
                    'model' => $phone->getEncodedModel(),
                    'memory' => $phone->getMemory(),
                ], 301);
            } else {
                return $this->redirectToRoute('learn_more_make_model', [
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
        } else {
            $phones = $repo->findBy(
                ['active' => true, 'make' => $make, 'model' => $decodedModel],
                ['memory' => 'asc'],
                1
            );
            if (count($phones) != 0 && mb_stripos($model, ' ') === false) {
                $phone = $phones[0];
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

        $user = new User();

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

        // if no price, will be sample policy of £100 annually
        $maxPot = $phone->getCurrentPhonePrice() ? $phone->getCurrentPhonePrice()->getMaxPot() : 80;
        $maxConnections = $phone->getCurrentPhonePrice() ? $phone->getCurrentPhonePrice()->getMaxConnections() : 8;
        $annualPremium = $phone->getCurrentPhonePrice() ? $phone->getCurrentPhonePrice()->getYearlyPremiumPrice() : 100;
        $maxComparision = $phone->getMaxComparision() ? $phone->getMaxComparision() : 80;

        $data = array(
            'phone' => $phone,
            'phone_price' => $phone->getCurrentPhonePrice(),
            'policy_key' => $this->getParameter('policy_key'),
            'connection_value' => PhonePolicy::STANDARD_VALUE,
            'annual_premium' => $annualPremium,
            'max_connections' => $maxConnections,
            'max_pot' => $maxPot,
            'buy_form' => $buyForm->createView(),
            'buy_form_banner' => $buyBannerForm->createView(),
            'phones' => $repo->findBy(
                ['active' => true, 'make' => $make, 'model' => $decodedModel],
                ['memory' => 'asc']
            ),
            'comparision' => $phone->getComparisions(),
            'comparision_max' => $maxComparision,
            'coming_soon' => $phone->getCurrentPhonePrice() ? false : true,
            //'slider_test' => $sliderTest,
            'slider_test' => 'slide-me',
        );

        return $this->render('AppBundle:PhoneInsurance:learnMore.html.twig', $data);
    }
}
