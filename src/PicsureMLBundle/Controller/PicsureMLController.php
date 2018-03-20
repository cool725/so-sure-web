<?php

namespace PicsureMLBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;

use AppBundle\Controller\BaseController;
use AppBundle\Document\File\S3File;
use PicsureMLBundle\Service\PicsureMLService;
use PicsureMLBundle\Document\TrainingData;
use PicsureMLBundle\Form\Type\PicsureMLSearchType;
use PicsureMLBundle\Form\Type\LabelType;

/**
 * @Route("/admin")
 * @Security("has_role('ROLE_ADMIN')")
 */
class PicsureMLController extends BaseController
{

    /**
     * @Route("/picsure-ml", name="admin_picsure_ml")
     * @Template
     */
    public function indexAction(Request $request)
    {
        $picsureMLSearchForm = $this->get('form.factory')
            ->createNamedBuilder('picsureml_search_form', PicsureMLSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $picsureMLSearchForm->handleRequest($request);

        $label = $picsureMLSearchForm->get('label')->getData();
        $imagesPerPage = $picsureMLSearchForm->get('images_per_page')->getData();

        if ($imagesPerPage == null) {
            $imagesPerPage = 30;
        }

        $dm = $this->getPicsureMLManager();
        $repo = $dm->getRepository(TrainingData::class);

        $qb = $repo->createQueryBuilder();
        if ($label != null) {
            if ($label == 'none') {
                $qb->field('label')->equals(null);
            } else {
                $qb->field('label')->equals($label);
            }
        }
        $qb->sort('id', 'desc');
        $pager = $this->pager($request, $qb, $imagesPerPage);

        return [
            'n_images' => $repo->getTotalCount(),
            'n_none_images' => $repo->getNoneCount(),
            'n_undamaged_images' => $repo->getUndamagedCount(),
            'n_invalid_images' => $repo->getInvalidCount(),
            'n_damaged_images' => $repo->getDamagedCount(),
            'label' => $label,
            'picsureml_search_form' => $picsureMLSearchForm->createView(),
            'images' => $pager->getCurrentPageResults(),
            'pager' => $pager
        ];
    }

    /**
     * @Route("/picsure-ml/edit/{id}", name="admin_picsure_ml_edit")
     * @Template
     */
    public function editAction(Request $request, $id)
    {
        $dm = $this->getPicsureMLManager();
        $repo = $dm->getRepository(TrainingData::class);
        $image = $repo->find($id);
        if ($image === null) {
            throw $this->createNotFoundException(sprintf('Image not found %s', $id));
        }

        $imagesForm = $this->get('form.factory')
            ->createNamedBuilder('picsureml_label_form', LabelType::class, $image)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('picsureml_label_form')) {
                $imagesForm->handleRequest($request);
                if ($imagesForm->isValid()) {
                    $dm->flush();
                    if (array_key_exists('previous', $request->request->get('picsureml_label_form'))) {
                        $prevId = $repo->getPreviousImage($id);
                        if ($prevId) {
                            return $this->redirectToRoute('admin_picsure_ml_edit', ['id' => $prevId]);
                        } else {
                            return $this->redirectToRoute('admin_picsure_ml');
                        }
                    } else {
                        $nextId = $repo->getNextImage($id);
                        if ($nextId) {
                            return $this->redirectToRoute('admin_picsure_ml_edit', ['id' => $nextId]);
                        } else {
                            return $this->redirectToRoute('admin_picsure_ml');
                        }
                    }
                }
            }
        }

        return [
            'image' => $image,
            'picsureml_label_form' => $imagesForm->createView(),
        ];
    }

    /**
     * @Route("/picsure-ml/image/policy/{file}", name="admin_picsure_ml_image_policy", requirements={"file"=".*"})
     * @Route("/picsure-ml/image/picsure/{file}", name="admin_picsure_ml_image_picsure", requirements={"file"=".*"})
     * @Template()
     */
    public function picsureImageAction(Request $request, $file)
    {
        if ($request->get('_route') == "admin_picsure_ml_image_policy") {
            $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3policy_fs');
            $environment = $this->getParameter('kernel.environment');
            $file = str_replace(sprintf('%s/', $environment), '', $file);
        } else {
            $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3picsure_fs');
        }

        if (!$filesystem->has($file)) {
            throw $this->createNotFoundException(sprintf('URL not found %s', $file));
        }

        $mimetype = $filesystem->getMimetype($file);
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

    /**
     * @Route("/picsure-ml/predict/{id}", name="admin_picsure_ml_predict")
     * @Template
     */
    public function predictAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(S3File::class);

        $picsureFile = $repo->find($id);
        if ($picsureFile) {
            $service = $this->get('picsureml.picsureml');
            $service->predict($picsureFile);
        }

        return new RedirectResponse($this->generateUrl('admin_picsure'));
    }

    /**
     * @Route("/picsure-ml/sync", name="admin_picsure_ml_sync")
     * @Method({"POST"})
     */
    /*
    public function syncAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3picsure_fs');
        $service = $this->get('picsureml.picsureml');
        $service->sync($filesystem);

        return new RedirectResponse($this->generateUrl('admin_picsure_ml'));
    }
    */

    /**
     * @Route("/picsure-ml/annotate", name="admin_picsure_ml_annotate")
     * @Method({"POST"})
     */
    /*
    public function annotateAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3picsure_fs');
        $service = $this->get('picsureml.picsureml');
        $service->annotate($filesystem);

        return new RedirectResponse($this->generateUrl('admin_picsure_ml'));
    }
    */
}
