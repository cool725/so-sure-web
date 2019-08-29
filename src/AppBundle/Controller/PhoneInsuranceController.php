<?php

namespace AppBundle\Controller;

use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\InvalidFullNameException;
use AppBundle\Exception\ValidationException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

use AppBundle\Form\Type\LaunchType;
use AppBundle\Form\Type\LeadEmailType;
use AppBundle\Form\Type\RegisterUserType;
use AppBundle\Form\Type\PhoneMakeType;
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
     * @Route("/phone-insurance", name="phone_insurance")
     */
    public function phoneInsuranceAction()
    {

        return $this->render('AppBundle:PhoneInsurance:phoneInsurance.html.twig');
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
        if ($request->get('_route') == 'purchase_phone_make_model_memory') {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, [
                'Location' => 'offsite'
            ]);

            // Multipolicy should skip user details
            if ($this->getUser() && $this->getUser()->hasPolicy()) {
                // don't check for partial partial as quote phone may be different from partial policy phone
                return $this->redirectToRoute('purchase_step_phone');
            } else {
                return $this->redirectToRoute('purchase');
            }
        }
    }

    /**
     * Note that any changes to actual path routes need to be reflected in the Google Analytics Goals
     *   as these will impact Adwords
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
        // Skip to purchase
        // TODO - Let's remove altogether
        $skipToPurchase = $request->get('skip');

        if (in_array($request->get('_route'), ['insure_make_model_memory', 'insure_make_model'])) {
            return new RedirectResponse($this->generateUrl('homepage'));
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        if ($id) {
            /** @var Phone $phone */
            $phone = $repo->find($id);
            if ($phone->getMemory() && !$skipToPurchase) {
                return $this->redirectToRoute('quote_make_model_memory', [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getEncodedModelCanonical(),
                    'memory' => $phone->getMemory(),
                ], 301);
            }

            if (!$skipToPurchase) {
                return $this->redirectToRoute('quote_make_model', [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getEncodedModelCanonical(),
                ], 301);
            }
        }

        if ($memory) {
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
                if ($phone && !$skipToPurchase) {
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
                if ($phone && !$skipToPurchase) {
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
        } elseif (!$phone->isSameMakeModelCanonical($make, $model) && !$skipToPurchase) {
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

        $buyForm = $this->makeBuyButtonForm('buy_form', 'buy_tablet');
        $buyBannerForm = $this->makeBuyButtonForm('buy_form_banner');
        $buyBannerTwoForm = $this->makeBuyButtonForm('buy_form_banner_two');
        $buyBannerThreeForm = $this->makeBuyButtonForm('buy_form_banner_three');
        $buyBannerFourForm = $this->makeBuyButtonForm('buy_form_banner_four', 'buy');

        // Burger vs Full Menu - Proceed
        $this->get('app.sixpack')->convert(SixpackService::EXPERIMENT_BURGER_MENU);

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
                    // if ($buyForm->getData()['claim_used']) {
                    //     $properties['Played with Claims'] = true;
                    // }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
                    }

                    return $this->redirectToRoute('purchase');
                }
            } elseif ($request->request->has('buy_form_banner')) {
                $buyBannerForm->handleRequest($request);
                if ($buyBannerForm->isValid()) {
                    $properties = [];
                    $properties['Location'] = 'seeFullDetailsMobile';
                    // if ($buyForm->getData()['claim_used']) {
                    //     $properties['Played with Claims'] = true;
                    // }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
                    }

                    return $this->redirectToRoute('purchase');
                }
            } elseif ($request->request->has('buy_form_banner_two')) {
                $buyBannerTwoForm->handleRequest($request);
                if ($buyBannerTwoForm->isValid()) {
                    $properties = [];
                    $properties['Location'] = 'seeFullDetailsDesktop';
                    // if ($buyForm->getData()['claim_used']) {
                    //     $properties['Played with Claims'] = true;
                    // }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
                    } else {
                        return $this->redirectToRoute('purchase');
                    }
                }
            } elseif ($request->request->has('buy_form_banner_three')) {
                $buyBannerThreeForm->handleRequest($request);
                if ($buyBannerThreeForm->isValid()) {
                    $properties = [];
                    $properties['Location'] = 'sidebar';
                    // if ($buyForm->getData()['claim_used']) {
                    //     $properties['Played with Claims'] = true;
                    // }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
                    } else {
                        return $this->redirectToRoute('purchase');
                    }
                }
            } elseif ($request->request->has('buy_form_banner_four')) {
                $buyBannerFourForm->handleRequest($request);
                if ($buyBannerFourForm->isValid()) {
                    $properties = [];
                    $properties['Location'] = 'sticky';
                    // if ($buyForm->getData()['claim_used']) {
                    //     $properties['Played with Claims'] = true;
                    // }
                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_BUY_BUTTON_CLICKED, $properties);

                    // Multipolicy should skip user details
                    if ($this->getUser() && $this->getUser()->hasPolicy()) {
                        // don't check for partial partial as quote phone may be different from partial policy phone
                        return $this->redirectToRoute('purchase_step_phone');
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
        }

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
        ];

        $template = 'AppBundle:PhoneInsurance:quote.html.twig';
        $hideSection = false;

        // SEO pages
        if ($request->get('_route') == 'quote_make_model') {
            // Model template
            $templateModel = $modelHyph.'.html.twig';
            $template = 'AppBundle:PhoneInsurance/Phones:'.$templateModel;

            // Check if template exists
            if (!$this->get('templating')->exists($template)) {
                $hideSection = true;
                $template = 'AppBundle:PhoneInsurance:phoneInsuranceMakeModel.html.twig';
            }
        }

        $data = array(
            'phone'                 => $phone,
            'phone_price'           => $phone->getCurrentPhonePrice(),
            'policy_key'            => $this->getParameter('policy_key'),
            'connection_value'      => PhonePolicy::STANDARD_VALUE,
            'lead_form'             => $leadForm->createView(),
            'buy_form'              => $buyForm->createView(),
            'buy_form_banner'       => $buyBannerForm->createView(),
            'buy_form_banner_two'   => $buyBannerTwoForm->createView(),
            'buy_form_banner_three' => $buyBannerThreeForm->createView(),
            'buy_form_banner_four'  => $buyBannerFourForm->createView(),
            'phones'                => $repo->findBy(
                [
                    'active'         => true,
                    'makeCanonical'  => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($decodedModel)
                ],
                ['memory' => 'asc']
            ),
            'comparision'      => $phone->getComparisions(),
            'comparision_max'  => $maxComparision,
            'coming_soon'      => $phone->getCurrentPhonePrice() ? false : true,
            'web_base_url'     => $this->getParameter('web_base_url'),
            'img_url'          => $modelHyph,
            'available_images' => $availableImages,
            'hide_section'     => $hideSection,
        );

        if ($skipToPurchase) {
            return $this->redirectToRoute('purchase');
        }
        return $this->render($template, $data);
    }

    private function makeBuyButtonForm(string $formName, string $buttonName = 'buy')
    {
        return $this->get('form.factory')
            ->createNamedBuilder($formName)
            ->add($buttonName, SubmitType::class)
            ->add('slider_used', HiddenType::class)
            ->getForm();
    }

    private function getAllPhonesByMake($make)
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

        return $phones;
    }

    private function sortPhoneNamesByMemory($phones): array
    {
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

        return $phonesMem;
    }
}
