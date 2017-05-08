<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use AppBundle\Classes\ApiErrorCode;
use AppBundle\Document\User;
use AppBundle\Document\SCode;
use AppBundle\Document\Policy;
use AppBundle\Service\MixpanelService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use AppBundle\Document\Invitation\EmailInvitation;

/**
 * @Route("/ops")
 */
class OpsController extends BaseController
{
    /**
     * @Route("/status", name="ops_status")
     * @Template
     */
    public function statusAction()
    {
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(User::class);
            $user = $repo->find(1);

            // Ensure there's a bit of free disk space
            $temp = tmpfile();
            fwrite($temp, "t");
            fclose($temp);

            $response = new JsonResponse([
                'status' => 'Ok',
            ]);

            // Disable HSTS for status check - otherwise elb healthcheck will fail
            $response->headers->set('Strict-Transport-Security', 'max-age=0');

            return $response;
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'Error',
            ], 500);
        }
    }

    /**
     * @Route("/exception", name="ops_exception")
     */
    public function exceptionAction()
    {
        throw new \Exception('Exception');
    }

    /**
     * @Route("/exception503", name="ops_exception_503")
     */
    public function exceptionDeniedAction()
    {
        throw new HttpException(503);
    }

    /**
     * @Route("/pages", name="ops_pages")
     * @Template()
     */
    public function validatePagesAction()
    {
        if ($this->isProduction()) {
            throw $this->createAccessDeniedException('Only for dev use');
        }

        $dm = $this->getManager();
        $scodeRepo = $dm->getRepository(SCode::class);
        $scode = $scodeRepo->findOneBy(['active' => true, 'type' => 'standard']);

        $invitationRepo = $dm->getRepository(EmailInvitation::class);
        $invitation = $invitationRepo->findOneBy(['accepted' => null, 'rejected' => null, 'cancelled' => null]);

        $policyRepo = $dm->getRepository(Policy::class);
        $unpaidPolicy = $policyRepo->findOneBy(['status' => Policy::STATUS_UNPAID]);
        $validPolicies = $policyRepo->findBy(['status' => Policy::STATUS_ACTIVE]);
        $position = rand(1, count($validPolicies));
        foreach ($validPolicies as $validPolicy) {
            if ($position <= 0) {
                break;
            }
            $position--;
        }
        $cancelledPolicy = $policyRepo->findOneBy(['status' => Policy::STATUS_CANCELLED]);

        return [
            'scode' => $scode->getCode(),
            'invitation' => $invitation,
            'unpaid_policy' => $unpaidPolicy,
            'valid_policy' => $validPolicy,
            'cancelled_policy' => $cancelledPolicy,
        ];
    }

    /**
     * @Route("/track/invite/{event}", name="ops_track_invite")
     * @Route("/track/{event}", name="ops_track")
     */
    public function trackAction(Request $request, $event)
    {
        if ($request->get('_route') == 'ops_track_invite') {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_INVITE, [
                'Invitation Method' => 'web',
                'Shared Bundle' => $event
            ]);
        } else {
            $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_TEST, ['Test Name' => $event]);
        }

        return $this->getErrorJsonResponse(ApiErrorCode::SUCCESS, 'Queued', 200);
    }
}
