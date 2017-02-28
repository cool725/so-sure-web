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
use AppBundle\Form\Type\PhoneType;
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
        $scode = null;
        try {
            $dm = $this->getManager();
            $repo = $dm->getRepository(SCode::class);
            $scode = $repo->findOneBy(['code' => $code]);
            $phoneRepo = $dm->getRepository(Phone::class);

            // make sure to get policy user in code first rather than in twig in case policy/user was deleted
            if (!$scode ||
                (in_array($scode->getType(), [SCode::TYPE_STANDARD, SCode::TYPE_MULTIPAY]) &&
                !$scode->getPolicy()->getUser())) {
                throw new \Exception('Unknown scode');
            }
        } catch (\Exception $e) {
            $scode = null;
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
        }

        if ($scode && $this->getUser()) {
            // Let the user just invite the person directly
            return new RedirectResponse($this->generateUrl('user_home'));
        }

        return array(
            'scode' => $scode,
        );
    }
}
