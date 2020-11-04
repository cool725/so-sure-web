<?php

namespace AppBundle\Controller;

use AppBundle\Document\User;
use AppBundle\Document\Policy;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\EmailTrait;
use AppBundle\Helpers\StringHelper;
use AppBundle\Event\PicsureEvent;
use AppBundle\Form\Type\PicSureSearchType;
use AppBundle\Service\PushService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @Route("/picsure")
 * @Security("has_role('ROLE_PICSURE')")
 */
class PicsureController extends BaseController implements ContainerAwareInterface
{
    use ContainerAwareTrait;
    use EmailTrait;

    /**
     * @Route("", name="picsure_index")
     * @Route("/{id}/approve", name="picsure_approve")
     * @Route("/{id}/reject", name="picsure_reject")
     * @Route("/{id}/invalid", name="picsure_invalid")
     * @Template
     */
    public function picsureAction(Request $request, $id = null)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(PhonePolicy::class);
        /** @var PhonePolicy $policy */
        $policy = null;
        if ($id) {
            /** @var PhonePolicy $policy */
            $policy = $repo->find($id);
            if ($request->get('_route') == 'picsure_approve') {
                $this->approvePicsure($policy, $this->getUser());
                return new RedirectResponse($this->generateUrl('picsure_index'));
            } elseif ($request->get('_route') == 'picsure_reject') {
                $this->rejectPicsure($policy, $this->getUser());
                return new RedirectResponse($this->generateUrl('picsure_index'));
            } elseif ($request->get('_route') == 'picsure_invalid') {
                $this->invalidatePicsure($policy, $this->getUser(), $request->get('message'));
                return new RedirectResponse($this->generateUrl('picsure_index'));
            }
        }
        $picSureSearchForm = $this->get('form.factory')
            ->createNamedBuilder('search_form', PicSureSearchType::class, null, ['method' => 'GET'])
            ->getForm();
        $picSureSearchForm->handleRequest($request);
        $status = $request->get('status');
        $data = $picSureSearchForm->get('status')->getData();
        $qb = $repo->createQueryBuilder()
            ->field('picSureStatus')->equals($data)
            ->sort('picSureApprovedDate', 'desc')
            ->sort('created', 'desc');
        $pager = $this->pager($request, $qb, $maxPerPage = 5);
        return [
            'policies' => $pager->getCurrentPageResults(),
            'pager' => $pager,
            'status' => $data,
            'picsure_search_form' => $picSureSearchForm->createView(),
        ];
    }


    /**
     * @Route("/picsure/image/{file}", name="picsure_image", requirements={"file"=".*"})
     * @Template()
     */
    public function picsureImageAction($file)
    {
        $filesystem = $this->get('oneup_flysystem.mount_manager')->getFilesystem('s3policy_fs');
        $environment = $this->getParameter('kernel.environment');
        $file = str_replace(sprintf('%s/', $environment), '', $file);
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
     * Approves picsure for a policy.
     * @param PhonePolicy $policy is the policy that is being approved.
     * @param User        $user   is the user doing the approving.
     */
    private function approvePicsure($policy, $user)
    {
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_APPROVED, $user);
        $this->getManager()->flush();
        $mailer = $this->get('app.mailer');
        $trustpilot = 'wearesosure.com+f9e2e9f7ce@invite.trustpilot.com';
        // Don't ask Gmail users for Truspilot review
        $bcc = $this->isGmail($policy->getUser()->getEmail()) ? null : $trustpilot;
        $mailer->sendTemplateToUser(
            'Phone validation successful ✅',
            $policy->getUser(),
            'AppBundle:Email:picsure/accepted.html.twig',
            ['policy' => $policy],
            'AppBundle:Email:picsure/accepted.txt.twig',
            ['policy' => $policy],
            null,
            $bcc
        );
        try {
            $push = $this->get('app.push');
            $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                'Your phone is now successfully validated.'
            ), null, null, $policy);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in pic-sure push.'), ['exception' => $e]);
        }
        $picsureFiles = $policy->getPolicyPicSureFiles();
        if (count($picsureFiles) > 0) {
            $this->get('event_dispatcher')->dispatch(
                PicsureEvent::EVENT_APPROVED,
                new PicsureEvent($policy, $picsureFiles[0])
            );
        } else {
            $this->get('logger')->error(sprintf('Missing picture file in policy %s.', $policy->getId()));
        }
    }

    /**
     * Invalidates picsure for a policy.
     * @param PhonePolicy $policy  is the policy being invalidated.
     * @param User        $user    is the user doing the invalidation.
     * @param string      $message is the invalidation reason message.
     */
    private function invalidatePicsure($policy, $user, $message)
    {
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_INVALID, $user);
        $this->getManager()->flush();
        $mailer = $this->get('app.mailer');
        $mailer->sendTemplateToUser(
            'Sorry, please attempt to validate again ⚠️',
            $policy->getUser(),
            'AppBundle:Email:picsure/invalid.html.twig',
            ['policy' => $policy, 'additional_message' => $message],
            'AppBundle:Email:picsure/invalid.txt.twig',
            ['policy' => $policy, 'additional_message' => $message]
        );
        try {
            $push = $this->get('app.push');
            $push->sendToUser(PushService::PSEUDO_MESSAGE_PICSURE, $policy->getUser(), sprintf(
                'Sorry, your phone validation was not successful: %s',
                $message
            ), null, null, $policy);
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in pic-sure push.'), ['exception' => $e]);
        }
        $picsureFiles = $policy->getPolicyPicSureFiles();
        if (count($picsureFiles) > 0) {
            $this->get('event_dispatcher')->dispatch(
                PicsureEvent::EVENT_INVALID,
                new PicsureEvent($policy, $picsureFiles[0])
            );
        } else {
            $this->get('logger')->error(sprintf('Missing picture file in policy %s.', $policy->getId()));
        }
    }

    /**
     * Rejects picsure for a policy.
     * @param PhonePolicy $policy the policy to be rejected.
     * @param User        $user   is the user doing the rejecting.
     */
    private function rejectPicsure($policy, $user)
    {
        $policy->setPicSureStatus(PhonePolicy::PICSURE_STATUS_REJECTED, $user);
        $mailer = $this->get('app.mailer');
        $mailer->sendTemplateToUser(
            'Phone validation failed ❌',
            $policy->getUser(),
            'AppBundle:Email:picsure/rejected.html.twig',
            ['policy' => $policy],
            'AppBundle:Email:picsure/rejected.txt.twig',
            ['policy' => $policy]
        );
        $policyService = $this->get('app.policy');
        $policyService->cancel($policy, Policy::CANCELLED_WRECKAGE);
        $this->getManager()->flush();
        try {
            $push = $this->get('app.push');
            $push->sendToUser(
                PushService::PSEUDO_MESSAGE_PICSURE,
                $policy->getUser(),
                StringHelper::join(
                    'Your phone did not pass validation. If you phone was damaged prior to your policy purchase, ',
                    'then it is crimial fraud to claim on our policy. Please contact us if you have purchased this ',
                    'policy by mistake.'
                ),
                null,
                null,
                $policy
            );
        } catch (\Exception $e) {
            $this->get('logger')->error(sprintf('Error in pic-sure push.'), ['exception' => $e]);
        }
        $picsureFiles = $policy->getPolicyPicSureFiles();
        if (count($picsureFiles) > 0) {
            $this->get('event_dispatcher')->dispatch(
                PicsureEvent::EVENT_REJECTED,
                new PicsureEvent($policy, $picsureFiles[0])
            );
        } else {
            $this->get('logger')->error(sprintf('Missing picture file in policy %s.', $policy->getId()));
        }
    }
}
