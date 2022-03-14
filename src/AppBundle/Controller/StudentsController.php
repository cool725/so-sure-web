<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;

use AppBundle\Classes\ApiErrorCode;

use AppBundle\Document\Lead;
use AppBundle\Document\User;

use AppBundle\Exception\InvalidEmailException;

use AppBundle\Service\MixpanelService;

class StudentsController extends BaseController
{
    /**
     * @Route("/students", name="students")
     */
    public function studentsInsuranceAction()
    {
        $template = 'AppBundle:Students:studentsInsurance.html.twig';

        // Is indexed?
        $noindex = false;

        // Always use page load event
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PAGE_LOAD, [
            'Page' => 'landing_page',
            'Step' => 'students'
        ]);

        $data = [
            'is_noindex' => $noindex,
        ];

        return $this->render($template, $data);
    }

    /**
     * @Route("/students-beans", name="students_beans")
     */
    public function studentsBeansAction()
    {
        $template = 'AppBundle:Students:studentBeans.html.twig';

        // Is indexed?
        $noindex = true;

        // Always use page load event
        $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_PAGE_LOAD, [
            'Page' => 'landing_page',
            'Step' => 'Student Beans'
        ]);

        $data = [
            'is_noindex' => $noindex,
        ];

        return $this->render($template, $data);
    }
}
