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
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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
use AppBundle\Document\Claim;
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

class DefaultController extends BaseController
{
    use PhoneTrait;

    /**
     * @Route("/", name="homepage", options={"sitemap"={"priority":"1.0","changefreq":"daily"}})
     * @Route("/discount-vouchers", name="discount-vouchers")
     */
    public function indexAction(Request $request)
    {
        $sixpack = $this->get('app.sixpack')
            ->participate(SixpackService::EXPERIMENT_HOMEPAGE_PHONE_IMAGE, ['phone-image', 'plain']);
        // $sixpack = $this->get('app.sixpack')
            // ->participate(SixpackService::EXPERIMENT_HOMEPAGE_PHONE_IMAGE, ['plain', 'phone-image']);
        $geoip = $this->get('app.geoip');
        //$ip = "72.229.28.185";
        $ip = $request->getClientIp();
        $site = $request->get('site');
        $userAgent = $request->headers->get('User-Agent');
        // make sure to exclude us based bots that import content - eg. facebook/twitter
        // https://developers.facebook.com/docs/sharing/webmasters/crawler
        // https://dev.twitter.com/cards/getting-started#crawling
        if ($geoip->findCountry($ip) == "US" && $site != 'uk' &&
            !preg_match("/Twitterbot|facebookexternalhit|Facebot/i", $userAgent)) {
            return $this->redirectToRoute('launch_usa');
        }
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $phoneRepo = $dm->getRepository(Phone::class);
        $logger = $this->get('logger');
        $launchUser = $this->get('app.user.launch');

        $phone = null;
        $phoneName = (string) $request->get('phone');
        $matches = null;
        if (preg_match('/([^ ]+) (.*) ([0-9]+)GB/', $phoneName, $matches) !== false && count($matches) >= 3) {
            $decodedModel = Phone::decodeModel($matches[2]);
            $phone = $phoneRepo->findOneBy([
                'active' => true,
                'make' => $matches[1],
                'model' => $decodedModel,
                'memory' => (int) $matches[3]
            ]);
        }

        $userTop = new User();
        $referral = $request->get('referral');
        if ($referral) {
            $userTop->setReferralId($referral);
            $session = $this->get('session');
            $session->set('referral', $referral);
            $logger->debug(sprintf('Referral %s', $referral));
        }
        $userBottom = clone $userTop;
        $formTop = $this->get('form.factory')
            ->createNamedBuilder('launch_top', LaunchType::class, $userTop)
            ->getForm();
        $formBottom = $this->get('form.factory')
            ->createNamedBuilder('launch_bottom', LaunchType::class, $userBottom)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $existingUser = null;
            if ($request->request->has('launch_top')) {
                $formTop->handleRequest($request);
                if ($formTop->isValid()) {
                    $existingUser = $launchUser->addUser($userTop)['user'];
                }
            } elseif ($request->request->has('launch_bottom')) {
                $formBottom->handleRequest($request);
                if ($formBottom->isValid()) {
                    $existingUser = $launchUser->addUser($userBottom)['user'];
                }
            }

            if ($existingUser) {
                return $this->redirectToRoute('launch_share', ['id' => $existingUser->getId()]);
            }
        }

        $i6s = $phoneRepo->findOneBy([
                'active' => true,
                'make' => 'Apple',
                'model' => 'iPhone 6S',
                'memory' => (int) 32
        ]);

        $i7 = $phoneRepo->findOneBy([
            'active' => true,
            'make' => 'Apple',
            'model' => 'iPhone 7',
            'memory' => (int) 32
        ]);

