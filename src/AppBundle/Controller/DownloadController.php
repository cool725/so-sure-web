<?php

namespace AppBundle\Controller;

use AppBundle\Validator\Constraints\AlphanumericValidator;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\RedirectResponse;
use AppBundle\Service\MixpanelService;

/**
 * @Route("/download")
 */
class DownloadController extends BaseController
{
    /**
     * @Route("/apple/{medium}", name="download_apple")
     * @Template
     */
    public function appleAction($medium = null)
    {
        $validator = new AlphanumericValidator();
        $medium = $validator->conform($medium);

        $url = $this->get('app.twig.branch')->apple($medium);
        if (!$url) {
            throw $this->createNotFoundException('Invalid url');
        }

        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_APP_DOWNLOAD, [
            'Store' => 'Apple',
            'Location' => $medium,
        ]);

        return new RedirectResponse($url);
    }

    /**
     * @Route("/google/{medium}", name="download_google")
     * @Template
     */
    public function googleAction($medium = null)
    {
        $validator = new AlphanumericValidator();
        $medium = $validator->conform($medium);

        $url = $this->get('app.twig.branch')->google($medium);
        if (!$url) {
            throw $this->createNotFoundException('Invalid url');
        }

        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_APP_DOWNLOAD, [
            'Store' => 'Google',
            'Location' => $medium,
        ]);

        return new RedirectResponse($url);
    }
}
