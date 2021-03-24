<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Classes\Competitors;
use AppBundle\Document\Opt\EmailOptIn;
use AppBundle\Form\Type\CompanyLeadType;
use AppBundle\Form\Type\EmailOptInType;
use AppBundle\Form\Type\EmailOptOutType;
use AppBundle\Form\Type\MarketingEmailOptOutType;
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
use AppBundle\Document\PhonePrice;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Opt\EmailOptOut;
use AppBundle\Document\Opt\Opt;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Charge;
use AppBundle\Document\Feature;

use AppBundle\Service\MixpanelService;

use AppBundle\Validator\Constraints\UkMobileValidator;

class DefaultController extends BaseController
{
    use PhoneTrait;
    use \Symfony\Component\Security\Http\Util\TargetPathTrait;

    /**
     * @Route("/", name="homepage", options={"sitemap" = true})
     * @Route("/replacement-24", name="replacement_24_landing")
     * @Route("/replacement-72", name="replacement_72_landing")
     * @Route("/reimagined", name="reimagined")
     * @Route("/hasslefree", name="hasslefree")
     */
    public function indexAction(Request $request)
    {
        $noindex = false;
        $referral = $request->get('referral');
        $session = $this->get('session');

        // For Referrals
        if ($referral) {
            $session->set('referral', $referral);
            $this->get('logger')->debug(sprintf('Referral %s', $referral));
        }

        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');

        $template = 'AppBundle:Default:indexQuickQuote.html.twig';

        $competitorData = new Competitors();

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE);

        $data = array(
            'competitor' => $competitorData::$competitorComparisonData,
            'is_noindex' => $noindex
        );

