<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Invitation\Invitation;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

class InvitationController extends BaseController
{
    /**
     * @Route("/invitation/{id}", name="invitation")
     * @Template
     */
    public function invitationAction(Request $request, $id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Invitation::class);
        $invitation = $repo->find($id);
        
        // TODO: Change to more friendly templates
        if (!$invitation) {
            throw $this->createNotFoundException('Unable to find invitation');
        } elseif ($invitation->isSingleUse() && $invitation->isProcessed()) {
            return $this->render('AppBundle:Invitation:processed.html.twig');
        } elseif ($this->getUser() !== null) {
            return $this->redirectToRoute('user_invitation', ['id' => $id]);
        }

        $form = $this->createFormBuilder()
            ->add('decline', SubmitType::class, array(
                'label' => "I'm not interested",
                'attr' => ['class' => 'btn btn-danger'],
            ))
            ->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $invitationService = $this->get('app.invitation');
            $invitationService->reject($invitation);

            return $this->render('AppBundle:Invitation:processed.html.twig');
        }
        return array(
            'invitation' => $invitation,
            'form' => $form->createView(),
        );
    }

    /**
     * @Route("/user/invitation/{id}", name="user_invitation")
     * @Template
     */
    public function userInvitationAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Invitation::class);
        $invitation = $repo->find($id);

        return array();
    }
}
