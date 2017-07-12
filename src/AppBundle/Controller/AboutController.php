<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

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
     * @Route("/how-to-contact-so-sure", name="about_how_to_contact_so_sure")
     * @Template
     */
    public function howToContactSoSureAction(Request $request)
    {
    }
}
