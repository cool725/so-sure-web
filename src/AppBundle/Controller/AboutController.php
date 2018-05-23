<?php

namespace AppBundle\Controller;

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
     * @Route("/", name="about_home")
     * @Template
     */
    public function indexAction(Request $request)
    {
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
    public function privacyAction()
    {
        return array();
    }

    /**
     * @Route("/how-to-contact-so-sure", name="about_how_to_contact_so_sure")
     * @Template
     */
    public function howToContactSoSureAction(Request $request)
    {
        $contactForm = $this->get('form.factory')
            ->createNamedBuilder('contact_form')
            ->add('email', EmailType::class)
            ->add('name', TextType::class)
            ->add('phone', TextType::class)
            ->add('message', TextareaType::class)
            ->add('submit', SubmitType::class)
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
                    $intercom = $this->get('app.intercom');
                    $intercom->queueMessage($contactForm->getData()['email'], $body, Lead::SOURCE_CONTACT_US);

                    $message = \Swift_Message::newInstance()
                        ->setSubject(sprintf(
                            'Contact Request from %s',
                            $contactForm->getData()['name']
                        ))
                        ->setFrom('info@so-sure.com')
                        ->setTo('contact-us@so-sure.com')
                        ->setBody($body, 'text/html');
                    $this->get('mailer')->send($message);
                    $this->addFlash(
                        'success',
                        "Thanks. We'll be in touch shortly"
                    );

                    return $this->redirectToRoute('about_how_to_contact_so_sure');
                }
            }
        }

        return [
            'contact_form' => $contactForm->createView(),
        ];
    }
}
