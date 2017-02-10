<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\DomCrawler\Crawler;

/**
 * @Route("/support")
 */
class SupportController extends BaseController
{
    /**
     * @Route("/{name}", name="support", requirements={"name"=".+"})
     * @Template()
     */
    public function allAction(Request $request, $name)
    {
        if ($name == "") {
            $name = "index.html";
        }
        $file = sprintf('%s', $name);

        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3support_fs');
        $mimetype = $filesystem->getMimetype($file);
        if (stripos($mimetype, 'text/html') !== false) {
            $html = $filesystem->read($file);
            $crawler = new Crawler($html);
            return ['data' => $crawler->filter('body')->html()];
        } else {
            return StreamedResponse::create(
                function () use ($file, $filesystem) {
                    $stream = $filesystem->readStream($file);
                    echo stream_get_contents($stream);
                    flush();
                },
                200,
                array('Content-Type' => $mimetype)
            );
        }
    }
}
