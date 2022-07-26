<?php

namespace AppBundle\Controller;

use AppBundle\Document\Subvariant;
use AppBundle\Exception\InvalidEmailException;
use AppBundle\Exception\InvalidFullNameException;
use AppBundle\Exception\ValidationException;
use AppBundle\Service\XmlAdapter\ArrayToXml;
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
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Feature;

use AppBundle\Service\MixpanelService;
use AppBundle\Service\IntercomService;

use AppBundle\Classes\GoCompare;
use AppBundle\Classes\Competitors;

class PhoneInsuranceController extends BaseController
{
    use PhoneTrait;

    const CACHE_LIST_PHONES_KEY_FORMAT = 'ListPhones:%s:%s';
    const CACHE_LIST_PHONES_TIME = 3600; // 1 hour

    /**
     * @Route("/phone-insurance/water-damage", name="phone_insurance_water_damage", options={"sitemap" = true})
     * @Route("/mobile-insurance/water-damage", name="mobile_insurance_water_damage")
     * @Template()
     */
    public function waterDamageAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_water_damage') {
            return $this->redirectToRoute('phone_insurance_water_damage', [], 301);
        }

        return $this->redirectToRoute('phone_insurance', [], 301);
    }

    /**
     * @Route("/phone-insurance/theft", name="phone_insurance_theft", options={"sitemap" = true})
     * @Route("/mobile-insurance/theft", name="mobile_insurance_theft")
     * @Template()
     */
    public function theftAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_theft') {
            return $this->redirectToRoute('phone_insurance_theft', [], 301);
        }

        return $this->redirectToRoute('phone_insurance', [], 301);
    }

    /**
     * @Route("/phone-insurance/loss", name="phone_insurance_loss", options={"sitemap" = true})
     * @Route("/mobile-insurance/loss", name="mobile_insurance_loss")
     * @Template()
     */
    public function lossAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_loss') {
            return $this->redirectToRoute('phone_insurance_loss', [], 301);
        }

        return $this->redirectToRoute('phone_insurance', [], 301);
    }

    /**
     * @Route("/phone-insurance/cracked-screen", name="phone_insurance_cracked_screen", options={"sitemap" = true})
     * @Route("/mobile-insurance/cracked-screen", name="mobile_insurance_cracked_screen")
     * @Template()
     */
    public function crackedScreenAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_cracked_screen') {
            return $this->redirectToRoute('phone_insurance_cracked_screen', [], 301);
        }

        return $this->redirectToRoute('phone_insurance', [], 301);
    }

    /**
     * @Route("/phone-insurance/broken-phone", name="phone_insurance_broken_phone", options={"sitemap" = true})
     * @Route("/mobile-insurance/broken-phone", name="mobile_insurance_broken_phone")
     * @Template()
     */
    public function brokenPhoneAction(Request $request)
    {
        if ($request->get('_route') == 'mobile_insurance_broken_phone') {
            return $this->redirectToRoute('phone_insurance_broken_phone', [], 301);
        }

        return array();
    }

    /**
     * SEO Pages - Second Hand Phone Insurance
     * @Route("/phone-insurance/second-hand", name="phone_insurance_second_hand", options={"sitemap" = true})
     * @Route("/phone-insurance/second-hand/m", name="phone_insurance_second_hand_m")
     */
    public function secondHandPhoneInsuranceAction(Request $request)
    {
        // Is indexed?
        $noindex = false;
        if ($request->get('_route') == 'phone_insurance_second_hand_m') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Second Hand Phone Insurance - LP']);
        }

        $data = [
            'is_noindex' => $noindex,
        ];

        return $this->render('AppBundle:PhoneInsurance:secondHandPhoneInsurance.html.twig', $data);
    }

    /**
     * SEO Pages - Refurbished Phone Insurance
     * @Route("/phone-insurance/refurbished", name="phone_insurance_refurbished", options={"sitemap" = true})
     * @Route("/phone-insurance/refurbished/m", name="phone_insurance_refurbished_m")
     */
    public function refurbishedPhoneInsuranceAction(Request $request)
    {
        // Is indexed?
        $noindex = false;
        if ($request->get('_route') == 'phone_insurance_refurbished_m') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Refurbished Phone Insurance - LP']);
        }

        $data = [
            'is_noindex' => $noindex,
        ];

        return $this->render('AppBundle:PhoneInsurance:refurbishedHandPhoneInsurance.html.twig', $data);
    }

    /**
     * SEO Pages - Phone Insurance
     * @Route("/phone-insurance", name="phone_insurance", options={"sitemap" = true})
     * @Route("/phone-insurance/m", name="phone_insurance_m")
     * @Route("/phone-insurance-hyperjar", name="phone_insurance_hyperjar")
     */
    public function phoneInsuranceAction(Request $request)
    {
        // Select the lowest
        // $fromPrice = $this->getLowestPremium();

        $competitorData = new Competitors();

        // Is indexed?
        $noindex = false;
        if ($request->get('_route') == 'phone_insurance_m') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Phone Insurance - LP']);
        } elseif ($request->get('_route') == 'phone_insurance_hyperjar') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => 'Phone Insurance - Hyperjar']);

            $session = $this->get('session');
            $session->set('bacsnotallowed', true);
        } else {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PHONE_INSURANCE_HOME_PAGE);
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PAGE_LOAD, [
                'Page' => 'landing_page',
                'Step' => 'mobile_insurance',
            ]);
        }

        $data = [
            // 'from_price' => $fromPrice,
            'competitor' => $competitorData::$competitorComparisonData,
            'is_noindex' => $noindex,
        ];

        // return $this->render('AppBundle:PhoneInsurance:phoneInsurance.html.twig', $data);
        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceHomepage.html.twig', $data);
    }

    /**
     * Route for Quote ID
     * @Route("/phone-insurance/{id}", name="quote_phone", requirements={"id":"[0-9a-f]{24,24}"})
    */
    public function phoneInsuranceIdAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;

        $data = [];
        if ($request->get('aggregator') && $request->get('aggregator') == 'true') {
            $data['aggregator'] = 'true';
        }

        if ($id) {
            /** @var Phone $phone */
            $phone = $repo->find($id);
            $data['make'] = $phone->getMakeCanonical();
            $data['model'] = $phone->getEncodedModelCanonical();
            $data['memory'] = $phone->getMemory();
            return $this->redirectToRoute('phone_insurance_make_model_memory', $data, 301);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for id: %s',
                $id
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance', $data));
        }
    }

    /**
     * SEO Pages - Phone Insurance > Make
     * @Route("/phone-insurance/{make}",
     * name="phone_insurance_make", requirements={"make":"[a-zA-Z]+"})
     * @Route("/phone-insurance/{make}/money",
     * name="phone_insurance_make_money", requirements={"make":"[a-zA-Z]+"})
     * @Route("/phone-insurance/{make}/m",
     * name="phone_insurance_make_m", requirements={"make":"[a-zA-Z]+"})
     */
    public function phoneInsuranceMakeAction(Request $request, $make = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $noindex = false;
        $money = false;

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

        // Check if caps in url and redirect back with make in lowercase - SEO
        if (preg_match("/^[A-Z]/", $make)) {
            return $this->redirectToRoute('phone_insurance_make', [
                'make' => mb_strtolower($make),
            ], 301);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find make page for: %s',
                $make
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        // Track Page
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_MANUFACTURER_PAGE);

        // To display in Popular Models sections
        $topPhones = $repo->findBy([
            'active' => true,
            'topPhone' => true,
            'makeCanonical' => mb_strtolower($make),
        ]);

        $competitorData = new Competitors();

        if ($request->get('_route') == 'phone_insurance_make_money') {
            $money = true;
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => sprintf('%s Phone Insurance - Money LP', $make)]);
        }

        if ($request->get('_route') == 'phone_insurance_make_m') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => sprintf('%s Phone Insurance - LP', $make)]);
        }

        $data = [
            'phone' => $phone,
            'top_phones' => $topPhones,
            'competitor' => $competitorData::$competitorComparisonData,
            'money_version' => $money,
            'is_noindex' => $noindex,
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMake.html.twig', $data);
    }

    /**
     * SEO Pages - Phone Insurance > Make > Model - Legacy Route
     * @Route("/phone-insurance/samsung/s8", name="phone_insurance_make_model_s8")
     */
    public function phoneInsuranceS8RedirectAction()
    {
        return $this->redirectToRoute('phone_insurance_make_model', [
            'make' => 'samsung',
            'model' => 'galaxy-s8',
        ], 301);
    }

    /**
     * SEO Pages - Phone Insurance > Make > Model - Legacy Route
     * @Route("/phone-insurance/samsung/s9", name="phone_insurance_make_model_s9")
     */
    public function phoneInsuranceS9RedirectAction()
    {
        return $this->redirectToRoute('phone_insurance_make_model', [
            'make' => 'samsung',
            'model' => 'galaxy-s9',
        ], 301);
    }

    /**
     * SEO Pages - Phone Insurance > Make > Model
     * @Route("/phone-insurance/{make}/{model}", name="phone_insurance_make_model",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/phone-insurance/{make}/{model}/m", name="phone_insurance_make_model_m",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/phone-insurance/{make}/{model}/money", name="phone_insurance_make_model_money",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     */
    public function phoneInsuranceMakeModelAction(Request $request, $make = null, $model = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);
        $noindex = false;
        $money = false;
        $upcoming = false;

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
            return $this->redirectToRoute('phone_insurance_make_model', [
                'make' => mb_strtolower($make),
                'model' => mb_strtolower($model),
            ], 301);
        }

        // Model caps redirect
        // Model hyp caps redirect

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for make/model page - make: %s model: %s',
                $make,
                $model
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        // Set make model for tracking
        $makemodel = $make." ".$model;

        // Track Page
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_MODEL_PAGE);

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
        // TODO: use make in template names
        $templateOverides = [
            'nokia 6',
        ];
        $templateOveride = $make." ".$model;
        $hideSection = false;
        $templateModel = $modelHyph.'.html.twig';
        $template = 'AppBundle:PhoneInsurance/Phones:'.$templateModel;

        // Check if template exists else default
        if (!$this->get('templating')->exists($template) or in_array($templateOveride, $templateOverides)) {
            $hideSection = true;
            $template = 'AppBundle:PhoneInsurance:phoneInsuranceMakeModel.html.twig';
        }

        // Get the price service
        $priceService = $this->get('app.price');

        // Check if pricing or enable the upcoming feature
        if (!$phone->getCurrentYearlyPhonePrice()) {
            $upcoming = true;
            $fromPrice = '9.49';
        } else {
            $fromPrice = $phone->getCurrentYearlyPhonePrice()->getMonthlyPremiumPrice();
        }

        $competitorData = new Competitors();

        if ($request->get('_route') == 'phone_insurance_make_model_money') {
            $noindex = true;
            $money = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => sprintf('%s Phone Insurance Money - LP', $makemodel)]);
        }

        if ($request->get('_route') == 'phone_insurance_make_model_m') {
            $noindex = true;
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
                'page' => sprintf('%s Phone Insurance - LP', $makemodel)]);
        }

        $data = [
            'phone' => $phone,
            'prices' => $priceService->userPhonePriceStreams(null, $phone, new \DateTime()),
            'phone_price' => $fromPrice,
            'img_url' => $modelHyph,
            'available_images' => $availableImages,
            'hide_section' => $hideSection,
            'competitor' => $competitorData::$competitorComparisonData,
            'money_version' => $money,
            'is_noindex' => $noindex,
            'upcoming' => $upcoming,
        ];

        return $this->render($template, $data);
    }

    /**
     * SEO/Quote Page - Phone Insurance > Make > Model > Memory
     * @Route("/phone-insurance/{make}+{model}+{memory}GB",
     * name="phone_insurance_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function phoneInsuranceMakeModelMemoryAction(
        Request $request,
        $make = null,
        $model = null,
        $memory = null
    ) {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $user = $this->getUser();
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);

        $phone = $repo->findOneBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make),
            'modelCanonical' => mb_strtolower($decodedModel),
            'memory' => (int) $memory,
        ]);
        // check for historical urls
        if (!$phone || mb_stripos($model, ' ') !== false) {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModelHyph),
                'memory' => (int) $memory,
            ]);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for make/model/memory page - make: %s model: %s mem: %s',
                $make,
                $model,
                $memory
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        $quoteUrl = $this->setPhoneSession($request, $phone);

        $validationRequired = null;
        $session = $this->get('session');
        if ($request->query->has('aggregator')) {
            // Aggregators - validation required
            $validationRequired = $request->get('aggregator');
            $session->set('aggregator', $validationRequired);
        }
        // Aggregators - Get session if coming back
        $validationRequired = $this->get('session')->get('aggregator');

        // In-store
        $instore = $this->get('session')->get('store');

        $buyForm = $this->makeBuyButtonForm('buy_form', 'buy');
        $buyBannerForm = $this->makeBuyButtonForm('buy_form_banner');
        $buyBannerTwoForm = $this->makeBuyButtonForm('buy_form_banner_two');
        // if no price, will be sample policy of £100 annually
        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
        $maxPot = $price ? $price->getMaxPot() : 80;
        $maxConnections = $price ? $price->getMaxConnections() : 8;
        $annualPremium = $price ? $price->getYearlyPremiumPrice() : 100;
        $maxComparision = $phone->getMaxComparision() ? $phone->getMaxComparision() : 80;
        $expIntercom = null;

        // Model template control
        // Hyphenate Model for images/template
        $modelHyph = str_replace('+', '-', $model);
        // Add available images for hero
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

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('buy_form')) {
                $buyForm->handleRequest($request);
                if ($buyForm->isValid()) {
                    $properties = [];
                    if ($buyForm->get('buy')->isClicked()) {
                        $properties['Location'] = 'main';
                    }
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

        // only need to run this once - if its a post, then ignore
        if ('GET' === $request->getMethod() && $price) {
            $event = MixpanelService::EVENT_QUOTE_PAGE;
            $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                'Device Selected' => $phone->__toString(),
                'Monthly Cost' => $price->getMonthlyPremiumPrice(),
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'First Device Selected' => $phone->__toString(),
                'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
            ], true);
        }

        // Get the price service
        $priceService = $this->get('app.price');

        $competitorData = new Competitors();

        $data = [
            'phone' => $phone,
            'prices' => $priceService->userPhonePriceStreams(null, $phone, new \DateTime()),
            'buy_form' => $buyForm->createView(),
            'buy_form_banner' => $buyBannerForm->createView(),
            'buy_form_banner_two'   => $buyBannerTwoForm->createView(),
            'phones' => $repo->findBy(
                [
                    'active' => true,
                    'makeCanonical' => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($decodedModel),
                ],
                ['memory' => 'asc']
            ),
            'instore' => $instore,
            'validation_required' => $validationRequired,
            'competitor' => $competitorData::$competitorComparisonData,
            'img_url' => mb_strtolower($modelHyph),
            'available_images' => $availableImages,
        ];
        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMakeModelMemory.html.twig', $data);
    }

    /**
     * SEO Pages Redirect - Phone Insurance > Make > Model
     * @Route("/phone-insurance/{make}+{model}", name="phone_insurance_make_model_old",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/phone-insurance/{make}+{model}/", name="phone_insurance_make_model_old_slash",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     */
    public function phoneInsuranceMakeModelRedirect($make = null, $model = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);

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

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for old make/model page - make: %s model: %s',
                $make,
                $model
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }

        return $this->redirectToRoute('phone_insurance_make_model', [
            'make' => $phone->getMakeCanonical(),
            'model' => $phone->getEncodedModelCanonical(),
        ], 301);
    }


    /**
     * ONLY Used for admin ????
     * TODO: Use make model memory
     * @Route("/purchase-phone/{make}+{model}+{memory}GB", name="purchase_phone_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function purchasePhoneAction()
    {
        return $this->redirectToRoute('purchase', [], 301);
    }

    /**
     * @Route("/quote-me/{id}", name="quote_me", requirements={"id":"[0-9a-f]{1,24}"})
     * @Route("/quote-me/{make}+{model}+{memory}GB", name="quote_me_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function quoteMe(Request $request, $id = null, $make = null, $model = null, $memory = null)
    {
        // For generic use by insurance aggregator sites
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $subvariantRepo = $dm->getRepository(Subvariant::class);
        $subvariants = $subvariantRepo->findAll();
        $decodedModel = Phone::decodeModel($model);
        $phone = null;
        $aggregator = '';
        $requester = '';

        if ($id) {
            if ($request->query->get('aggregator')) {
                $aggregator = '?aggregator=true';
                // If aggregator set, look for aggregator ID instead of phone ID
                if ($request->query->get('aggregator') == 'GoCompare') {
                    $goCompare = new GoCompare();
                    if (array_key_exists($id, $goCompare::$models)) {
                        /** @var Phone $phone */
                        $phone = $repo->findOneBy([
                            'active' => true,
                            'makeCanonical' => mb_strtolower($goCompare::$models[$id]['make']),
                            'modelCanonical' => mb_strtolower($goCompare::$models[$id]['model']),
                            'memory' => (int) $goCompare::$models[$id]['memory'],
                        ]);
                    }
                }
            } elseif ($request->query->get('requester')) {
                // Placeholder for generic use with partners
                $requester = '?requester=true';
            } else {
                /** @var Phone $phone */
                $phone = $repo->find($id);
            }
        }
        if ($memory) {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModel),
                'memory' => (int) $memory,
            ]);
        }
        if ($phone) {
            $subVariantArr['standard'] = [
                'subvariant' => 'standard',
                'price' => [
                    'monthlyPremium' => $phone->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice(),
                    'yearlyPremium' => $phone->getCurrentYearlyPhonePrice()->getYearlyPremiumPrice(),
                ],
                'excesses' => [
                    'defaultExcess' => $phone->getCurrentMonthlyPhonePrice()->getExcess() ?
                        $phone->getCurrentMonthlyPhonePrice()->getExcess()->toApiArray() :
                        [],
                    'validatedExcess' => $phone->getCurrentMonthlyPhonePrice()->getPicSureExcess() ?
                        $phone->getCurrentMonthlyPhonePrice()->getPicSureExcess()->toApiArray() :
                        [],
                ],
            ];
            $prices = [[
                'subvariant' => 'standard',
                'monthlyPremium' => $phone->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice(),
                'yearlyPremium' => $phone->getCurrentYearlyPhonePrice()->getYearlyPremiumPrice(),
            ]];
            foreach ($subvariants as $subvariant) {
                $subvarName = $subvariant->getName();
                $yearly = $phone->getCurrentYearlyPhonePrice(null, $subvarName);
                $monthly = $phone->getCurrentMonthlyPhonePrice(null, $subvarName);
                $price = [];
                if ($monthly && $yearly) {
                    $price = [
                        'monthlyPremium' => $monthly->getMonthlyPremiumPrice(),
                        'yearlyPremium' => $yearly->getYearlyPremiumPrice(),
                    ];
                }

                $subVariantArr[$subvarName] = [
                    'subvariant' => $subvarName,
                    'price' => $price,
                    'excesses' => [
                        'defaultExcess' => $monthly->getExcess() ?
                            $monthly->getExcess()->toApiArray($subvariant) :
                            [],
                        'validatedExcess' => $monthly->getPicSureExcess() ?
                            $monthly->getPicSureExcess()->toApiArray($subvariant) :
                            [],
                    ],
                ];
            }
            $response = new JsonResponse([
                'phoneId' => $phone->getId(),
                'subvariants'   => $subVariantArr

                // disabled temporarily to not confuse Comparison Creator
                /*'purchaseUrlRedirect' => $this->getParameter('web_base_url').'/phone-insurance/'.
                    str_replace(
                        ' ',
                        '+',
                        $phone->getMake().'+'.$phone->getModel().'+'.$phone->getMemory()
                    ).'GB'.$aggregator.$requester*/
            ]);
            return $response;
        }

        throw $this->createNotFoundException('Phone not found');
    }

    /**
     * @Route("/list-phones", name="list_phones")
     */
    public function listPhones(Request $request)
    {
        // For generic use by insurance aggregator sites
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $subvariantRepo = $dm->getRepository(Subvariant::class);
        $subvariants = $subvariantRepo->findAll();
        $xmlOutput = ($request->query->get('xml')) ?: null;
        $phones = $repo->findActive()->getQuery()->execute();
        $list = [];

        $redis = $this->get("snc_redis.default");
        $environment = $this->getParameter('kernel.environment');
        $redisKey = sprintf(
            self::CACHE_LIST_PHONES_KEY_FORMAT,
            $environment,
            $request->query->get('aggregator')
        );

        if ($redis->exists($redisKey)) {
            $list = json_decode($redis->get($redisKey));
        } else {
            /** @var Phone $phone */
            foreach ($phones as $phone) {
                // Loop through each phone and make an array for the response
                $aggregatorId = '';
                $requester = 'requesterId';
                if ($request->query->get('aggregator')) {
                    $requester = 'aggregatorId';
                    // If aggregator set, look for aggregator ID (if applicable)
                    if ($request->query->get('aggregator') == 'GoCompare') {
                        $goCompare = new GoCompare();
                        foreach ($goCompare::$models as $index => $model) {
                            if ($model['make'] == $phone->getMake()
                                && $model['model'] == $phone->getModel()
                                && $model['memory'] == $phone->getMemory()
                            ) {
                                $aggregatorId = $index;
                            }
                        }
                    }
                } elseif ($request->query->get('requester')) {
                    // Placeholder for generic use with partners
                    $requester = 'requesterId';
                }

                $subVariantArr['standard'] = [
                    'subvariant' => 'standard',
                    'price' => [
                        'monthlyPremium' => $phone->getCurrentMonthlyPhonePrice()->getMonthlyPremiumPrice(),
                        'yearlyPremium' => $phone->getCurrentYearlyPhonePrice()->getYearlyPremiumPrice(),
                    ],
                    'excesses' => [
                        'defaultExcess' => $phone->getCurrentMonthlyPhonePrice()->getExcess() ?
                            $phone->getCurrentMonthlyPhonePrice()->getExcess()->toApiArray() :
                            [],
                        'validatedExcess' => $phone->getCurrentMonthlyPhonePrice()->getPicSureExcess() ?
                            $phone->getCurrentMonthlyPhonePrice()->getPicSureExcess()->toApiArray() :
                            [],
                    ],
                ];

                foreach ($subvariants as $subvariant) {
                    $subvarName = $subvariant->getName();
                    $yearly = $phone->getCurrentYearlyPhonePrice(null, $subvarName);
                    $monthly = $phone->getCurrentMonthlyPhonePrice(null, $subvarName);
                    $price = [];
                    if ($monthly && $yearly) {
                        $price = [
                            'monthlyPremium' => $monthly->getMonthlyPremiumPrice(),
                            'yearlyPremium' => $yearly->getYearlyPremiumPrice(),
                        ];
                    }

                    $subVariantArr[$subvarName] = [
                        'subvariant' => $subvarName,
                        'price' => $price,
                        'excesses' => [
                            'defaultExcess' => $monthly->getExcess() ?
                                $monthly->getExcess()->toApiArray($subvariant) :
                                [],
                            'validatedExcess' => $monthly->getPicSureExcess() ?
                                $monthly->getPicSureExcess()->toApiArray($subvariant) :
                                [],
                        ],
                    ];
                }

                $list[] = [
                    'id'            => $phone->getId(),
                    'make'          => $phone->getMake(),
                    'model'         => $phone->getModel(),
                    'memory'        => $phone->getMemory(),
                    'devices'       => $phone->getDevices(),
                    'subvariants'   => $subVariantArr,
                    $requester      => $aggregatorId,
                ];

                // save to redis cache
                $redis->setex($redisKey, self::CACHE_LIST_PHONES_TIME, json_encode($list));
            }
        }

        if (true == $xmlOutput) {
            $xmlAdapter = new ArrayToXml();
            $result = $xmlAdapter->toXml($list);
            $response = new Response($result);
            $response->headers->set('Content-Type', 'text/xml');
            $response->headers->set('Access-Control-Allow-Origin', '*');
        } else {
            $response = new JsonResponse($list);
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        return $response;
    }

    private function makeBuyButtonForm(string $formName, string $buttonName = 'buy')
    {
        return $this->get('form.factory')
            ->createNamedBuilder($formName)
            ->add($buttonName, SubmitType::class)
            ->add('slider_used', HiddenType::class)
            ->getForm();
    }

    private function getAllPhones($make)
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
                'phone_insurance_make_model_memory',
                [
                    'make' => $phone->getMakeCanonical(),
                    'model' => $phone->getModelCanonical(),
                    'memory' => $phone->getMemory(),
                ]
            );
            ksort($phonesMem[$phone->getName()]['mem']);
        }

        return $phonesMem;
    }
}
