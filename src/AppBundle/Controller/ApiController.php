<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Form\Type\LaunchType;
use AppBundle\Document\User;
use AppBundle\Document\Phone;
use AppBundle\Document\Policy;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route("/api/v1")
 */
class ApiController extends BaseController
{
    /**
     * @Route("/quote", name="api_quote")
     */
    public function quoteAction(Request $request)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phones = $repo->findBy(['devices' => $request->get('device')]);
        if (!$phones || count($phones) == 0) {
            $phones = $repo->findBy(['make' => 'ALL']);
        }

        $quotes = [];
        foreach ($phones as $phone) {
            $quotes[] = [
                'monthly_premium' => $phone->getPolicyPrice(),
                'monthly_loss' => $phone->getLossPrice(),
                'yearly_premium' => $phone->getPolicyPrice() * 12,
                'yearly_loss' => $phone->getLossPrice() * 12,
                'phone' => $phone->asArray(),
            ];
        }

        return new JsonResponse([
            'quotes' => $quotes,
        ]);
    }
}
