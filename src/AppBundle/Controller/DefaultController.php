<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
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

class DefaultController extends BaseController
{
    use PhoneTrait;

    /**
     * @Route("/", name="homepage", options={"sitemap"={"priority":"1.0","changefreq":"daily"}})
     * @Template
     */
    public function indexAction(Request $request)
    {
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

        $this->get('app.mixpanel')->trackWithUtm(MixpanelService::HOME_PAGE);

        return array(
            'form_top' => $formTop->createView(),
            'form_bottom' => $formBottom->createView(),
            'referral' => $referral,
        );
    }

    /**
     * @Route("/select-phone", name="select_phone_make")
     * @Route("/select-phone/{type}", name="select_phone_make_type")
     * @Template()
     */
    public function selectPhoneMakeAction(Request $request, $type = null)
    {
        $deviceAtlas = $this->get('app.deviceatlas');
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phoneMake = new PhoneMake();
        if ($request->getMethod() == "GET") {
            $phone = $deviceAtlas->getPhone($request);
            /*
            if (!$phone) {
                $phone = $this->getDefaultPhone();
            }
            */
            if ($phone instanceof Phone) {
                $phoneMake->setMake($phone->getMake());
            }
        }
        $post = $this->generateUrl('select_phone_make');
        if ($type) {
            $post = $this->generateUrl('select_phone_make_type', ['type' => $type]);
        }
        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $post,
            ])
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('launch_phone')) {
                // handle request / isvalid doesn't really work well with jquery form adjustment
                // $formPhone->handleRequest($request);
                $phoneMake->setPhoneId($request->get('launch_phone')['phoneId']);
                if ($phoneMake->getPhoneId()) {
                    $phone = $phoneRepo->find($phoneMake->getPhoneId());
                    if ($type == 'purchase-select' || $type == 'purchase-change') {
                        $session = $request->getSession();
                        $session->set('quote', $phone->getId());

                        return $this->redirectToRoute('purchase_step_phone');
                    } else {
                        if (!$phone) {
                            // TODO: Would be better to redirect to a make page instead
                            $this->addFlash('warning', 'Please ensure you select a model as well');
                            if ($this->getReferer($request)) {
                                return new RedirectResponse($this->getReferer($request));
                            } else {
                                return $this->redirectToRoute('homepage');
                            }
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
        }

        return [
            'form_phone' => $formPhone->createView(),
            'phones' => $this->getPhonesArray(),
            'type' => $type,
        ];
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
     * @Route("/jobs", name="jobs", options={"sitemap"={"priority":"0.5","changefreq":"daily"}})
     * @Template
     */
    public function jobsAction()
    {
        return array();
    }

    /**
     * @Route("/terms", name="terms", options={"sitemap"={"priority":"0.5","changefreq":"daily"}})
     * @Template
     */
    public function termsAction()
    {
        return array();
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
     * @Route("/quote/{id}", name="quote_phone", requirements={"id":"[0-9a-f]{24,24}"})
     * @Route("/quote/{make}+{model}+{memory}+insurance", name="quote_make_model_memory",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+","memory":"[0-9]+"})
     * @Route("/quote/{make}+{model}+insurance", name="quote_make_model",
     *          requirements={"make":"[a-zA-Z]+","model":"[\+\-\.a-zA-Z0-9() ]+"})
     * @Template
     */
    public function quotePhoneAction(Request $request, $id = null, $make = null, $model = null, $memory = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phonePolicyRepo = $dm->getRepository(PhonePolicy::class);
        $phone = null;
        $decodedModel = Phone::decodeModel($model);
        if ($id) {
            $phone = $repo->find($id);
        } elseif ($memory) {
            $phone = $repo->findOneBy([
                'active' => true,
                'make' => $make,
                'model' => $decodedModel,
                'memory' => (int) $memory
            ]);
            // check for historical urls
            if (!$phone) {
                $phone = $repo->findOneBy([
                    'active' => true,
                    'make' => $make,
                    'model' => $model,
                    'memory' => (int) $memory
                ]);
            }
        } else {
            $phone = $repo->findOneBy(['active' => true, 'make' => $make, 'model' => $decodedModel]);
            // check for historical urls
            if (!$phone) {
                $phone = $repo->findOneBy(['active' => true, 'make' => $make, 'model' => $model]);
            }
        }
        if (!$phone) {
            return new RedirectResponse($this->generateUrl('homepage'));
        }

        $session = $request->getSession();
        $session->set('quote', $phone->getId());

        $user = new User();

        $form = $this->get('form.factory')
            ->createNamedBuilder('launch', LaunchType::class, $user)
            ->getForm();

        $lead = new Lead();
        $lead->setSource(Lead::SOURCE_BUY);
        $leadForm = $this->get('form.factory')
            ->createNamedBuilder('lead_form', LeadEmailType::class, $lead)
            ->getForm();
        $buyForm = $this->get('form.factory')
            ->createNamedBuilder('buy_form')->add('buy', SubmitType::class)
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
                    $userRepo = $dm->getRepository(User::class);
                    $user = $userRepo->findOneBy(['emailCanonical' => strtolower($lead->getEmail())]);
                    if (!$user) {
                        $userManager = $this->get('fos_user.user_manager');
                        $user = $userManager->createUser();
                        $user->setEnabled(true);
                        $user->setEmail($lead->getEmail());
                        $dm->persist($user);
                        $dm->flush();

                        $this->get('fos_user.security.login_manager')->loginUser(
                            $this->getParameter('fos_user.firewall_name'),
                            $user
                        );
                    } elseif (!$this->getUser()) {
                        // @codingStandardsIgnoreStart
                        $this->addFlash('warning', sprintf(
                            "Looks like you already have an account. Please login below to continue with your purchase.  You may need to use the email login and forgot password link."
                        ));
                        // @codingStandardsIgnoreEnd
                    }

                    return $this->redirectToRoute('purchase');
                } else {
                    $this->addFlash('error', sprintf(
                        "Sorry, didn't quite catch that email.  Please try again."
                    ));
                }
            } elseif ($request->request->has('buy_form')) {
                $buyForm->handleRequest($request);
                if ($buyForm->isValid()) {
                    $this->get('app.mixpanel')->track(MixpanelService::BUY_BUTTON_CLICKED);

                    return $this->redirectToRoute('purchase');
                }
            }
        }

        $maxPot = $phone->getCurrentPhonePrice()->getMaxPot();
        $maxConnections = $phone->getCurrentPhonePrice()->getMaxConnections();

        $this->get('app.mixpanel')->trackWithUtm(MixpanelService::QUOTE_PAGE, [
            'Device Selected' => $phone->__toString(),
            'Monthly Cost' => $phone->getCurrentPhonePrice()->getMonthlyPremiumPrice(),
        ]);

        return array(
            'phone' => $phone,
            'phone_price' => $phone->getCurrentPhonePrice(),
            'connection_value' => PhonePolicy::STANDARD_VALUE,
            'max_connections' => $maxConnections,
            'max_pot' => $maxPot,
            'form' => $form->createView(),
            'lead_form' => $leadForm->createView(),
            'buy_form' => $buyForm->createView(),
            'phones' => $repo->findBy(
                ['active' => true, 'make' => $make, 'model' => $decodedModel],
                ['memory' => 'asc']
            ),
        );
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
}
