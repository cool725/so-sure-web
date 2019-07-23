<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;
use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Form\Type\CompanyLeadType;
use AppBundle\Form\Type\EmailOptInType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Repository\OptOut\EmailOptOutRepository;
use AppBundle\Service\InvitationService;
use AppBundle\Service\MailerService;
use AppBundle\Service\RateLimitService;
use AppBundle\Service\RequestService;
use AppBundle\Service\ClaimsService;
use PHPStan\Rules\Arrays\AppendedArrayItemTypeRule;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
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
use AppBundle\Form\Type\PhoneDropdownType;
use AppBundle\Form\Type\SmsAppLinkType;
use AppBundle\Form\Type\ClaimFnolEmailType;
use AppBundle\Form\Type\ClaimFnolType;

use AppBundle\Document\Form\Register;
use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\Form\PhoneDropdown;
use AppBundle\Document\Form\ClaimFnol;
use AppBundle\Document\Form\ClaimFnolEmail;
use AppBundle\Document\User;
use AppBundle\Document\Claim;
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

class DefaultController extends BaseController
{
    use PhoneTrait;
    use \Symfony\Component\Security\Http\Util\TargetPathTrait;

    /**
     * @Route("/", name="homepage", options={"sitemap"={"priority":"1.0","changefreq":"daily"}})
     * @Route("/replacement-24", name="replacement_24_landing")
     * @Route("/replacement-72", name="replacement_72_landing")
     */
    public function indexAction(Request $request)
    {
        $referral = $request->get('referral');
        if ($referral) {
            $session = $this->get('session');
            $session->set('referral', $referral);
            $this->get('logger')->debug(sprintf('Referral %s', $referral));
        }

        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE);

        $template = 'AppBundle:Default:index.html.twig';

        // A/B Homepage USPS test
        // $exp = $this->sixpack(
        //     $request,
        //     SixpackService::EXPERIMENT_HOMEPAGE_USPS,
        //     ['homepage', 'homepage-usps']
        // );

        $data = array(
            // Make sure to check homepage landing below too
            'referral'  => $referral,
            'phone'     => $this->getQuerystringPhone($request),
            // 'exp'       => $exp,
        );

