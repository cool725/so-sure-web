<?php

namespace AppBundle\Controller;

use AppBundle\Form\Type\ContactUsType;
use AppBundle\Service\IntercomService;
use AppBundle\Service\MailerService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

use AppBundle\Document\Lead;

/**
 * @Route("/about/social-insurance")
 */
class AboutController extends BaseController
{
    /**
     * @Route("", name="about_home")
     * @Template
     */
    public function indexAction(Request $request)
    {
        return $this->redirectToRoute('social_insurance');
    }

    /**
     * @Route("/careers", name="careers", options={"sitemap"={"priority":"0.5","changefreq":"daily"}})
     * @Template
     */
    public function careersAction()
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
     * @Route("/privacy", name="privacy", options={"sitemap"={"priority":"0.5","changefreq":"daily"}})
     * @Template
     */
    public function privacyAction(Request $request)
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

        return [
            'intercom_enabled' => $intercomEnabled,
            'hide_cookie_warning' => $hideCookieWarning,
            'hide_nav' => $hideNav,
            'hide_footer' => $hideFooter,
            'hide_title' => $hideTitle,
        ];
    }

    /**
     * @Route("/how-to-contact-so-sure", name="about_how_to_contact_so_sure")
     * @Template
     */
    public function howToContactSoSureAction(Request $request)
    {
        $contactForm = $this->get('form.factory')
            ->createNamedBuilder('contact_form', ContactUsType::class)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('contact_form')) {
                $contactForm->handleRequest($request);
                if ($contactForm->isValid()) {
                    // @codingStandardsIgnoreStart
                    $body = sprintf(
                        "Name: %s<br>Email: %s<br>Contact #: %s<br>Message: %s",
                        $contactForm->getData()['name'],
                        $contactForm->getData()['email'],
                        $contactForm->getData()['phone'],
                        $contactForm->getData()['message']
                    );
                    // @codingStandardsIgnoreEnd
                    /** @var IntercomService $intercom */
                    $intercom = $this->get('app.intercom');
                    $intercom->queueMessage($contactForm->getData()['email'], $body, Lead::SOURCE_CONTACT_US);

                    $subject = sprintf(
                        'Contact Request from %s',
                        $contactForm->getData()['name']
                    );

                    /** @var MailerService $mailer */
                    $mailer = $this->get('app.mailer');
                    $mailer->send(
                        $subject,
                        'bcc@so-sure.com',
                        $body
                    );

                    $this->addFlash(
                        'success',
                        "Thanks. We'll be in touch shortly"
                    );

                    return $this->redirectToRoute('about_how_to_contact_so_sure');
                } else {
                    $this->addFlash(
                        'error',
                        "Sorry, there was a problem validating your request. Please check below for any errors."
                    );
                }
            }
        }

        return [
            'contact_form' => $contactForm->createView(),
        ];
    }
}
