<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Policy;
use AppBundle\Document\Phone;
use AppBundle\Form\Type\PhoneType;

/**
 * @Route("/purchase")
 */
class PurchaseController extends BaseController
{
    /**
     * @Route("/", name="purchase")
     * @Template
     */
    public function indexAction(Request $request)
    {
        $policy = new Policy();
        $form = $this->createForm(PhoneType::class, $policy);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $dm = $this->getManager();
            $dm->persist($policy);
            $dm->flush();
            if ($form->get('dd')->isClicked()) {
                return $this->redirectToRoute('purchase_item_debit', ['id' => $policy->getId()]);
            } elseif ($form->get('credit')->isClicked()) {
                return $this->redirectToRoute('purchase_judopay', ['id' => $policy->getId()]);
            }
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/price/{id}/", name="price_item")
     * @Template
     */
    public function priceItemAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if (!$phone) {
            return new JsonResponse([], 404);
        }

        return new JsonResponse([
            'price' => $phone->getPolicyPrice(),
        ]);
    }
    
    /**
     * @Route("/dd/{id}/", name="purchase_item_debit")
     * @Template
     */
    public function purchaseDdItemAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        return array(
            'phone' => $policy->getPhone(),
        );
    }

    /**
     * @Route("/cc/success/", name="purchase_judopay_success")
     * @Template
     */
    public function purchaseJudoPaySuccessAction(Request $request)
    {
        return array();
    }    

    /**
     * @Route("/cc/fail/", name="purchase_judopay_fail")
     * @Template
     */
    public function purchaseJudoPayFailAction(Request $request)
    {
        return array();
    }    
    
    /**
     * @Route("/cc/{id}/", name="purchase_judopay")
     * @Template
     */
    public function purchaseJudoPayAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        $policyPrice = $policy->getPhone()->getPolicyPrice();
        $webpay = $this->get('app.judopay')->webpay($policyPrice, $request->getClientIp(), $request->headers->get('User-Agent'));

        return array(
            'form_action' => $webpay['post_url'],
            'reference' => $webpay['payment']->getReference(),
            'phone' => $policy->getPhone(),
        );
    }
}
