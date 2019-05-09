<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SCode;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class SCodeController extends BaseController
{
    /**
     * @Route("/scode/{code}", name="scode")
     * @Template
     */
    public function scodeAction(Request $request, $code)
    {
        $geoip = $this->get('app.geoip');
        //$ip = "82.132.221.1";
        $ip = $request->getClientIp();
        $isUK = $geoip->findCountry($ip) == "GB";

        $dm = $this->getManager();
        $repo = $dm->getRepository(SCode::class);
        $phoneRepo = $dm->getRepository(Phone::class);

        $scode = null;
        try {
            if ($scode = $repo->findOneBy(['code' => $code])) {
                // make sure to get policy user in code first rather than in twig in case policy/user was deleted
                if (in_array($scode->getType(), [SCode::TYPE_STANDARD, SCode::TYPE_MULTIPAY])) {
                    if (!$scode->getPolicy() || !$scode->getPolicy()->getUser()) {
                        throw new \Exception('Unknown scode');
                    }
                } elseif (in_array($scode->getType(), [SCode::TYPE_REWARD])) {
                    if (!$scode->getReward() || !$scode->getReward()->getUser()) {
                        throw new \Exception('Unknown scode');
                    }
                }
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        if ($scode && !$isUK) {
            // @codingStandardsIgnoreStart
            $this->addFlash('error-raw', sprintf(
                '<i class="fa fa-warning"></i> Sorry, we currently only offer policies to UK residents. If you are a UK resident, you may continue below.'
            ));
            // @codingStandardsIgnoreEnd
        }

        $session = $this->get('session');
        $session->set('scode', $code);

        if ($scode && $request->getMethod() === "GET") {
            $this->get('app.mixpanel')->queueTrackWithUtm(MixpanelService::EVENT_INVITATION_PAGE, [
                'Invitation Method' => 'scode',
            ]);
            $this->get('app.mixpanel')->queuePersonProperties([
                'Attribution Invitation Method' => 'scode',
            ], true);
            $this->get('app.sixpack')->convertByClientId(
                $code,
                SixpackService::EXPERIMENT_APP_SHARE_METHOD
            );
        }

        if ($scode && $this->getUser()) {
            // Let the user just invite the person directly
            return new RedirectResponse($this->generateUrl('user_home'));
        }

        // $landingText = $this->sixpack(
        //     $request,
        //     SixpackService::EXPERIMENT_SCODE_LANDING_TEXT,
        //     ['scode-landing-text-a', 'scode-landing-text-b']
        // );

        return array(
            'scode' => $scode,
            // 'landing_text' => $landingText,
        );
    }
}