        $s7 = $phoneRepo->findOneBy([
            'active' => true,
            'make' => 'Samsung',
            'model' => 'Galaxy S7',
            'memory' => (int) 32
        ]);

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE);

        $data = array(
            'form_top' => $formTop->createView(),
            'form_bottom' => $formBottom->createView(),
            'referral' => $referral,
            'i6s' => $i6s,
            'i7' => $i7,
            'phone' => $phone,
            's7' => $s7,
            'sixpack' => $sixpack,
        );

        if (in_array($request->get('_route'), ['discount-vouchers'])) {
            return $this->render('AppBundle:Default:discountVouchers.html.twig', $data);
        } else {
            return $this->render('AppBundle:Default:index.html.twig', $data);
        }
    }

    /**
     * @Route("/select-phone", name="select_phone_make")
     * @Route("/select-phone/{type}", name="select_phone_make_type")
     * @Route("/select-phone/{type}/{id}", name="select_phone_make_type_id")
     * @Template()
     */
    public function selectPhoneMakeAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        if ($id) {
            $phone = $phoneRepo->find($id);
        }

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            $session = $request->getSession();
            $session->set('quote', $phone->getId());

            return $this->redirectToRoute('purchase_step_policy');
        }

        return [
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/search-phone", name="search_phone_data")
     */
    public function searchPhoneAction()
    {
        return new JsonResponse(
            $this->getPhonesSearchArray()
        );
    }

    /**
     * @Route("/login-redirect", name="login_redirect")
     */
    public function loginRedirectAction()
    {
        if ($this->getUser()) {
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('admin_home');
            } elseif ($this->isGranted('ROLE_EMPLOYEE')) {
                return $this->redirectToRoute('admin_home');
            } elseif ($this->isGranted('ROLE_CLAIMS')) {
                return $this->redirectToRoute('claims_home');
            } elseif ($this->isGranted('ROLE_USER')) {
                return $this->redirectToRoute('user_home');
            }
        }

        return $this->redirectToRoute('homepage');
    }

    /**
     * @Route("/usa", name="launch_usa")
     * @Template
     */
    public function launchUSAAction()
    {
        return [];
    }

    /**
     * @Route("/design-patterns", name="design_patterns")
     * @Template
     */
    public function designPatternsAction()
    {
        return [];
    }

    /**
     * @Route("/mobile-phone-insurance-for-your-company",
     *  name="mobile_phone_insurance_for_your_company",
     *  options={"sitemap"={"priority":"1.0","changefreq":"daily"}})
     * @Route("/mobile-phone-insurance-for-your-company/thank-you",
     *  name="mobile_phone_insurance_for_your_company_thanks")
     * @Template
     */
    public function mobileInsuranceForYourCompany(Request $request)
    {
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form')
            ->add('email', EmailType::class)
            ->add('name', TextType::class)
            ->add('company', TextType::class)
            ->add('phone', TextType::class)
            ->add('phones', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'No. of phones to insure...',
                'choices' => [
                    'less than 10' => 'less than 10',
                    '10-50' => '10-50',
                    'more than 50' => 'more than 50',
                    'uncertain' => 'uncertain'
                ],
            ])
            ->add('timeframe', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Purchasing timeframe...',
                'choices' => [
                    'immedidate' => 'immedidate',
                    'this quarter' => 'this quarter',
                    'next quarter' => 'next quarter',
                    'next year' => 'next year',
                    'uncertain' => 'uncertain'
                ],
            ])
            ->add('message', TextareaType::class)
            ->add('submit', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('lead_form')) {
                $leadForm->handleRequest($request);
                if ($leadForm->isValid()) {
                    // @codingStandardsIgnoreStart
                    $body = sprintf(
                        "Name: %s\nCompany: %s\nEmail: %s\nContact #: %s\n# Phones: %s\nPurchasing Timeframe: %s\nMessage: %s",
                        $leadForm->getData()['name'],
                        $leadForm->getData()['company'],
                        $leadForm->getData()['email'],
                        $leadForm->getData()['phone'],
                        $leadForm->getData()['phones'],
                        $leadForm->getData()['timeframe'],
                        $leadForm->getData()['message']
                    );
                    // @codingStandardsIgnoreEnd

                    $message = \Swift_Message::newInstance()
                        ->setSubject('Company inquiry')
                        ->setFrom('info@so-sure.com')
                        ->setTo('sales@so-sure.com')
                        ->setBody($body, 'text/html');
                    $this->get('mailer')->send($message);
                    $this->addFlash(
                        'success',
                        "Thanks. We'll be in touch shortly"
                    );

                    return $this->redirectToRoute('mobile_phone_insurance_for_your_company_thanks');
                }
            }
        }

        return [
            'lead_form' => $leadForm->createView(),
        ];
    }

    /**
     * @Route("/claim", name="claim")
     * @Template
     */
    public function claimAction(Request $request)
    {
        $claimForm = $this->get('form.factory')
            ->createNamedBuilder('claim_form')
            ->add('email', EmailType::class)
            ->add('name', TextType::class)
            ->add('policyNumber', TextType::class)
            ->add('phone', TextType::class)
            ->add('timeToReach', TextType::class)
            ->add('signature', TextType::class)
            ->add('type', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'My phone is...',
                'choices' => [
                    'My phone is lost' => Claim::TYPE_LOSS,
                    'My phone was stolen' => Claim::TYPE_THEFT,
                    'My phone is damaged or not working' => Claim::TYPE_DAMAGE,
                ],
            ])
            ->add('message', TextareaType::class)
            ->add('submit', SubmitType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claim_form')) {
                $claimForm->handleRequest($request);
                if ($claimForm->isValid()) {
                    // @codingStandardsIgnoreStart
                    $body = sprintf(
                        "Name: %s<br>Policy Number: %s<br>Email: %s<br>Contact #: %s<br># Best time to reach: %s<br>Type of claim: %s<br>Message: %s<br>Signature: %s",
                        $claimForm->getData()['name'],
                        $claimForm->getData()['policyNumber'],
                        $claimForm->getData()['email'],
                        $claimForm->getData()['phone'],
                        $claimForm->getData()['timeToReach'],
                        $claimForm->getData()['type'],
                        $claimForm->getData()['message'],
                        $claimForm->getData()['signature']
                    );
                    // @codingStandardsIgnoreEnd

                    $message = \Swift_Message::newInstance()
                        ->setSubject(sprintf(
                            'New Claim from %s/%s',
                            $claimForm->getData()['name'],
                            $claimForm->getData()['policyNumber']
                        ))
                        ->setFrom('info@so-sure.com')
                        ->setTo('claims@wearesosure.com')
                        ->setBody($body, 'text/html');
                    $this->get('mailer')->send($message);
                    $this->addFlash(
                        'success',
                        "Thanks. We'll be in touch shortly"
                    );

                    return $this->redirectToRoute('claim');
                }
            }
        }

        return [
            'claim_form' => $claimForm->createView(),
        ];
    }

    /**
     * @Route("/register", name="register")
     * @Template
     */
    public function registerAction(Request $request)
    {
        $dm = $this->getManager();
        $registerUser = new Register();
        $session = $request->getSession();
        if ($session->get('email')) {
            $registerUser->setEmail($session->get('email'));
        }

        $form = $this->get('form.factory')
            ->createNamedBuilder('launch', RegisterUserType::class, $registerUser)
            ->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {
            $userRepo = $dm->getRepository(User::class);
            if ($userRepo->existsUser($registerUser->getEmail(), null, null)) {
                $this->addFlash('warning', 'Looks like you already have an account.');
                return $this->redirectToRoute('user_home');
            }

            // TODO: add to intercom?
            $lead = new Lead();
            $lead->setSource(Lead::SOURCE_BUY);
            $lead->setEmail($registerUser->getEmail());

            $dm->persist($lead);
            $dm->flush();
            $session->set('email', $registerUser->getEmail());

            return $this->redirectToRoute('purchase');
        }

        return [
            'form' => $form->createView(),
            'quote' => $session->get('quote'),
        ];
    }

    /**
     * @Route("/alpha", name="alpha")
     * @Template
     */
    public function alphaAction()
    {
        return array();
    }

    /**
     * @Route("/download-app", name="download_app", options={"sitemap"={"priority":"0.5","changefreq":"daily"}})
     * @Template
     */
    public function downloadAppAction()
    {
        return [];
    }

    /**
     * @Route("/text-me-the-app", name="sms_app_link", options={"sitemap"={"priority":"0.5","changefreq":"daily"}})
     * @Template
     */
    public function smsAppLinkAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Lead::class);
        $form = $this->createForm(SmsAppLinkType::class);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $mobileNumber = $form->get('mobileNumber')->getData();
            $ukMobileNumber = $this->normalizeUkMobile($mobileNumber, true);
            $lead = $repo->findOneBy(['mobileNumber' => $ukMobileNumber]);
            if ($lead) {
                $this->addFlash('error', sprintf(
                    "Oops, looks like we already sent you a link.",
                    $mobileNumber
                ));
            } elseif (!$this->isValidUkMobile($ukMobileNumber)) {
                $this->addFlash('error', sprintf(
                    '%s does not appear to be a valid UK Mobile Number',
                    $mobileNumber
                ));
            } else {
                $sms = $this->get('app.sms');
                $message = $this->get('templating')->render(
                    'AppBundle:Sms:text-me.txt.twig',
                    ['branch_pot_url' => $this->getParameter('branch_pot_url')]
                );
                if ($sms->send($ukMobileNumber, $message)) {
                    $lead = new Lead();
                    $lead->setMobileNumber($ukMobileNumber);
                    $lead->setSource(Lead::SOURCE_TEXT_ME);
                    $dm->persist($lead);
                    $dm->flush();
                    $this->addFlash('success', sprintf(
                        'You should receive a download link shortly',
                        $ukMobileNumber
                    ));
                } else {
                    $this->addFlash('error', sprintf(
                        'Sorry, we had a problem sending a link to %s',
                        $mobileNumber
                    ));
                }
            }
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/price/{id}/", name="price_item")
     */
    public function priceItemAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if (!$phone) {
            return new JsonResponse([], 404);
        }

        return new JsonResponse([
            'price' => $phone->getCurrentPhonePrice(),
        ]);
    }

    /**
     * @Route("/think-your-iPhone-7-is-insured-by-your-bank", name="think_your_iPhone-7_is_insured_by_your_bank")
     * @Template
     */
    public function thinkYourIPhone7IsInsuredByYourBank()
    {
        return array();
    }

    /**
     * @Route("/so-sure-vs-protect-your-bubble", name="so_sure_vs_protect_your_bubble")
     * @Route("/so-sure-vs-carphone-warehouse", name="so_sure_vs_carphone_warehouse")
     * @Template
     */
    public function soSureVsCompetitor(Request $request)
    {
        $exp = $this->get('app.sixpack')->participate(
            SixpackService::EXPERIMENT_PYG_HOME,
            ['pyg', 'home'],
            true
        );
        if ($exp == 'home') {
            return new RedirectResponse($this->generateUrl('homepage'));
        }

        if ($request->get('_route') == "so_sure_vs_protect_your_bubble") {
            $data = [
                'c_name' => 'Protect Your Bubble',
                's_theft' => 'Yes',
                's_theft_bg' => 'tick-background',
                's_loss' => 'As standard',
                's_loss_bg' => 'tick-background',
                's_theft_replacement' => '1 working day',
                's_damage_replacement' => '1 working day',
                's_used_phones' => 'Yes',
                's_used_phones_bg' => 'tick-background',
                's_cashback' => 'Yes',
                's_cashback_bg' => 'tick-background',
                'c_theft' => 'Yes',
                'c_theft_bg' => 'tick-background',
                'c_loss' => 'Extra £1.50 per month',
                'c_loss_bg' => 'cross-background',
                'c_theft_replacement' => '2 working days',
                'c_damage_replacement' => '3-5 working days',
                'c_used_phones' => 'Only up to 12 months old',
                'c_used_phones_bg' => 'cross-background',
                'c_cashback' => 'No',
                'c_cashback_bg' => 'cross-background',
            ];
        } elseif ($request->get('_route') == "so_sure_vs_carphone_warehouse") {
            $data = [
                'c_name' => 'Carphone Warehouse',
                's_theft' => 'Yes',
                's_theft_bg' => 'tick-background',
                's_loss' => 'Yes',
                's_loss_bg' => 'tick-background',
                's_theft_replacement' => 'Next working day',
                's_damage_replacement' => 'Next working day',
                's_used_phones' => 'Yes',
                's_used_phones_bg' => 'tick-background',
                's_cashback' => 'Yes',
                's_cashback_bg' => 'tick-background',
                'c_theft' => 'Yes',
                'c_theft_bg' => 'tick-background',
                'c_loss' => 'Yes',
                'c_loss_bg' => 'tick-background',
                'c_theft_replacement' => 'Next working day',
                'c_damage_replacement' => 'Next working day',
                'c_used_phones' => 'No',
                'c_used_phones_bg' => 'cross-background',
                'c_cashback' => 'No',
                'c_cashback_bg' => 'cross-background',
            ];
        } 

        return array('data' => $data);
    }

    /**
     * @Route("/samsung-s7-insured-with-vodafone", name="samsung_s7_insured_with_vodafone")
     * @Route("/google-pixel-insured-with-vodafone", name="google_pixel_insured_with_vodafone")
     * @Route("/iphone-SE-insured-with-vodafone", name="iphone_SE_insured_with_vodafone")
     * @Route("/iphone-6-insured-with-vodafone", name="iphone_6_insured_with_vodafone")
     * @Route("/iphone-6s-insured-with-vodafone", name="iphone_6s_insured_with_vodafone")
     * @Route("/iphone-7-insured-with-vodafone", name="iphone_7_insured_with_vodafone")
     * @Route("/iphone-7-plus-insured-with-vodafone", name="iphone_7_plus_insured_with_vodafone")
     * @Template
     */
    public function insuredWithVodafone(Request $request)
    {
        if ($request->get('_route') == "samsung_s7_insured_with_vodafone") {
            $phoneName = "Samsung S7";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'Samsung',
                'model' => 'Galaxy+S7',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "google_pixel_insured_with_vodafone") {
            $phoneName = "Google Pixel";
            $phonePrice = "8.49";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'Google',
                'model' => 'Pixel',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_SE_insured_with_vodafone") {
            $phoneName = "iPhone SE";
            $phonePrice = "6.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'Apple',
                'model' => 'iPhone+SE',
                'memory' => '16'
            ]);
        } elseif ($request->get('_route') == "iphone_7_plus_insured_with_vodafone") {
            $phoneName = "iPhone 7 Plus";
            $phonePrice = "9.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'Apple',
                'model' => 'iPhone+7+Plus',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_7_insured_with_vodafone") {
            $phoneName = "iPhone 7";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'Apple',
                'model' => 'iPhone+7',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_6s_insured_with_vodafone") {
            $phoneName = "iPhone 6S";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'Apple',
                'model' => 'iPhone+6S',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_6_insured_with_vodafone") {
            $phoneName = "iPhone 6";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'Apple',
                'model' => 'iPhone+6',
                'memory' => '16'
            ]);
        }
        return array('phone_name' => $phoneName, 'phone_price' => $phonePrice, 'quote_route' => $quoteRoute);
    }

    /**
     * @Route("/samsung-s7-insured-with-your-mobile-network", name="samsung_s7_insured_with_your_mobile_network")
     * @Route("/google-pixel-insured-with-your-mobile-network", name="google_pixel_insured_with_your_mobile_network")
     * @Route("/iphone-SE-insured-with-your-mobile-network", name="iphone_SE_insured_with_your_mobile_network")
     * @Route("/iphone-6-insured-with-your-mobile-network", name="iphone_6_insured_with_your_mobile_network")
     * @Route("/iphone-6s-insured-with-your-mobile-network", name="iphone_6s_insured_with_your_mobile_network")
     * @Route("/iphone-7-insured-with-your-mobile-network", name="iphone_7_insured_with_your_mobile_network")
     * @Route("/iphone-7-plus-insured-with-your-mobile-network", name="iphone_7_plus_insured_with_your_mobile_network")
     * @Template
     */
    public function insuredWithMobileNetwork(Request $request)
    {
        $repo = $this->getManager()->getRepository(Phone::class);
        if ($request->get('_route') == "samsung_s7_insured_with_your_mobile_network") {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => 'Samsung',
                'model' => 'Galaxy S7',
                'memory' => (int) 32
            ]);
            $phoneName = "Samsung S7";
        } elseif ($request->get('_route') == "google_pixel_insured_with_your_mobile_network") {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => 'Google',
                'model' => 'Pixel',
                'memory' => (int) 32
            ]);
            $phoneName = "Google Pixel";
        } elseif ($request->get('_route') == "iphone_SE_insured_with_your_mobile_network") {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => 'Apple',
                'model' => 'iPhone SE',
                'memory' => (int) 16
            ]);
            $phoneName = "iPhone SE";
        } elseif ($request->get('_route') == "iphone_7_plus_insured_with_your_mobile_network") {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => 'Apple',
                'model' => 'iPhone 7 Plus',
                'memory' => (int) 32
            ]);
            $phoneName = "iPhone 7 Plus";
        } elseif ($request->get('_route') == "iphone_7_insured_with_your_mobile_network") {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => 'Apple',
                'model' => 'iPhone 7',
                'memory' => (int) 32
            ]);
            $phoneName = "iPhone 7";
        } elseif ($request->get('_route') == "iphone_6s_insured_with_your_mobile_network") {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => 'Apple',
                'model' => 'iPhone 6S',
                'memory' => (int) 32
            ]);
            $phoneName = "iPhone 6S";
        } elseif ($request->get('_route') == "iphone_6_insured_with_your_mobile_network") {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => 'Apple',
                'model' => 'iPhone 6',
                'memory' => (int) 16
            ]);
            $phoneName = "iPhone 6";
        }
        return array(
            'phone' => $phone,
            'phone_name' => $phoneName,
        );
    }


    /**
     * @Route("/phone/{make}/{model}", name="phone_make_model")
     * @Template
     */
    public function phoneMakeModelAction($make, $model)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->findOneBy(['make' => $make, 'model' => $model]);
        if (!$phone) {
            return new RedirectResponse($this->generateUrl('phone_make', ['make' => $make]));
        }

        return array('phone' => $phone);
    }

    /**
     * @Route("/phone/{make}", name="phone_make")
     * @Template
     */
    public function phoneMakeAction($make)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(['make' => $make]);
        // TODO: Redirect to other phone

        return array('phones' => $phones);
    }

    /**
     * @Route("/apple-app-site-association", name="apple-app-site-assocaition")
     */
    public function appleAppAction()
    {
        $view = $this->renderView('AppBundle:Default:apple-app-site-association.json.twig');

        return new Response($view, 200, array('Content-Type'=>'application/json'));
    }

    /**
     * @Route("/login/digits", name="digits_login")
     * @Method({"POST"})
     */
    public function digitsLoginAction(Request $request)
    {
        try {
            $csrf = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('authenticate', $csrf)) {
                throw new \Exception('Invalid csrf');
            }

            $credentials = $request->request->get('credentials');
            $provider = $request->request->get('provider');
            $digits = $this->get('app.digits');
            $user = $digits->validateUser($provider, $credentials);
            if (!$user) {
                throw new \Exception('Unknown user');
            }
            $this->get('fos_user.security.login_manager')->loginUser(
                $this->getParameter('fos_user.firewall_name'),
                $user
            );

            return new RedirectResponse($this->generateUrl('user_home'));
        } catch (\Exception $e) {
            $this->addFlash('error', 'Unable to login.  Did you create a policy using our app yet?');

            return new RedirectResponse($this->generateUrl('fos_user_security_login'));
        }
    }

    /**
     * @Route("/optout", name="optout")
     * @Template()
     */
    public function optOutAction(Request $request)
    {
        $form = $this->createFormBuilder()
            ->add('email', EmailType::class, array(
                'label' => "Email",
            ))
            ->add('decline', SubmitType::class, array(
                'label' => "Opt out",
                'attr' => ['class' => 'btn btn-danger'],
            ))
            ->getForm();

        $email = null;
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $hash = urlencode(base64_encode($form->getData()['email']));

            return new RedirectResponse($this->generateUrl('optout_hash', ['hash' => $hash]));
        }

        return array(
            'form_optout' => $form->createView(),
        );
    }

    /**
     * @Route("/optout/{hash}", name="optout_hash")
     * @Template()
     */
    public function optOutHashAction(Request $request, $hash)
    {
        $form = $this->createFormBuilder()
            ->add('add', SubmitType::class)
            ->getForm();


        if (!$hash) {
            return new RedirectResponse($this->generateUrl('optout'));
        }

        $email = base64_decode(urldecode($hash));
        $invitationService = $this->get('app.invitation');

        $cat = $request->get('cat');
        if (!$cat) {
            $cat = EmailOptOut::OPTOUT_CAT_ALL;
        }
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $invitationService->optin($email, $cat);
        } else {
            $invitationService->optout($email, $cat);
            $invitationService->rejectAllInvitations($email);
        }

        return array(
            'category' => $cat,
            'email' => $email,
            'form_optin' => $form->createView(),
            'is_opted_out' => $invitationService->isOptedOut($email, $cat),
        );
    }

    /**
     * @Route("/accountkit-login", name="accountkit_login")
     */
    public function accountKitLoginAction(Request $request)
    {
        $facebook = $this->get('app.facebook');
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);

        $csrf = $request->get('csrf');
        $authorizationCode = $request->get('code');

        if (!$this->isCsrfTokenValid('account-kit', $request->get('csrf'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $mobileNumber = $facebook->getAccountKitMobileNumber($authorizationCode);
        $user = $repo->findOneBy(['mobileNumber' => $mobileNumber]);
        if (!$user) {
            $this->addFlash(
                'error',
                "Sorry, we can't seem to find your user account. Please contact us if you need help."
            );

            return new RedirectResponse($this->generateUrl('fos_user_security_login'));
        }

        $this->get('fos_user.security.login_manager')->loginUser(
            $this->getParameter('fos_user.firewall_name'),
            $user
        );

        return new RedirectResponse($this->generateUrl('user_home'));
    }
}