        return $this->render($template, $data);
    }

    /**
     * @Route("/home", name="home")
     */
    public function homeLandingAction(Request $request)
    {
        $referral = $request->get('referral');
        $session = $this->get('session');

        // For Referrals
        if ($referral) {
            $session->set('referral', $referral);
            $this->get('logger')->debug(sprintf('Referral %s', $referral));
        }

        /** @var RequestService $requestService */
        $requestService = $this->get('app.request');

        $template = 'AppBundle:Default:indexQuickQuote.html.twig';

        $competitorData = new Competitors();

        // Is indexed?
        $noindex = true;

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
            'page' => 'Homepage Marketing - LP']);

        $data = array(
            'referral'  => $referral,
            'competitor' => $competitorData::$competitorComparisonData,
            'is_noindex' => $noindex
        );

        return $this->render($template, $data);
    }

    /**
     * @Route("/social-insurance", name="social_insurance", options={"sitemap" = true})
     */
    public function socialInsurance()
    {
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_HOME_PAGE, ['page' => 'social-insurance']);
        return $this->render('AppBundle:Default:socialInsurance.html.twig');
    }

    /**
     * @Route("/terms-test", name="terms_test")
     */
    public function termsTest()
    {
        return $this->render('AppBundle:Pdf:policyTermsV15.html.twig');
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
     * @Route("/starling-business", name="starling_business")
     * @Route("/comparison", name="comparison")
     * @Route("/vendi-app", name="vendi_app")
     * @Route("/so-sure-compared", name="so_sure_compared")
     * @Route("/moneyback", name="moneyback")
     * @Route("/quotezone", name="quotezone")
     * @Route("/getmyslice", name="getmyslice")
     */
    public function affiliateLanding(Request $request)
    {
        $template = 'AppBundle:Default:indexAffiliateOld.html.twig';
        $competitorData = new Competitors();

        $data = [

        ];

        if ($request->get('_route') == 'topcashback') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'topcashback',
                'affiliate_company' => 'TopCashback',
                'affiliate_company_logo' => 'so-sure_topcashback_logo.svg',
            ];
        } elseif ($request->get('_route') == 'vouchercodes') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'vouchercodes',
                'affiliate_company' => 'VoucherCodes',
                'affiliate_company_logo' => 'so-sure_vouchercodes_logo.svg',
            ];
        } elseif ($request->get('_route') == 'quidco') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'quidco',
                'affiliate_company' => 'Quidco',
                'affiliate_company_logo' => 'so-sure_quidco_logo.svg',
            ];
        } elseif ($request->get('_route') == 'ivip') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'ivip',
                'affiliate_company' => 'iVIP',
                'affiliate_company_logo' => 'so-sure_ivip_logo.svg',
            ];
        } elseif ($request->get('_route') == 'reward_gateway') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'reward-gateway',
                'affiliate_company' => 'Reward Gateway',
            ];
        } elseif ($request->get('_route') == 'money') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'money',
                'affiliate_company' => 'money',
                'affiliate_company_logo' => 'so-sure_money_logo_light.svg',
            ];
        } elseif ($request->get('_route') == 'quotezone') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'quotezone',
                'affiliate_company' => 'quotezone',
                'affiliate_company_logo' => 'so-sure_quotezone_logo-spaced.svg',
            ];
        } elseif ($request->get('_route') == 'money_free_phone_case') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'money-free-phone-case',
                'affiliate_company' => 'money',
                'affiliate_company_logo' => 'so-sure_money_logo.png',
            ];
        } elseif ($request->get('_route') == 'starling_bank') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'starling-bank',
                'affiliate_company' => 'Starling Bank',
                'affiliate_company_logo' => 'so-sure_starling_bank_logo.svg',
                'modify_class' => 'starling'
            ];
            $template = 'AppBundle:Default:indexAffiliate.html.twig';
            $this->starlingOAuthSession($request);
        } elseif ($request->get('_route') == 'starling_business') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'starling-business',
            ];
            // Ignore this
            $template = 'AppBundle:Default:starlingBusiness.html.twig';
            $this->starlingOAuthSession($request);
        } elseif ($request->get('_route') == 'comparison') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'comparison',
                'titleH1' => 'Mobile Insurance beyond compare',
                'leadP' => 'If you do want to compare... <br> here\'s how we stack up against
                the <a href="#" class="text-white scroll-to"
                data-scroll-to-anchor="#table-compare"
                data-scroll-to-offset="50">competition</a> 👇',
            ];
        } elseif ($request->get('_route') == 'vendi_app') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'vendi-app',
                'affiliate_company' => 'Vendi',
                'affiliate_company_logo' => 'so-sure_vendi_logo.svg',
            ];
        } elseif ($request->get('_route') == 'getmyslice') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'getmyslice',
                'affiliate_company' => 'Get My Slice',
                'affiliate_company_logo' => 'so-sure_getmyslice_logo.svg',
            ];
        } elseif ($request->get('_route') == 'so_sure_compared') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'so-sure-compared',
            ];
        } elseif ($request->get('_route') == 'moneyback') {
            $data = [
                'competitor' => $competitorData::$competitorComparisonData,
                'affiliate_page' => 'moneyback',
            ];
        }

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_LANDING_PAGE, [
            'page' => $data['affiliate_page']]);

        return $this->render($template, $data);
    }

    /**
     * @Route("/login-redirect", name="login_redirect")
     */
    public function loginRedirectAction()
    {
        if ($this->getUser()) {
            if ($this->isGranted(User::ROLE_EMPLOYEE)) {
                return $this->redirectToRoute('admin_home');
            } elseif ($this->isGranted(User::ROLE_CLAIMS)) {
                return $this->redirectToRoute('claims_policies');
            } elseif ($this->isGranted(User::ROLE_PICSURE)) {
                return $this->redirectToRoute('picsure_index');
            } elseif ($this->isGranted('USER')) {
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
     * @Route("/help", name="help")
     * @Route("/help/{section}", name="help_section", requirements={"section"="[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/help/{section}/{article}", name="help_section_article",
     * requirements={"section"="[\+\-\.a-zA-Z0-9() ]+", "article"="[\+\-\.a-zA-Z0-9() ]+"})
     * @Route("/help/{section}/{article}/{sub}", name="help_section_article_sub",
     * requirements={"section"="[\+\-\.a-zA-Z0-9() ]+", "article"="[\+\-\.a-zA-Z0-9() ]+",
     * "sub"="[\+\-\.a-zA-Z0-9() ]+"})
     * @Template
     */
    public function helpAction()
    {
        return $this->redirectToRoute('faq', [], 301);
    }

    /**
     * @Route("/faq", name="faq", options={"sitemap" = true})
     * @Template
     */
    public function faqAction(Request $request)
    {
        $intercomEnabled = true;
        $hideCookieWarning = false;
        $hideNav = false;
        $hideFooter = false;
        $hideTitle = false;

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
            $hideTitle = true;
        }

        $data = [
            'intercom_enabled' => $intercomEnabled,
            'hide_cookie_warning' => $hideCookieWarning,
            'hide_nav' => $hideNav,
            'hide_footer' => $hideFooter,
            'hide_title' => $hideTitle,
        ];
        return $this->render('AppBundle:Default:faq.html.twig', $data);
    }

    /**
     * @Route("/company-phone-insurance",
     *  name="company_phone_insurance", options={"sitemap" = true})
     * @Route("/company-phone-insurance/thank-you",
     *  name="company_phone_insurance_thanks")
     * @Route("/company-phone-insurance/m",
     *  name="company_phone_insurance_m")
     */
    public function companyAction(Request $request)
    {
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', CompanyLeadType::class)
            ->getForm();

        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_COMPANY_PHONES);

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

                    $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_COMPANY_LEAD_CAPTURE);

                    return $this->redirectToRoute('company_phone_insurance_thanks');
                } else {
                    $this->addFlash(
                        'error',
                        "Sorry, there was a problem validating your request. Please check below for any errors."
                    );
                }
            }
        }

        // Is indexed?
        $noindex = false;
        if ($request->get('_route') == 'company_phone_insurance_m' or
            $request->get('_route') == 'company_phone_insurance_thanks') {
            $noindex = true;
        }

        $data = [
            'lead_form' => $leadForm->createView(),
            'is_noindex' => $noindex
        ];

        return $this->render('AppBundle:Default:indexCompany.html.twig', $data);
    }

    /**
     * @Route("/claim", name="claim", options={"sitemap" = true})
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
                    // @codingStandardsIgnoreEnd
                }
            }
        }

        $data = [
            'claim_email_form' => $claimEmailForm->createView(),
        ];

        if ($request->get('_route') == 'claim_login') {
            return $this->redirectToRoute('claim', [], 301);
        }
        return $this->render('AppBundle:Default:claim.html.twig', $data);
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
            'price' => $phone->getCurrentPhonePrice(PhonePrice::STREAM_ANY),
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
        if (!$rateLimit->allowedByIp(RateLimitService::DEVICE_TYPE_OPT, $request->getClientIp())) {
            $this->addFlash('error', 'Too many requests! Please try again later');
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
        $marketingOptOutForm = $this->get('form.factory')
            ->createNamedBuilder(
                'marketing_optout_form',
                MarketingEmailOptOutType::class,
                null,
                ['checked' => $optOut->hasCategory(Opt::OPTOUT_CAT_MARKETING)]
            )
            ->getForm();
        $optOutForm = $this->get('form.factory')
            ->createNamedBuilder('optout_form', EmailOptOutType::class, $optOut)
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('marketing_optout_form')) {
                $marketingOptOutForm->handleRequest($request);
                $categories = $marketingOptOutForm->getData()['categories'];
                if (in_array(Opt::OPTOUT_CAT_MARKETING, $categories)) {
                    $optOut->addCategory(Opt::OPTOUT_CAT_MARKETING);
                    $user = $this->getUser();
                    /** @var User $user */
                    if ($user) {
                        if ($user->getEmail() !== $email) {
                            throw new \Exception("Cannot opt out for different email");
                        }
                        $this->getUser()->optOutMarketing();
                    } else {
                        throw new \Exception("Please login to proceed with opt out");
                    }
                } else {
                    $optOut->removeCategory(Opt::OPTOUT_CAT_MARKETING);
                }
            } elseif ($request->request->has('optout_form')) {
                $optOutForm->handleRequest($request);
            } else {
                throw new \Exception("no form submitted");
            }
            $optOut->setLocation(Opt::OPT_LOCATION_PREFERENCES);
            $optOut->setIdentityLog($this->getIdentityLogWeb($request));
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
            $dm = $this->getManager();
            $dm->persist($optOut);
            $dm->flush();
            $this->addFlash('success', 'Your preferences have been updated');
            return new RedirectResponse($this->generateUrl('optout_hash', ['hash' => $hash]));
        }
        return array(
            'email' => $email,
            'marketing_optout_form' => $marketingOptOutForm->createView(),
            'optout_form' => $optOutForm->createView(),
        );
    }

    /**
     * @Route("/mobile-otp", name="mobile_otp_web")
     */
    public function mobileOtp(Request $request)
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->validateFields(
            $data,
            ['mobileNumber', 'csrf']
        )) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_MISSING_PARAM, 'Missing parameters', 400);
        }

        if (!$this->isCsrfTokenValid('mobile', $data['csrf'])) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_INVALD_DATA_FORMAT, 'Invalid csrf', 422);
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $validator = new UkMobileValidator();
        $mobileNumber = $this->normalizeUkMobile($data['mobileNumber']);
        $user = $repo->findOneBy(["mobileNumber" => $mobileNumber]);

        if (!$user) {
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_ABSENT, 'User not found', 404);
        }

        if (!$user->isEnabled()) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_USER_RESET_PASSWORD,
                'User account is temporarily disabled - reset password',
                422
            );
        } elseif ($user->isLocked()) {
            return $this->getErrorJsonResponse(
                ApiErrorCode::ERROR_USER_SUSPENDED,
                'User account is suspended - contact us',
                422
            );
        }

        $sms = $this->get('app.sms');
        $code = $sms->setValidationCodeForUser($user);
        $status = $sms->sendTemplate(
            $mobileNumber,
            'AppBundle:Sms:login-code.txt.twig',
            ['code' => $code],
            $user->getLatestPolicy(),
            Charge::TYPE_SMS_VERIFICATION
        );

        if ($status) {
            return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'OK', 200);
        } else {
            $this->get('logger')->error('Error sending SMS.', ['mobile' => $mobileNumber]);
            return $this->getErrorJsonResponse(ApiErrorCode::ERROR_USER_SEND_SMS, 'Error sending SMS', 422);
        }
    }

    /**
     * @Route("/mobile-login", name="mobile_login_web")
     */
    public function mobileLoginAction(Request $request)
    {
        $data = $request->request->all();
        if (!$this->validateFields(
            $data,
            ['mobileNumber', 'code', 'csrf']
        )) {
            throw new \InvalidArgumentException('Missing Parameters');
        }

        if (!$this->isCsrfTokenValid('mobile', $data['csrf'])) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $validator = new UkMobileValidator();
        $mobileNumber = $this->normalizeUkMobile($data['mobileNumber']);

        $user = $repo->findOneBy(['mobileNumber' => $mobileNumber]);
        if (!$user) {
            $this->addFlash(
                'error',
                "Sorry, we can't seem to find your user account. Please contact us if you need help."
            );

            return new RedirectResponse($this->generateUrl('fos_user_security_login'));
        }

        $code = $data['code'];
        $sms = $this->get('app.sms');
        if ($sms->checkValidationCodeForUser($user, $code)) {
            $user->setMobileNumberVerified(true);
            $dm->flush();
        } else {
            $this->addFlash(
                'error',
                "Sorry, your code is invalid or has expired, please try again or use the email login"
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
     * @Route("/quiz", name="quiz")
     */
    public function quizAction()
    {
        return $this->render('AppBundle:Quiz:quiz.html.twig');
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