        return $this->render($template, $data);
    }

    /**
     * @Route("/free-taste-card", name="free_taste_card")
     */
    public function freeTasteCard()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'tastecard']);

        $pageType = 'tastecard';

        $data = array(
            'page_type' => $pageType,
        );


        $template = 'AppBundle:Default:indexPromotions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/free-phone-case", name="free_phone_case")
     * @Route("/case", name="case")
     */
    public function freePhoneCase()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'freephonecase']);

        $pageType = 'phonecase';

        $data = array(
            'page_type' => $pageType,
        );

        $template = 'AppBundle:Default:indexPromotions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/valentines-day-free-phone-case", name="valentines_day_free_phone_case")
     */
    public function valentinesDayCase()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'valentinesdayfreephonecase'
        ]);

        $pageType = 'vdayphonecase';

        $data = array(
            'page_type' => $pageType,
        );

        $template = 'AppBundle:Default:indexPromotions.html.twig';

        return $this->render($template, $data);
    }

    /**
     * @Route("/social-insurance", name="social_insurance", options={"sitemap"={"priority":"0.5","changefreq":"daily"}})
     */
    public function socialInsurance()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'social-insurance']);
        return $this->render('AppBundle:Default:socialInsurance.html.twig');
    }

    /**
     * @Route("/snapchat", name="snapchat")
     */
    public function snapchatLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'snapchat'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexSnapchat.html.twig', $data);
    }

    /**
     * @Route("/snapchat-b", name="snapchat-b")
     */
    public function snapchatbLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'snapchat-b'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexSnapchatB.html.twig', $data);
    }

    /**
     * @Route("/twitter", name="twitter")
     */
    public function twitterLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'twitter'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexTwitter.html.twig', $data);
    }

    /**
     * @Route("/facebook", name="facebook")
     */
    public function facebookLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'facebook'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexFacebook.html.twig', $data);
    }

    /**
     * @Route("/youtube", name="youtube")
     */
    public function youtubeLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => 'youtube'
        ]);

        $data = [
            'competitor' => $this->competitorsData(),
            'competitor1' => 'PYB',
            'competitor2' => 'GC',
            'competitor3' => 'LICI',
        ];

        return $this->render('AppBundle:Default:indexYoutube.html.twig', $data);
    }

    /**
     * @Route("/terms-test", name="terms_test")
     */
    public function termsTest()
    {
        return $this->render('AppBundle:Pdf:policyTermsV13.html.twig');
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
            ]
        ];

        return $competitor;
    }

    /**
     * @Route("/topcashback", name="topcashback")
     * @Route("/vouchercodes", name="vouchercodes")
     * @Route("/quidco", name="quidco")
     * @Route("/ivip", name="ivip")
     * @Route("/reward-gateway", name="reward_gateway")
     * @Route("/money", name="money")
     * @Route("/money-free-phone-case", name="money_free_phone_case")
     * @Route("/starling-bank", name="starling_bank")
     * @Route("/comparison", name="comparison")
     * @Route("/vendi-app", name="vendi_app")
     * @Route("/so-sure-compared", name="so_sure_compared")
     * @Route("/moneyback", name="moneyback")
     */
    public function affiliateLanding(Request $request)
    {
        $data = [
            'competitor' => $this->competitorsData(),
        ];

        $template = 'AppBundle:Default:indexAffiliate.html.twig';

        if ($request->get('_route') == 'topcashback') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'topcashback',
                'affiliate_company' => 'TopCashback',
                'affiliate_company_logo' => 'so-sure_topcashback_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'SS',
            ];
        } elseif ($request->get('_route') == 'vouchercodes') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'vouchercodes',
                'affiliate_company' => 'VoucherCodes',
                'affiliate_company_logo' => 'so-sure_vouchercodes_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'END',
            ];
        } elseif ($request->get('_route') == 'quidco') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'quidco',
                'affiliate_company' => 'Quidco',
                'affiliate_company_logo' => 'so-sure_quidco_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'CC',
            ];
        } elseif ($request->get('_route') == 'ivip') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'ivip',
                'affiliate_company' => 'iVIP',
                'affiliate_company_logo' => 'so-sure_ivip_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
        } elseif ($request->get('_route') == 'reward_gateway') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'reward-gateway',
                'affiliate_company' => 'Reward Gateway',
                'competitor1' => 'PYB',
                'competitor2' => 'END',
                'competitor3' => 'SS',
            ];
        } elseif ($request->get('_route') == 'money') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'money',
                'affiliate_company' => 'money',
                'affiliate_company_logo' => 'so-sure_money_logo.png',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
        } elseif ($request->get('_route') == 'money_free_phone_case') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'money-free-phone-case',
                'affiliate_company' => 'money',
                'affiliate_company_logo' => 'so-sure_money_logo.png',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
        } elseif ($request->get('_route') == 'starling_bank') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'starling-bank',
                // 'affiliate_company' => 'Starling Bank',
                // 'affiliate_company_logo' => 'so-sure_money_logo.png',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
            $template = 'AppBundle:Default:indexStarlingBank.html.twig';
            $this->starlingOAuthSession($request);
        } elseif ($request->get('_route') == 'comparison') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'comparison',
                'titleH1' => 'Mobile Insurance beyond compare',
                'leadP' => 'But if you do want to compare... <br> here\'s how we stack up against the competition 🤔',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
        } elseif ($request->get('_route') == 'vendi_app') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'vendi-app',
                'affiliate_company' => 'Vendi',
                'affiliate_company_logo' => 'so-sure_vendi_logo.svg',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
        } elseif ($request->get('_route') == 'so_sure_compared') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'so-sure-compared',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
        } elseif ($request->get('_route') == 'moneyback') {
            $data = [
                'competitor' => $this->competitorsData(),
                'affiliate_page' => 'moneyback',
                'competitor1' => 'PYB',
                'competitor2' => 'GC',
                'competitor3' => 'LICI',
            ];
        }

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => $data['affiliate_page']]);

        return $this->render($template, $data);
    }

    /**
     * @Route("/reimagined", name="reimagined")
     * @Route("/hasslefree", name="hasslefree")
     * @Template
     */
    public function homepageLanding()
    {
        // $data = [];
        // if ($request->get('_route') == "reimagined") {
        //     $data = array(
        //         'main'              => 'Mobile Insurance',
        //         'main_cont'         => 'Re-Imagined',
        //         'sub'               => 'Quicker. Easier. Jargon Free.',
        //         // 'sub_cont'  => '',
        //     );
        // } elseif ($request->get('_route') == "hasslefree") {
        //     $data = array(
        //         'main'              => 'Hassle Free',
        //         'main_cont'         => 'Mobile Insurance',
        //         'sub'               => 'We dont give you the run around when you claim.',
        //         // 'sub_cont'  => '',
        //     );
        // }

        // $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
        //     'Page' => $request->get('_route'),
        // ]);

        return $this->render('AppBundle:Default:index.html.twig');
    }


    /**
     * @Route("/select-phone-dropdown", name="select_phone_make_dropdown")
     * @Route("/select-phone-dropdown/{type}/{id}", name="select_phone_make_dropdown_type_id")
     * @Route("/select-phone-dropdown/{type}", name="select_phone_make_dropdown_type")
     * @Template()
     */
    public function selectPhoneMakeDropdownAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $phoneMake = new PhoneMake();
        if ($id) {
            $phone = $phoneRepo->find($id);
            if (!$phone) {
                throw $this->createNotFoundException('Invalid id');
            }

            $phoneMake->setMake($phone->getMake());
        }

        // throw new \Exception($id);

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_phone');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('select_phone_make_dropdown'),
            ])
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'memory');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw $this->createNotFoundException('Invalid id');
                    }
                    if ($phone->getMemory()) {
                        return $this->redirectToRoute('quote_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory(),
                        ]);
                    } else {
                        return $this->redirectToRoute('quote_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                        ]);
                    }
                }
            }
        }

        // throw new \Exception(print_r($this->getPhonesArray(), true));

        return [
            'form_phone' => $formPhone->createView(),
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/phone-dropdown", name="phone_make_dropdown")
     * @Route("/phone-dropdown/{type}/{id}", name="phone_make_dropdown_type_id")
     * @Route("/phone-dropdown/{type}", name="phone_make_dropdown_type")
     * @Template()
     */
    public function phoneMakeDropdownNewAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $phoneMake = new PhoneDropdown();
        if ($id) {
            $phone = $phoneRepo->find($id);
            $phoneMake->setMake($phone->getMake());
        }

        // throw new \Exception($id);

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_phone');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneDropdownType::class, $phoneMake, [
                'action' => $this->generateUrl('phone_make_dropdown'),
            ])
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'memory');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw new \Exception('unknown phone');
                    }
                    if ($phone->getMemory()) {
                        return $this->redirectToRoute('quote_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory(),
                        ]);
                    } else {
                        return $this->redirectToRoute('quote_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                        ]);
                    }
                }
            }
        }

        // throw new \Exception(print_r($this->getPhonesArray(), true));

        return [
            'form_phone' => $formPhone->createView(),
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/select-phone-search", name="select_phone_make_search")
     * @Route("/select-phone-search/{type}", name="select_phone_make_search_type")
     * @Route("/select-phone-search/{type}/{id}", name="select_phone_make_search_type_id")
     * @Template()
     */
    public function selectPhoneMakeSearchAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        if ($id) {
            $phone = $phoneRepo->find($id);
        }

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_phone');
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
            if ($this->isGranted(User::ROLE_EMPLOYEE)) {
                return $this->redirectToRoute('admin_home');
            } elseif ($this->isGranted('ROLE_CLAIMS')) {
                return $this->redirectToRoute('claims_policies');
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
     * @Route("/faq", name="faq")
     * @Template
     */
    public function faqAction(Request $request)
    {
        $intercomEnabled = true;
        $hideCookieWarning = false;
        $hideNav = false;
        $hideFooter = false;

        $isSoSureApp = false;
        $session = $request->getSession();
        if ($session) {
            if ($session->get('sosure-app') == "1") {
                $isSoSureApp = true;
            }
            if ($request->headers->get('X-SOSURE-APP') == "1" || $request->get('X-SOSURE-APP') == "1") {
                $session->set('sosure-app', 1);
                $isSoSureApp = true;
            }
        }

        if ($isSoSureApp) {
            $intercomEnabled = false;
            $hideCookieWarning = true;
            $hideNav = true;
            $hideFooter = true;
        }

        $data = [
            'intercom_enabled' => $intercomEnabled,
            'hide_cookie_warning' => $hideCookieWarning,
            'hide_nav' => $hideNav,
            'hide_footer' => $hideFooter,
        ];
        return $this->render('AppBundle:Default:faq.html.twig', $data);
    }

    /**
     * @Route("/company-phone-insurance",
     *  name="company_phone_insurance",
     *  options={"sitemap"={"priority":"1.0","changefreq":"daily"}})
     * @Route("/company-phone-insurance/thank-you",
     *  name="company_phone_insurance_thanks")
     */
    public function companyAction(Request $request)
    {
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', CompanyLeadType::class)
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

                    /** @var MailerService $mailer */
                    $mailer = $this->get('app.mailer');
                    $mailer->send(
                        'Company inquiry',
                        'sales@so-sure.com',
                        $body
                    );

                    $this->addFlash(
                        'success',
                        "Thanks. We'll be in touch shortly"
                    );

                    return $this->redirectToRoute('company_phone_insurance_thanks');
                } else {
                    $this->addFlash(
                        'error',
                        "Sorry, there was a problem validating your request. Please check below for any errors."
                    );
                }
            }
        }

        $data = [
            'lead_form' => $leadForm->createView(),
        ];

        return $this->render('AppBundle:Default:indexCompany.html.twig', $data);
    }

    /**
     * @Route("/claim", name="claim")
     * @Route("/claim/login", name="claim_login")
     */
    public function claimAction(Request $request)
    {
        /** @var User $user */
        $user = $this->getUser();

        // causes admin's (or claims) too much confusion to be redirected to a 404
        if ($user && !$user->hasEmployeeRole() && !$user->hasClaimsRole()
            && ($user->hasActivePolicy() || $user->hasUnpaidPolicy())) {
            return $this->redirectToRoute('user_claim');
        }

        $claimFnolEmail = new ClaimFnolEmail();

        $claimEmailForm = $this->get('form.factory')
            ->createNamedBuilder('claim_email_form', ClaimFnolEmailType::class, $claimFnolEmail)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('claim_email_form')) {
                $claimEmailForm->handleRequest($request);
                if ($claimEmailForm->isValid()) {
                    $repo = $this->getManager()->getRepository(User::class);
                    $user = $repo->findOneBy(['emailCanonical' => mb_strtolower($claimFnolEmail->getEmail())]);

                    if ($user) {
                        /** @var ClaimsService $claimsService */
                        $claimsService = $this->get('app.claims');
                        $claimsService->sendUniqueLoginLink($user, $request->get('_route') == 'claim_login');
                    }

                    // @codingStandardsIgnoreStart
                    $message = $request->get('_route') == 'claim_login' ? "Thank you. For our policy holders, an email with further instructions on how to proceed with updating your claim has been sent to you. If you do not receive the email shortly, please check your spam folders and also verify that the email address matches your policy." : "Thank you. For our policy holders, an email with further instructions on how to proceed with your claim has been sent to you. If you do not receive the email shortly, please check your spam folders and also verify that the email address matches your policy.";

                    $this->addFlash('success', $message);
                }
            }
        }

        $data = [
            'claim_email_form' => $claimEmailForm->createView(),
        ];

        if ($request->get('_route') == 'claim_login') {
            return $this->render('AppBundle:Default:claimLogin.html.twig', $data);
        }
        return $this->render('AppBundle:Default:claim.html.twig', $data);
    }

    /**
     * @Route("/claim/login/{tokenId}", name="claim_login_token")
     * @Template
     */
    public function claimLoginAction(Request $request, $tokenId = null)
    {
        $user = $this->getUser();

        if ($user) {
            return $this->redirectToRoute('user_claim');
        }

        if ($tokenId) {
            /** @var ClaimsService $claimsService */
            $claimsService = $this->get('app.claims');
            $userId = $claimsService->getUserIdFromLoginLinkToken($tokenId);
            if (!$userId) {
                // @codingStandardsIgnoreStart
                $this->addFlash(
                    'error',
                    "Sorry, it looks like your link as expired. Please re-enter the email address you have created your policy under and try again."
                );
                return $this->redirectToRoute('claim');
            }

            $dm = $this->getManager();
            $userRepo = $dm->getRepository(User::class);
            $user = $userRepo->find($userId);

            if ($user) {
                if ($user->isLocked() || !$user->isEnabled()) {
                    // @codingStandardsIgnoreStart
                    $this->addFlash(
                        'error',
                        "Sorry, it looks like your user account is locked or expired. Please email support@wearesosure.com"
                    );

                    return $this->redirectToRoute('claim');
                }

                $this->get('fos_user.security.login_manager')->loginUser(
                    $this->getParameter('fos_user.firewall_name'),
                    $user
                );

                return $this->redirectToRoute('user_claim');
            }
        }

        throw $this->createNotFoundException('Invalid link');
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
     * @Route("/price/{id}", name="price_item")
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
     * @Route("/optout", name="optout_old")
     * @Route("/communications", name="optout")
     * @Template()
     */
    public function optOutAction(Request $request)
    {
        if ($this->getUser()) {
            $hash = SoSure::encodeCommunicationsHash($this->getUser()->getEmail());

            return new RedirectResponse($this->generateUrl('optout_hash', ['hash' => $hash]));
        }

        $form = $this->createFormBuilder()
            ->add('email', EmailType::class, [
                'data' => $request->get('email')
            ])
            ->add('decline', SubmitType::class)
            ->getForm();

        $email = null;
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $rateLimit = $this->get('app.ratelimit');
            if (!$rateLimit->allowedByIp(
                RateLimitService::DEVICE_TYPE_OPT,
                $request->getClientIp()
            )) {
                $this->addFlash(
                    'error',
                    'Too many requests! Please try again later'
                );

                return new RedirectResponse($this->generateUrl('homepage'));
            }

            $email = $form->getData()['email'];
            $hash = SoSure::encodeCommunicationsHash($email);

            /** @var MailerService $mailer */
            $mailer = $this->get('app.mailer');
            $mailer->sendTemplate(
                'Update your communication preferences',
                $email,
                'AppBundle:Email:user/optOutLink.html.twig',
                ['hash' => $hash],
                'AppBundle:Email:user/optOutLink.txt.twig',
                ['hash' => $hash]
            );

            $this->addFlash(
                'success',
                'Thanks! You should receive an email shortly.'
            );

            return new RedirectResponse($this->generateUrl('optout'));
        }

        return array(
            'form_optout' => $form->createView(),
        );
    }

    /**
     * @Route("/optout/{hash}", name="optout_hash_old")
     * @Route("/communications/{hash}", name="optout_hash")
     * @Template()
     */
    public function optOutHashAction(Request $request, $hash)
    {
        if (!$hash) {
            return new RedirectResponse($this->generateUrl('optout'));
        }
        $rateLimit = $this->get('app.ratelimit');
        if (!$rateLimit->allowedByIp(
            RateLimitService::DEVICE_TYPE_OPT,
            $request->getClientIp()
        )) {
            $this->addFlash(
                'error',
                'Too many requests! Please try again later'
            );

            return new RedirectResponse($this->generateUrl('homepage'));
        }

        $email = SoSure::decodeCommunicationsHash($hash);

        /** @var InvitationService $invitationService */
        $invitationService = $this->get('app.invitation');

        /** @var EmailOptOutRepository $optOutRepo */
        $optOutRepo = $this->getManager()->getRepository(EmailOptOut::class);
        /** @var EmailOptOut $optOut */
        $optOut = $optOutRepo->findOneBy(['email' => mb_strtolower($email)]);
        if (!$optOut) {
            $optOut = new EmailOptOut();
            $optOut->setEmail($email);
        }

        $optInRepo = $this->getManager()->getRepository(EmailOptIn::class);
        $optIn = $optInRepo->findOneBy(['email' => mb_strtolower($email)]);
        if (!$optIn) {
            $optIn = new EmailOptIn();
            $optIn->setEmail($email);
        }

        $optInForm = $this->get('form.factory')
            ->createNamedBuilder('optin_form', EmailOptInType::class, $optIn)
            ->getForm();

        $optOutForm = $this->get('form.factory')
            ->createNamedBuilder('optout_form', EmailOptOutType::class, $optOut)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('optout_form')) {
                $optOutForm->handleRequest($request);
                if ($optOutForm->isSubmitted() && $optOutForm->isValid()) {
                    $optOut->setLocation(EmailOptOut::OPT_LOCATION_PREFERNCES);
                    $optIn->setIdentityLog($this->getIdentityLogWeb($request));
                    if (mb_strtolower($email) != $optOut->getEmail()) {
                        throw new \Exception(sprintf(
                            'Optout hacking attempt %s != %s',
                            $email,
                            $optOut->getEmail()
                        ));
                    }
                    if (in_array(EmailOptOut::OPTOUT_CAT_INVITATIONS, $optOut->getCategories())) {
                        $invitationService->rejectAllInvitations($email);
                    }

                    $this->getManager()->persist($optOut);
                    $this->getManager()->flush();

                    $this->addFlash(
                        'success',
                        'Your preferences have been updated.'
                    );

                    return new RedirectResponse($this->generateUrl('optout_hash', ['hash' => $hash]));
                } else {
                    $this->addFlash(
                        'danger',
                        'Sorry, there was a problem submitting this form. Please contact us.'
                    );
                }
            } elseif ($request->request->has('optin_form')) {
                $optInForm->handleRequest($request);
                if ($optInForm->isSubmitted() && $optInForm->isValid()) {
                    $optIn->setLocation(EmailOptIn::OPT_LOCATION_PREFERNCES);
                    $optIn->setIdentityLog($this->getIdentityLogWeb($request));

                    $this->getManager()->persist($optIn);
                    $this->getManager()->flush();

                    $this->addFlash(
                        'success',
                        'Your preferences have been updated.'
                    );
                    return new RedirectResponse($this->generateUrl('optout_hash', ['hash' => $hash]));
                } else {
                    $this->addFlash(
                        'danger',
                        'Sorry, there was a problem submitting this form. Please contact us.'
                    );
                }
            }
        }

        return array(
            'email' => $email,
            'optin_form' => $optInForm->createView(),
            'optout_form' => $optOutForm->createView(),
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
            'make' => 'apple',
            'model' => 'iphone+8',
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

    /**
     * @Route("/sitemap", name="sitemap")
     * @Template()
     */
    public function sitemapAction()
    {
        $dpn = $this->get('dpn_xml_sitemap.manager');
        $entities = $dpn->getSitemapEntries();
        uasort($entities, function ($a, $b) {
            $dirA = pathinfo($a->getUrl())['dirname'];
            $dirB = pathinfo($b->getUrl())['dirname'];
            if ($dirA != $dirB) {
                return $dirA > $dirB;
            }

            return $a->getUrl() > $b->getUrl();
        });
        return [
            'entities' => $entities,
        ];
    }
}
