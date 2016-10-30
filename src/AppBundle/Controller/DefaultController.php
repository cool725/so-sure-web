<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\OptOut\EmailOptOut;
use AppBundle\Document\Invitation\EmailInvitation;
use AppBundle\Form\Type\PhoneType;
use AppBundle\Form\Type\SmsAppLinkType;
use AppBundle\Form\Type\LeadEmailType;
use AppBundle\Document\PolicyTerms;
use AppBundle\Document\Lead;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

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
        if ($geoip->findCountry($ip) == "US" && $site != 'uk') {
            return $this->redirectToRoute('launch_usa');
        }
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $phoneRepo = $dm->getRepository(Phone::class);
        $logger = $this->get('logger');
        $launchUser = $this->get('app.user.launch');
        $deviceAtlas = $this->get('app.deviceatlas');

        $userTop = new User();
        $referral = $request->get('referral');
        if ($referral) {
            $userTop->setReferralId($referral);
            $session = $this->get('session');
            $session->set('referral', $referral);
            $logger->debug(sprintf('Referral %s', $referral));
        }
        $userBottom = clone $userTop;
        $policy = new PhonePolicy();
        if ($request->getMethod() == "GET") {
            $phone = $deviceAtlas->getPhone($request);
            /*
            if (!$phone) {
                $phone = $this->getDefaultPhone();
            }
            */
            if ($phone instanceof Phone) {
                $policy->setPhone($phone);
            }
        }

        $formTop = $this->get('form.factory')
            ->createNamedBuilder('launch_top', LaunchType::class, $userTop)
            ->getForm();
        $formBottom = $this->get('form.factory')
            ->createNamedBuilder('launch_bottom', LaunchType::class, $userBottom)
            ->getForm();
        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneType::class, $policy)
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
            } elseif ($request->request->has('launch_phone')) {
                $formPhone->handleRequest($request);
                if ($formPhone->isValid() && $policy->getPhone()) {
                    if ($policy->getPhone()->getMemory()) {
                        return $this->redirectToRoute('quote_make_model_memory', [
                            'make' => $policy->getPhone()->getMake(),
                            'model' => $policy->getPhone()->getEncodedModel(),
                            'memory' => $policy->getPhone()->getMemory(),
                        ]);
                    } else {
                        return $this->redirectToRoute('quote_make_model', [
                            'make' => $policy->getPhone()->getMake(),
                            'model' => $policy->getPhone()->getEncodedModel(),
                        ]);
                    }
                }
            }

            if ($existingUser) {
                return $this->redirectToRoute('launch_share', ['id' => $existingUser->getId()]);
            }
        }

        return array(
            'form_top' => $formTop->createView(),
            'form_bottom' => $formBottom->createView(),
            'referral' => $referral,
            'form_phone' => $formPhone->createView(),
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
            } elseif ($this->isGranted('ROLE_CLAIMS')) {
                return $this->redirectToRoute('claims_home');
            } elseif ($this->isGranted('ROLE_USER')) {
                return $this->redirectToRoute('user_home');
            }
        }

        return $this->redirectToRoute('homepage');
    }

    /**
     * @Route("/launch/{id}", name="launch_share")
     * @Template
     */
    public function launchAction($id)
    {
        $launchUser = $this->get('app.user.launch');
        $url = $launchUser->getShortLink($id);

        return array('id' => $id, 'referral_url' => $url, 'fb_pixel_event' => 'Lead');
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
     * @Route("/alpha", name="alpha")
     * @Template
     */
    public function alphaAction()
    {
        return array();
    }

    /**
     * @Route("/quote", name="quote")
     * @Template
     */
    public function quoteAction(Request $request)
    {
        $policy = new PhonePolicy();
        $form = $this->createForm(PhoneType::class, $policy);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $session = new Session();
            $session->set('quote', $policy->getPhone()->getId());

            return $this->redirectToRoute('purchase');
        }

        return array(
            'form' => $form->createView(),
        );
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
            $phone = $repo->findOneBy(['make' => $make, 'model' => $decodedModel, 'memory' => (int) $memory]);
            // check for historical urls
            if (!$phone) {
                $phone = $repo->findOneBy(['make' => $make, 'model' => $model, 'memory' => (int) $memory]);
            }
        } else {
            $phone = $repo->findOneBy(['make' => $make, 'model' => $decodedModel]);
            // check for historical urls
            if (!$phone) {
                $phone = $repo->findOneBy(['make' => $make, 'model' => $model]);
            }
        }
        if (!$phone) {
            return new RedirectResponse($this->generateUrl('homepage'));
        }

        $user = new User();

        $form = $this->get('form.factory')
            ->createNamedBuilder('launch', LaunchType::class, $user)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $launchUser = $this->get('app.user.launch');
                $existingUser = $launchUser->addUser($user)['user'];
            }

            if ($existingUser) {
                return $this->redirectToRoute('launch_share', ['id' => $existingUser->getId()]);
            }
        }

        $tmpPolicy = new PhonePolicy();
        $prefix = $tmpPolicy->getPolicyNumberPrefix();
        $isPromo = $phonePolicyRepo->isPromoLaunch($prefix);

        $maxPot = $phone->getCurrentPhonePrice()->getMaxPot($isPromo);
        $additionalValue = 0;
        if ($isPromo) {
            $additionalValue = PhonePolicy::PROMO_LAUNCH_VALUE;
        }
        $maxConnections = $phone->getCurrentPhonePrice()->getMaxConnections($additionalValue, $isPromo);

        return array(
            'phone' => $phone,
            'phone_price' => $phone->getCurrentPhonePrice(),
            'connection_value' => PhonePolicy::STANDARD_VALUE,
            'max_connections' => $maxConnections,
            'max_pot' => $maxPot,
            'is_promo' => $isPromo,
            'form' => $form->createView()
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
