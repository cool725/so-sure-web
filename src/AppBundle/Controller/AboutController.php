<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

/**
 * @Route("/about")
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
     * @Route("/are-we-regulated-within-the-uk", name="about_are_we_regulated_within_the_uk")
     * @Template
     */
    public function areWeRegulatedWithinTheUkAction(Request $request)
    {
    }

    /**
     * @Route("/how-secure-is-so-sure", name="about_how_secure_is_so_sure")
     * @Template
     */
    public function howSecureIsSoSureAction(Request $request)
    {
    }

    /**
     * @Route("/what-do-you-do-with-my-personal-information", name="about_what_do_you_do_with_my_personal_information")
     * @Template
     */
    public function whatDoYouDoWithMyPersonalInformationAction(Request $request)
    {
    }

    /**
     * @Route("/what-personal-information-do-you-capture", name="about_what_personal_information_do_you_capture")
     * @Template
     */
    public function whatPersonalInformationDoYouCaptureAction(Request $request)
    {
    }

    /**
     * @Route("/the-team", name="about_the_team")
     * @Template
     */
    public function theTeamAction(Request $request)
    {
    }

    /**
     * @Route("/how-to-contact-so-sure", name="about_how_to_contact_so_sure")
     * @Template
     */
    public function howToContactSoSureAction(Request $request)
    {
    }

    /**
     * @Route("/our-mission", name="about_our_mission")
     * @Template
     */
    public function ourMissionAction(Request $request)
    {
    }

    /**
     * @Route("/why-we-re-better", name="about_why_we_re_better")
     * @Template
     */
    public function whyWeReBetterAction(Request $request)
    {
    }

    /**
     * @Route("/what-is-social-insurance", name="about_what_is_social_insurance")
     * @Template
     */
    public function whatIsSocialInsuranceAction(Request $request)
    {
    }
}
