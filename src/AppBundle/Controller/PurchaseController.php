<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Phone;
use AppBundle\Form\Type\BasicUserType;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * @Route("/purchase")
 */
class PurchaseController extends BaseController
{
    /**
     * @Route("/", name="purchase")
     * @Template
     */
    public function indexAction()
    {
        throw $this->createAccessDeniedException('Coming soon');

        $phone = $this->getSessionPhone();
        if (!$phone) {
            return $this->redirectToRoute('quote');
        }

        return array(
            'phone' => $phone
        );
    }

    private function getSessionPhone()
    {
        $session = new Session();
        $phoneId = $session->get('quote');

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($phoneId);

        return $phone;
    }

    /**
     * @Route("/dd/", name="purchase_item_debit")
     * @Template
     */
    public function purchaseDdItemAction(Request $request)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $phone = $this->getSessionPhone();
        if (!$phone) {
            return $this->redirectToRoute('quote');
        }

        $user = $this->getUser();
        $form = $this->createForm(BasicUserType::class, $user);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $dm = $this->getManager();
            $dm->flush();

            $this->redirectToRoute('purchase_item_debit_address');
        }

        return array(
            'phone' => $phone,
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/dd/address", name="purchase_item_debit_address")
     * @Template
     */
    public function purchaseDdItemAddressAction(Request $request)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $phone = $this->getSessionPhone();
        if (!$phone) {
            return $this->redirectToRoute('quote');
        }

        $dm = $this->getManager();
        $user = $this->getUser();
        if (!$address = $user->getBillingAddress()) {
            $address = new Address();
            $address->setType(Address::Billing);
            $address->setUser($user);
        }

        $form = $this->createForm(AddressType::class, $address);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $dm->persist($address);
            $dm->flush();

            //$this->redirectToRoute('purchase_item_debit_address');
        }

        return array(
            'phone' => $phone,
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/cc/success/", name="purchase_judopay_receive_success")
     * @Template
     * @Method({"POST"})
     */
    public function purchaseJudoPayReceiveSuccessAction(Request $request)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $policy = $this->get('app.judopay')->paymentSuccess(
            $request->get('Reference'),
            $request->get('ReceiptId'),
            $request->get('CardToken')
        );

        return $this->redirectToRoute('purchase_judopay_success', ['id' => $policy->getId()]);
    }

    /**
     * @Route("/cc/success/{id}", name="purchase_judopay_success")
     * @Template
     * @Method({"GET"})
     */
    public function purchaseJudoPaySuccessAction($id)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $dm = $this->getManager();
        $repo = $dm->getRepository(Policy::class);
        $policy = $repo->find($id);
        if (!$policy) {
            throw new \Exception('Unable to locate policy');
        }
        if ($policy->getUser()->getId() != $this->getUser()->getId()) {
            throw new \Exception('Invalid policy');
        }

        return array('policy' => $policy);
    }
    
    /**
     * @Route("/cc/fail/", name="purchase_judopay_receive_fail")
     * @Template
     */
    public function purchaseJudoPayFailAction()
    {
        throw $this->createAccessDeniedException('Coming soon');

        return array();
    }

    /**
     * @Route("/cc/", name="purchase_judopay")
     * @Template
     */
    public function purchaseJudoPayAction(Request $request)
    {
        throw $this->createAccessDeniedException('Coming soon');

        $phone = $this->getSessionPhone();
        if (!$phone) {
            return $this->redirectToRoute('quote');
        }

        // TODO: Lost addition?
        $webpay = $this->get('app.judopay')->webpay(
            $this->getUser(),
            $phone,
            $phone->getCurrentPhonePrice(),
            $request->getClientIp(),
            $request->headers->get('User-Agent')
        );

        return array(
            'form_action' => $webpay['post_url'],
            'reference' => $webpay['payment']->getReference(),
            'phone' => $phone,
        );
    }
}
