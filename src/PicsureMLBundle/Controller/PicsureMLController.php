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
use PicsureMLBundle\Document\TrainingVersionsInfo;
use PicsureMLBundle\Document\TrainingData;
use PicsureMLBundle\Document\Form\Search;
use PicsureMLBundle\Document\Form\NewVersion;
use PicsureMLBundle\Form\Type\SearchType;
use PicsureMLBundle\Form\Type\NewVersionType;
use PicsureMLBundle\Form\Type\EditType;

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
        $dm = $this->getPicsureMLManager();
        $repo = $dm->getRepository(TrainingData::class);

        $search = new Search();
        $picsureMLSearchForm = $this->get('form.factory')
            ->createNamedBuilder('picsureml_search_form', SearchType::class, $search, ['method' => 'GET'])
            ->getForm();

        $newVersion = new NewVersion();
        $picsureMLNewVersionForm = $this->get('form.factory')
            ->createNamedBuilder('picsureml_newversion_form', NewVersionType::class, $newVersion)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('picsureml_newversion_form')) {
                $picsureMLNewVersionForm->handleRequest($request);
                if ($picsureMLNewVersionForm->isValid()) {
                    $version = $picsureMLNewVersionForm->get('version')->getData();
                    $service = $this->get('picsureml.picsureml');
                    $service->createNewTrainingVersion($version);
                    return new RedirectResponse($this->generateUrl('admin_picsure_ml'));
                }
            }
        }

        $picsureMLSearchForm->handleRequest($request);

        $version = $search->getVersion();
        $label = $search->getLabel();
        $forDetection = $search->getForDetection();
        $imagesPerPage = $search->getImagesPerPage();

        if ($imagesPerPage == null) {
            $imagesPerPage = $picsureMLSearchForm->get('images_per_page')->getData();
        }

        $qb = $repo->createQueryBuilder();
        if ($version != null) {
            $qb->field('versions')->equals($version);
        }
        if ($label != null) {
            if ($label == 'none') {
                $qb->field('label')->equals(null);
            } else {
                $qb->field('label')->equals($label);
            }
        }
        if ($forDetection == true) {
            $qb->field('forDetection')->equals(true);
        }
        $qb->sort('id', 'desc');
        $pager = $this->pager($request, $qb, $imagesPerPage);

        return [
            'n_images' => $repo->getTotalCount($version, null),
            'n_none_images' => $repo->getTotalCount($version, 'none'),
            'n_undamaged_images' => $repo->getTotalCount($version, TrainingData::LABEL_UNDAMAGED),
            'n_invalid_images' => $repo->getTotalCount($version, TrainingData::LABEL_INVALID),
            'n_damaged_images' => $repo->getTotalCount($version, TrainingData::LABEL_DAMAGED),
            'version' => $version,
            'label' => $label,
            'picsureml_search_form' => $picsureMLSearchForm->createView(),
            'picsureml_newversion_form' => $picsureMLNewVersionForm->createView(),
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

        $editForm = $this->get('form.factory')
            ->createNamedBuilder('picsureml_edit_form', EditType::class, $image)
            ->getForm();

        if ('POST' === $request->getMethod()) {
            if ($request->request->has('picsureml_edit_form')) {
                $editForm->handleRequest($request);
                if ($editForm->isValid()) {
                    $dm->flush();
                    if (array_key_exists('previous', $request->request->get('picsureml_edit_form'))) {
                        $prevId = $repo->getPreviousImage($id);
                        if ($prevId) {
                            return $this->redirectToRoute('admin_picsure_ml_edit', ['id' => $prevId]);
                        } else {
                            return $this->redirectToRoute('admin_picsure_ml');
                        }
                    } elseif (array_key_exists('next', $request->request->get('picsureml_edit_form'))) {
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
            'picsureml_edit_form' => $editForm->createView(),
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
}
