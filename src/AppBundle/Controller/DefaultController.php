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
use AppBundle\Form\Type\ClaimFnolType;

use AppBundle\Document\Form\Register;
use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\Form\ClaimFnol;
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
     */
    public function indexAction(Request $request)
    {
        if ($this->isRealUSAIp($request) && $request->get('site') != 'uk') {
            return $this->redirectToRoute('launch_usa');
        }

        $referral = $request->get('referral');
        if ($referral) {
            $session = $this->get('session');
            $session->set('referral', $referral);
            $this->get('logger')->debug(sprintf('Referral %s', $referral));
        }

        $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_HOMEPAGE_AA_V2,
            ['A1', 'A2'],
            SixpackService::LOG_MIXPANEL_CONVERSION
        );

        $exp = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_HOMEPAGE_STICKYSEARCH_PICSURE,
            ['v2', 'sticky-search', 'picsure'],
            SixpackService::LOG_MIXPANEL_ALL // keep consistent with running test; change for future
        );

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE);

        $data = array(
            // Make sure to check homepage landing below too
            'select_phone_type'   => $exp == 'sticky-search' ? 'homepage-sticky' : 'homepage',
            'pic_sure'        => $exp == 'picsure',
            'referral'        => $referral,
            'phone'           => $this->getQuerystringPhone($request),
        );

        // return $this->render('AppBundle:Default:index.html.twig', $data);
        return $this->render('AppBundle:Default:indexContentShuffle.html.twig', $data);
    }


    /**
     * @Route("/reimagined", name="reimagined")
     * @Route("/hasslefree", name="hasslefree")
     * @Template
     */
    public function homepageLanding(Request $request)
    {

        if ($request->get('_route') == "reimagined") {
            $data = array(
                'select_phone_type' => 'homepage',
                'main'              => 'Mobile Insurance',
                'main_cont'         => 'Re-Imagined',
                'sub'               => 'Quicker. Easier. Jargon Free.',
                // 'sub_cont'  => '',
            );
        } elseif ($request->get('_route') == "hasslefree") {
            $data = array(
                'select_phone_type' => 'homepage',
                'main'              => 'Hassle Free',
                'main_cont'         => 'Mobile Insurance',
                'sub'               => 'We dont give you the run around when you claim.',
                // 'sub_cont'  => '',
            );
        }

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
            'Page' => $request->get('_route'),
        ]);

        return $this->render('AppBundle:Default:index.html.twig', $data);
    }


    /**
     * @Route("/select-phone", name="select_phone_make")
     * @Route("/select-phone/{type}/{id}", name="select_phone_make_type_id")
     * @Route("/select-phone/{type}", name="select_phone_make_type")
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

        // throw new \Exception($id);

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            $session = $request->getSession();
            $session->set('quote', $phone->getId());

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_policy');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            $session = $request->getSession();
            $session->set('quote', $phone->getId());

           // return $this->redirectToRoute('learn_more_phone', ['id' => $id]);
        }

        return [
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/select-phone-v2", name="select_phone_make_v2")
     * @Route("/select-phone-v2/{type}", name="select_phone_make_v2_type")
     * @Route("/select-phone-v2/{type}/{id}", name="select_phone_make_v2_type_id")
     * @Template()
     */
    public function selectPhoneMakeV2Action(Request $request, $type = null, $id = null)
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

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_policy');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            $session = $request->getSession();
            $session->set('quote', $phone->getId());

           // return $this->redirectToRoute('learn_more_phone', ['id' => $id]);
        }

        return [
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/search-phone", name="search_phone_data")
     * @Route("/search-phone-combined", name="search_phone_combined_data")
     */
    public function searchPhoneAction(Request $request)
    {
        $type = 'simple';
        if ($request->get('_route') == 'search_phone_combined_data') {
            $type = 'highlight';
        }

        return new JsonResponse(
            $this->getPhonesSearchArray($type)
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
                    'less than 5' => 'less than 5',
                    '5-10' => '5-10',
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
     * @Route("/claim/{policyId}", name="claim_policy")
     * @Template
     */
    public function claimAction(Request $request, $policyId = null)
    {
        $user = $this->getUser();
        $claimFnol = new ClaimFnol();

        if ($policyId) {
            $repo = $this->getManager()->getRepository(Policy::class);
            $policy = $repo->find($policyId);
            $claimFnol->setPolicy($policy);
        } elseif ($user) {
            $claimFnol->setUser($user);
        }

        $claimForm = $this->get('form.factory')
            ->createNamedBuilder('claim_form', ClaimFnolType::class, $claimFnol)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claim_form')) {
                $claimForm->handleRequest($request);
                if ($claimForm->isValid()) {
                    $mailer = $this->get('app.mailer');
                    $subject = sprintf(
                        'New Claim from %s/%s',
                        $claimFnol->getName(),
                        $claimFnol->getPolicyNumber()
                    );
                    $mailer->sendTemplate(
                        $subject,
                        'new-claim@wearesosure.com',
                        'AppBundle:Email:claim/fnolToClaims.html.twig',
                        ['data' => $claimFnol]
                    );

                    $mailer->sendTemplate(
                        'Your claim with so-sure',
                        $claimFnol->getEmail(),
                        'AppBundle:Email:claim/fnolResponse.html.twig',
                        ['data' => $claimFnol],
                        'AppBundle:Email:claim/fnolResponse.txt.twig',
                        ['data' => $claimFnol]
                    );

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
     * @Route("/so-sure-vs-gadget-cover-phone-insurance", name="so-sure-vs-gadget_cover_phone_insurance")
     * @Template
     */
    public function soSureVsGadgetCover()
    {

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_CPC_COMPETITOR_PAGE, [
            'Competitor' => 'Gadget Cover',
        ]);

        return array();
    }

    /**
     * @Route("/so-sure-vs-halifax-phone-insurance", name="so-sure-vs-halifax_phone_insurance")
     * @Template
     */
    public function soSureVsHalifaxCover()
    {

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_CPC_COMPETITOR_PAGE, [
            'Competitor' => 'Halifax',
        ]);

        return array();
    }

    /**
     * @Route("/so-sure-vs-three-phone-insurance", name="so-sure-vs-three_phone_insurance")
     * @Template
     */
    public function soSureVsThree()
    {

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_CPC_COMPETITOR_PAGE, [
            'Competitor' => 'Three',
        ]);

        return array();
    }

    /**
     * @Route("/so-sure-vs-protect-your-bubble", name="so_sure_vs_protect_your_bubble")
     * @Route("/so-sure-vs-protect-your-bubble-phone-insurance", name="so_sure_vs_protect_your_bubble_phone_insurance")
     * @Route("/so-sure-vs-carphone-warehouse-phone-insurance", name="so_sure_vs_carphone_warehouse_phone_insurance")
     * @Route("/so-sure-vs-ee-damage-cover-insurance", name="so_sure_vs_ee_damage_cover_insurance")
     * @Route("/so-sure-vs-tesco-phone-insurance", name="so_sure_vs_tesco_phone_insurance")
     * @Template
     */
    public function soSureVsCompetitor(Request $request)
    {
        /*
        $exp = $this->get('app.sixpack')->participate(
            SixpackService::EXPERIMENT_PYG_HOME,
            ['pyg', 'home'],
            SixpackService::LOG_MIXPANEL_NONE
        );
        */
        //if ($exp == 'home') {
        return new RedirectResponse($this->generateUrl('homepage'));
        //}

        if ($request->get('_route') == "so_sure_vs_protect_your_bubble_phone_insurance" ||
            $request->get('_route') == "so_sure_vs_protect_your_bubble") {
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
        } elseif ($request->get('_route') == "so_sure_vs_carphone_warehouse_phone_insurance") {
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
        } elseif ($request->get('_route') == "so_sure_vs_ee_damage_cover_insurance") {
            $data = [
                'c_name' => 'EE Damage Cover',
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
                'c_loss' => 'No',
                'c_loss_bg' => 'cross-background',
                'c_theft_replacement' => 'Theft: Next working day Loss: N/A',
                'c_damage_replacement' => 'Next working day',
                'c_used_phones' => 'Only new phones bought from EE',
                'c_used_phones_bg' => 'cross-background',
                'c_cashback' => 'No',
                'c_cashback_bg' => 'cross-background',
            ];
        } elseif ($request->get('_route') == "so_sure_vs_tesco_phone_insurance") {
            $data = [
                'c_name' => 'Tesco Phone',
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
                'c_theft_replacement' => '7-10 working days',
                'c_damage_replacement' => '7-10 working days',
                'c_used_phones' => 'Only new phones bought from Tesco',
                'c_used_phones_bg' => 'cross-background',
                'c_cashback' => 'No',
                'c_cashback_bg' => 'cross-background',
            ];
        }

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_CPC_COMPETITOR_PAGE, [
            'Competitor' => $data['c_name'],
        ]);

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

    /**
     * @Route("/iphone8", name="iphone8_redirect")
     */
    public function iPhone8RedirectAction()
    {
        return new RedirectResponse($this->generateUrl('quote_make_model', [
            'make' => 'Apple',
            'model' => 'iPhone+8',
            'utm_medium' => 'flyer',
            'utm_source' => 'sosure',
            'utm_campaign' => 'iPhone8',
        ]));
    }

    /**
     * @Route("/trinitiymaxwell", name="trinitiymaxwell_redirect")
     */
    public function tmAction()
    {
        return new RedirectResponse($this->generateUrl('homepage', [
            'utm_medium' => 'flyer',
            'utm_source' => 'sosure',
            'utm_campaign' => 'trinitiymaxwell',
        ]));
    }
}
