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
     * @Route("/phone-insurance/water-damage", name="phone_insurance_water_damage")
     * @Template()
     */
    public function waterDamageAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/theft", name="phone_insurance_theft")
     * @Template()
     */
    public function theftAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/loss", name="phone_insurance_loss")
     * @Template()
     */
    public function lossAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/cracked-screen", name="phone_insurance_cracked_screen")
     * @Template()
     */
    public function crackedScreenAction()
    {
        return array();
    }

    /**
     * @Route("/phone-insurance/broken-phone", name="phone_insurance_broken_phone")
     * @Template()
     */
    public function brokenPhoneAction()
    {
        return array();
    }


    /**
     * SEO Pages - Phone Insurance
     * @Route("/phone-insurance", name="phone_insurance")
     */
    public function phoneInsuranceAction()
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;

        // To display lowest monthly premium
        $fromPhones = $repo->findBy([
            'active' => true,
        ]);

        $fromPhones = array_filter($fromPhones, function ($phone) {
            return $phone->getCurrentPhonePrice();
        });

        // Sort by cheapest
        usort($fromPhones, function ($a, $b) {
            return $a->getCurrentPhonePrice()->getMonthlyPremiumPrice() <
            $b->getCurrentPhonePrice()->getMonthlyPremiumPrice() ? -1 : 1;
        });

        // Select the lowest
        $fromPrice = $fromPhones[0]->getCurrentPhonePrice()->getMonthlyPremiumPrice();

        $data = [
            'from_price' => $fromPrice,
            'from_phones' => $fromPhones,
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsurance.html.twig', $data);
    }

    /**
     * Route for Quote ID
     * @Route("/phone-insurance/{id}", name="quote_phone", requirements={"id":"[0-9a-f]{24,24}"})
    */
    public function phoneInsuranceIdAction($id = null, $make = null, $model = null, $memory = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);

        if ($id) {
            /** @var Phone $phone */
            $phone = $repo->find($id);
            return $this->redirectToRoute('phone_insurance_make_model_memory', [
                'make' => $phone->getMakeCanonical(),
                'model' => $phone->getEncodedModelCanonical(),
                'memory' => $phone->getMemory(),
            ], 301);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for id: %s make: %s model: %s mem: %s',
                $id,
                $make,
                $model,
                $memory
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
        }
    }

    /**
     * SEO Pages - Phone Insurance > Make
     * @Route("/phone-insurance/{make}",
     * name="phone_insurance_make", requirements={"make":"[a-zA-Z]+"})
     */
    public function phoneInsuranceMakeAction($make = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;

        $phones = $repo->findBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make)
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

        // To display in Popular Models sections
        $topPhones = $repo->findBy([
            'active' => true,
            'topPhone' => true,
            'makeCanonical' => mb_strtolower($make)
        ]);

        // To display lowest monthly premium
        $fromPhones = $repo->findBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make)
        ]);

        $fromPhones = array_filter($phones, function ($phone) {
            return $phone->getCurrentPhonePrice();
        });

        // Sort by cheapest
        usort($fromPhones, function ($a, $b) {
            return $a->getCurrentPhonePrice()->getMonthlyPremiumPrice() <
            $b->getCurrentPhonePrice()->getMonthlyPremiumPrice() ? -1 : 1;
        });

        // Select the lowest
        $fromPrice = $fromPhones[0]->getCurrentPhonePrice()->getMonthlyPremiumPrice();

        $data = [
            'phone' => $phone,
            'top_phones' => $topPhones,
            'from_price' => $fromPrice
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMake.html.twig', $data);
    }

    /**
     * SEO Pages - Phone Insurance > Make > Model
     * @Route("/phone-insurance/{make}/{model}", name="phone_insurance_make_model",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     */
    public function phoneInsuranceMakeModelAction($make = null, $model = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);

        $phones = $repo->findBy([
            'active' => true,
            'makeCanonical' => mb_strtolower($make),
            'modelCanonical' => mb_strtolower($decodedModel)
        ]);

        if (count($phones) != 0 && mb_stripos($model, ' ') === false) {
            $phone = $phones[0];
        } else {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModelHyph),
            ]);
        }

        if (!$phone) {
            $this->get('logger')->info(sprintf(
                'Failed to find phone for make/model page - make: %s model: %s',
                $make,
                $model
            ));
            return new RedirectResponse($this->generateUrl('phone_insurance'));
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
        ];
        // TODO: use make in template names
        $templateOverides = [
            'nokia 6'
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

        $data = [
            'phone' => $phone,
            'phone_price' => $phone->getCurrentPhonePrice(),
            'img_url' => $modelHyph,
            'available_images' => $availableImages,
            'hide_section' => $hideSection,
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
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        $decodedModelHyph = Phone::decodedModelHyph($model);

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
                'modelCanonical' => mb_strtolower($decodedModelHyph),
                'memory' => (int) $memory
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

        $buyForm = $this->makeBuyButtonForm('buy_form', 'buy');
        $buyBannerForm = $this->makeBuyButtonForm('buy_form_banner');
        $buyBannerTwoForm = $this->makeBuyButtonForm('buy_form_banner_two');

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
        if ('GET' === $request->getMethod() && $phone->getCurrentPhonePrice()) {
            $event = MixpanelService::EVENT_QUOTE_PAGE;
            $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                'Device Selected' => $phone->__toString(),
                'Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'First Device Selected' => $phone->__toString(),
                'First Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
            ], true);
        }

        $data = [
            'phone' => $phone,
            'phone_price' => $phone->getCurrentPhonePrice(),
            'buy_form' => $buyForm->createView(),
            'buy_form_banner' => $buyBannerForm->createView(),
            'buy_form_banner_two'   => $buyBannerTwoForm->createView(),
            'phones' => $repo->findBy(
                [
                    'active' => true,
                    'makeCanonical' => mb_strtolower($make),
                    'modelCanonical' => mb_strtolower($decodedModel)
                ],
                ['memory' => 'asc']
            ),
        ];

        return $this->render('AppBundle:PhoneInsurance:phoneInsuranceMakeModelMemory.html.twig', $data);
    }


    /**
     * ONLY Used for admin ????
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
     * @Route("/quote-me/{id}", name="quote_me", requirements={"id":"[0-9a-f]{24,24}"})
     * @Route("/quote-me/{make}+{model}+{memory}GB", name="quote_me_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     */
    public function quoteMe($id = null, $make = null, $model = null, $memory = null)
    {
        // For generic use by insurance aggregator sites
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $decodedModel = Phone::decodeModel($model);
        $phone = null;
        if ($id) {
            /** @var Phone $phone */
            $phone = $repo->find($id);
        }
        if ($memory) {
            $phone = $repo->findOneBy([
                'active' => true,
                'makeCanonical' => mb_strtolower($make),
                'modelCanonical' => mb_strtolower($decodedModel),
                'memory' => (int) $memory
            ]);
        }
        if ($phone) {
            $response = new JsonResponse([
                'phoneId' => $phone->getId(),
                'price' => [
                    'monthlyPremium' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
                    'yearlyPremium' => $phone->getCurrentPhonePrice()->getYearlyPremiumPrice()
                ],
                'productOverrides' => [
                    'excesses' => $phone->getCurrentPhonePrice()->getExcess() ?
                        $phone->getCurrentPhonePrice()->getExcess()->toApiArray() :
                        [],
                    'picsureExcesses' => $phone->getCurrentPhonePrice()->getPicSureExcess() ?
                        $phone->getCurrentPhonePrice()->getPicSureExcess()->toApiArray() :
                        []
                ],
                'purchaseUrlRedirect' => $this->getParameter('web_base_url').'/phone-insurance/'.
                    str_replace(' ', '+', $phone->getMake().'+'.$phone->getModel().'+'.$phone->getMemory())
                    .'GB?skip=1'
            ]);
            return $response;
        }

        throw $this->createNotFoundException('Phone not found');
    }

    /**
     * @Route("/list-phones", name="list_phones")
     */
    public function listPhones()
    {
        // For generic use by insurance aggregator sites
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findActive()->getQuery()->execute();
        $list = [];
        foreach ($phones as $phone) {
            $list[] = [
                'id'        => $phone->getId(),
                'make'      => $phone->getMake(),
                'model'     => $phone->getModel(),
                'memory'    => $phone->getMemory()
            ];
        }
        $response = new JsonResponse($list);
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
                    'memory' => $phone->getMemory()
                ]
            );
            ksort($phonesMem[$phone->getName()]['mem']);
        }

        return $phonesMem;
    }
}
