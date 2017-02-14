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

class HelpController extends BaseController
{
    /**
     * @Route("/help/{file}", name="help", requirements={"file"=".*"})
     * @Template()
     */
    public function allAction($file = null)
    {
        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3support_fs');

        // assume html extension if not given
        if (pathinfo($file, PATHINFO_EXTENSION) == "") {
            $html = sprintf('%s.html', $file);
            if (!$filesystem->has($html)) {
                $html = sprintf("%s/index.html", $file);
            }
            $file = $html;
        }

        if (!$filesystem->has($file)) {
            throw $this->createNotFoundException('URL not found');
        }
        $mimetype = $filesystem->getMimetype($file);
        if (stripos($mimetype, 'text/html') !== false) {
            $html = $filesystem->read($file);
            $crawler = new Crawler($html);
            $head = $crawler->filter('head');
            $head->each(function (Crawler $crawler) {
                foreach ($crawler as $node) {
                    if ($node->tagName == "title") {
                        $node->parentNode->removeChild($node);
                    }
                }
            });

            //throw new \Exception($head->html());
            throw new \Exception($crawler->filterXPath('//head/*[not(self::title)]')->html());
            return [
                'title' => $crawler->filter('head title')->text(),
                //'head' => $crawler->filterXPath('//head/*[not(self::title)]')->html(),
                'head' => $crawler->filter('head')->reduce(function (Crawler $node, $i) {
                    //if (!in_array($node->nodeName(), ['meta'])) { throw new \Exception($node->nodeName()); }
                    if ($node->nodeName() == 'title') {
                        return false;
                    }

                    return true;
                })->html(),
                'body' => $crawler->filter('body')->html(),
            ];
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
