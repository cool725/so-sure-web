<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
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
            return $this->redirectToRoute('purchase_item', ['id' => $policy->getPhone()->getId()]);
        }

        return array(
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/{id}/", name="purchase_item")
     * @Template
     */
    public function purchaseItemAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        return array(
            'phone' => $phone,
        );
    }
}
