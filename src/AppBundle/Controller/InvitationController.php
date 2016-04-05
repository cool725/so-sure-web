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

class InvitationController extends BaseController
{
    /**
     * @Route("/invitation/{id}", name="invitation")
     * @Template
     */
    public function invitationAction($id)
    {
        $dm = $this->getManager();
        $repo = $dm->getRepository(Invitation::class);
        $invitation = $repo->find($id);
        
        // TODO: Change to more friendly templates
        if (!$invitation) {
            return $this->createNotFoundException('Unable to find invitation');
        } elseif ($invitation->isSingleUse() && $invitation->hasAccepted()) {
            return $this->render('Invitation/accepted.html.twig');
        } elseif ($this->getUser() !== null) {
            return $this->redirectToRoute('user_invitation', ['id' => $id]);
        }

        
        return array('invitation' => $invitation);
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
