<?php

namespace AppBundle\Controller;

use AppBundle\Exception\InvalidEmailException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SCode;
use AppBundle\Document\Reward;
use AppBundle\Document\Connection\RewardConnection;
use AppBundle\Service\MixpanelService;
use AppBundle\Service\SixpackService;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class PromoController extends BaseController
{
    /**
     * @Route("/promo/{code}", name="promo")
     * @Template
     */
    public function promoAction(Request $request, $code)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Scode::class);
        $phoneRepo = $dm->getRepository(Phone::class);

        $scode = null;

        try {
            if ($scode = $repo->findOneBy(['code' => $code, 'active' => true, 'type' => Scode::TYPE_REWARD])) {
                $reward = $scode->getReward();
                if (!$reward || !$reward->getUser() || !$reward->isOpen(new \DateTime())) {
                    throw new \Exception('Unknown promo code');
                }
            }
        } catch (\Exception $e) {
            $scode = null;
        }

        $session = $this->get('session');
        $session->set('reward', $code);

        if ($scode && $request->getMethod() === "GET") {
            $this->get('app.mixpanel')->queuePersonProperties([
                'Attribution Invitation Method' => 'reward',
            ], true);
        }

        return [
            'scode'    => $scode,
            'use_code' => $code,
        ];
    }
}
