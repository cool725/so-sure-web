<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Invitation\Invitation;
use AppBundle\Document\Phone;
use AppBundle\Document\PhonePolicy;
use AppBundle\Form\Type\PhoneType;
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
        $phoneRepo = $dm->getRepository(Phone::class);
        $deviceAtlas = $this->get('app.deviceatlas');

        // TODO: Change to more friendly templates
        if (!$invitation) {
            throw $this->createNotFoundException('Unable to find invitation');
        } elseif ($invitation->isSingleUse() && $invitation->isProcessed()) {
            return $this->render('AppBundle:Invitation:processed.html.twig', [
                'invitation' => $invitation,
            ]);
        } elseif ($this->getUser() !== null) {
            // If user is on their mobile, use branch to redirect to app
            if ($deviceAtlas->isMobile()) {
                return $this->redirect($this->getParameter('branch_share_url'));
            }
            // otherwise, the standard invitation is ok for now
            // TODO: Once invitations can be accepted on the web,
            // we should use branch for everything
        }

        $policy = new PhonePolicy();
        if ($request->getMethod() == "GET") {
            $phone = $deviceAtlas->getPhone($request);
            /*
            if (!$phone) {
                $phone = $this->getDefaultPhone();
            }
            */
            if ($phone instanceof Phone) {
                $policy->setPhone($phone);
            }
        }

        $form = $this->createFormBuilder()
            ->add('decline', SubmitType::class, array(
                'label' => "No thanks!",
                'attr' => ['class' => 'btn btn-danger'],
            ))
            ->getForm();
        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneType::class, $policy)
            ->getForm();

        if ($request->request->has('launch_phone')) {
            $formPhone->handleRequest($request);
            if ($formPhone->isValid()) {
                if ($policy->getPhone()->getMemory()) {
                    return $this->redirectToRoute('quote_make_model_memory', [
                        'make' => $policy->getPhone()->getMake(),
                        'model' => $policy->getPhone()->getModel(),
                        'memory' => $policy->getPhone()->getMemory(),
                    ]);
                } else {
                    return $this->redirectToRoute('quote_make_model', [
                        'make' => $policy->getPhone()->getMake(),
                        'model' => $policy->getPhone()->getModel(),
                    ]);
                }
            }
        } else {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $invitationService = $this->get('app.invitation');
                $invitationService->reject($invitation);
                $this->addFlash(
                    'error',
                    'You have declined this invitation.'
                );

                return $this->redirectToRoute('invitation', ['id' => $id]);
            }
        }
        return array(
            'invitation' => $invitation,
            'form' => $form->createView(),
            'form_phone' => $formPhone->createView(),
        );
    }
}
