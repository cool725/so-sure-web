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

        $force = null;
        $trafficFraction = '0.0000001';
        if ($request->get('_route') == 'replacement_24_landing') {
            $force = 'next-working-day';
            $trafficFraction = 1;
        } elseif ($request->get('_route') == 'replacement_72_landing') {
            $force = 'twentyfour-seventy-two';
            $trafficFraction = 1;
        }

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE);

        // Valentines Day Promo
        $now   = \DateTime::createFromFormat('U', time());
        $start = new \DateTime('2019-02-14 00:00:00', SoSure::getSoSureTimezone());
        $end   = new \DateTime('2019-02-14 23:59:59', SoSure::getSoSureTimezone());

        if ($now >= $start && $now <= $end) {
            return $this->redirectToRoute('valentines_day_free_phone_case');
        }

        $data = array(
            // Make sure to check homepage landing below too
            'referral'  => $referral,
            'phone'     => $this->getQuerystringPhone($request),
        );

        $template = 'AppBundle:Default:index.html.twig';

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
     * @Route("/money", name="money")
     */
    public function moneyLanding()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'money']);

        return $this->render('AppBundle:Default:indexMoney.html.twig');
    }

    /**
     * @Route("/starling-bank", name="starling_bank")
     * @Template
     */
    public function starlingLanding(Request $request)
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'starling']);

        $this->starlingOAuthSession($request);

        return $this->render('AppBundle:Default:indexStarlingBank.html.twig');
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
     * @Route("/topcashback", name="topcashback")
     * @Route("/vouchercodes", name="vouchercodes")
     * @Route("/quidco", name="quidco")
     * @Route("/ivip", name="ivip")
     * @Route("/reward-gateway", name="reward_gateway")
     */
    public function affiliateLanding(Request $request)
    {
        $page = null;
        $affiliate = null;

        if ($request->get('_route') == 'topcashback') {
            $page = 'topcashback';
            $affiliate = 'TopCashback';
        } elseif ($request->get('_route') == 'vouchercodes') {
            $page = 'vouchercodes';
            $affiliate = 'VoucherCodes';
        } elseif ($request->get('_route') == 'quidco') {
            $page = 'quidco';
            $affiliate = 'Quidco';
        } elseif ($request->get('_route') == 'ivip') {
            $page = 'ivip';
            $affiliate = 'iVIP';
        } elseif ($request->get('_route') == 'reward_gateway') {
            $page = 'reward-gateway';
            $affiliate = 'Reward Gateway';
        }

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, [
            'page' => $page
        ]);

        $data = [
            'affiliate_company' => $affiliate,
            'affiliate_page' => $page,
        ];

        return $this->render('AppBundle:Default:indexAffiliate.html.twig', $data);
    }

    /**
     * @Route("/eb", name="eb")
     * @Template
     */
    public function ebLanding(Request $request)
    {
        $exp = $this->sixpackSimple(SixpackService::EXPERIMENT_EBAY_LANDING, $request);

        if ($exp === 'ebay-landing') {
            return $this->render('AppBundle:Default:indexEbay.html.twig');
        }

        return $this->redirectToRoute('homepage');
    }

    /**
     * @Route("/eb1", name="eb1")
     * @Template
     */
    public function eb1Landing(Request $request)
    {
        $data = [
            'main_title' => 'Honest Insurance for Honest People',
            'hero_class' => 'ebay__hero_1',
        ];

        $exp = $this->sixpackSimple(SixpackService::EXPERIMENT_EBAY_LANDING_1, $request);

        if ($exp === 'ebay-landing') {
            return $this->render('AppBundle:Default:indexEbay.html.twig', $data);
        }

        return $this->redirectToRoute('homepage');
    }

    /**
     * @Route("/eb2", name="eb2")
     * @Template
     */
    public function eb2Landing(Request $request)
    {

        $data = [
            'main_title' => 'Insurance You Deserve',
            'hero_class' => 'ebay__hero_2',
        ];

        $exp = $this->sixpack(
            $request,
            SixpackService::EXPERIMENT_EBAY_LANDING_2,
            ['homepage', 'ebay-landing-2']
        );

        if ($exp == 'ebay-landing') {
            return $this->render('AppBundle:Default:indexEbay.html.twig', $data);
        } else {
            return $this->redirectToRoute('homepage');
        }
    }

    /**
     * @Route("/comparison", name="comparison")
     * @Template
     */
    public function soSureCompetitors()
    {
        $data = [
            'headline'     => 'Mobile Insurance Beyond Compare',
            'sub_heading'  => 'But if you do want to compare…',
            'sub_heading2' => 'here’s how we stack up against the competition',
        ];

        return $this->render('AppBundle:Default:indexCompetitor.html.twig', $data);
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
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
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

                    return $this->redirectToRoute('mobile_phone_insurance_for_your_company_thanks');
                } else {
                    $this->addFlash(
                        'error',
                        "Sorry, there was a problem validating your request. Please check below for any errors."
                    );
                }
            }
        }

        return [
            'lead_form' => $leadForm->createView(),
        ];
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
                $this->addFlash(
                    'error',
                    "Oops, looks like we already sent you a link."
                );
            } elseif (!$this->isValidUkMobile($ukMobileNumber)) {
                $this->addFlash('error', sprintf(
                    'Sorry, that number does not appear to be a valid UK Mobile Number'
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
                    $this->addFlash(
                        'success',
                        'You should receive a download link shortly'
                    );
                } else {
                    $this->addFlash(
                        'error',
                        'Sorry, we had a problem sending you a sms. Please download the so-sure app from your app store.'
                    );
                }
            }
        }

        return array(
            'form' => $form->createView(),
        );
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

        $data = null;
        if ($request->get('_route') == "so_sure_vs_protect_your_bubble_phone_insurance" ||
            $request->get('_route') == "so_sure_vs_protect_your_bubble") {
            $data = [
                'c_name' => 'Protect Your Bubble',
                's_theft' => 'Yes',
                's_theft_bg' => 'tick-background',
                's_loss' => 'As standard',
                's_loss_bg' => 'tick-background',
                's_theft_replacement' => '24-72 hours once claim approved',
                's_damage_replacement' => '24-72 hours once claim approved',
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
                's_theft_replacement' => '24-72 hours once claim approved',
                's_damage_replacement' => '24-72 hours once claim approved',
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
                's_theft_replacement' => '24-72 hours once claim approved',
                's_damage_replacement' => '24-72 hours once claim approved',
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
                's_theft_replacement' => '24-72 hours once claim approved',
                's_damage_replacement' => '24-72 hours once claim approved',
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
        $phoneName = null;
        $phonePrice = null;
        $quoteRoute = null;
        if ($request->get('_route') == "samsung_s7_insured_with_vodafone") {
            $phoneName = "Samsung S7";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'samsung',
                'model' => 'galaxy+s7',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "google_pixel_insured_with_vodafone") {
            $phoneName = "Google Pixel";
            $phonePrice = "8.49";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'google',
                'model' => 'pixel',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_SE_insured_with_vodafone") {
            $phoneName = "iPhone SE";
            $phonePrice = "6.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'apple',
                'model' => 'iphone+se',
                'memory' => '16'
            ]);
        } elseif ($request->get('_route') == "iphone_7_plus_insured_with_vodafone") {
            $phoneName = "iPhone 7 Plus";
            $phonePrice = "9.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'apple',
                'model' => 'iphone+7+plus',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_7_insured_with_vodafone") {
            $phoneName = "iPhone 7";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'apple',
                'model' => 'iphone+7',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_6s_insured_with_vodafone") {
            $phoneName = "iPhone 6S";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'apple',
                'model' => 'iphone+6s',
                'memory' => '32'
            ]);
        } elseif ($request->get('_route') == "iphone_6_insured_with_vodafone") {
            $phoneName = "iPhone 6";
            $phonePrice = "7.99";
            $quoteRoute = $this->generateUrl('quote_make_model_memory', [
                'make' => 'apple',
                'model' => 'iphone+6',
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
        $phone = null;
        $phoneName = null;
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
                'AppBundle:Email:optOutLink.html.twig',
                ['hash' => $hash],
                'AppBundle:Email:optOutLink.txt.twig',
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
