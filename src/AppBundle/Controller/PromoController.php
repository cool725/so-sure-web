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
        $repo = $dm->getRepository(Reward::class);
        $phoneRepo = $dm->getRepository(Phone::class);

        $reward = null;

        try {
            if ($reward = $repo->findOneBy(['code' => $code])) {
                if (in_array($reward->getType(), [SCode::TYPE_REWARD])) {
                    if (!$reward->getReward() || !$reward->getReward()->getUser()) {
                        throw new \Exception('Unknown promo code');
                    }
                }
            }
        } catch (\Exception $e) {
            $reward = null;
        }

        $session = $this->get('session');
        $session->set('reward', $code);

        if ($reward && $request->getMethod() === "GET") {
            $this->get('app.mixpanel')->queuePersonProperties([
                'Attribution Invitation Method' => 'promo',
            ], true);
        }

        return [
            'promo'    => $reward,
            'use_code' => $code,
        ];
    }
}
