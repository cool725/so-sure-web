<?php

namespace AppBundle\Controller;

use AppBundle\Document\User;
use FOS\UserBundle\Controller\ResettingController;

use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use FOS\UserBundle\Model\UserInterface;

use FOS\UserBundle\Event\FilterUserResponseEvent;
use FOS\UserBundle\Event\FormEvent;
use FOS\UserBundle\Event\GetResponseNullableUserEvent;
use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class FOSUserController extends ResettingController
{
    /**
     * Reset user password
     */
    public function resetAction(Request $request, $token)
    {
        \AppBundle\Classes\NoOp::ignore([$request]);

        /** @var \FOS\UserBundle\Form\Factory\FactoryInterface $formFactory */
        $formFactory = $this->get('fos_user.resetting.form.factory');
        /** @var \FOS\UserBundle\Model\UserManagerInterface $userManager */
        $userManager = $this->get('fos_user.user_manager');
        /** @var \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher */
        $dispatcher = $this->get('event_dispatcher');

        /** @var User $user */
        $user = $userManager->findUserByConfirmationToken($token);

        if (null == $user) {
            throw new NotFoundHttpException(sprintf(
                'The user with "confirmation token" does not exist for value "%s"',
                $token
            ));
        }

        if (!$user->isEnabled()) {
            throw new AccessDeniedHttpException(sprintf(
                'Please contact support@wearesosure.com to enable this account'
            ));
        }

        $event = new GetResponseUserEvent($user, $request);
        $dispatcher->dispatch(FOSUserEvents::RESETTING_RESET_INITIALIZE, $event);

        if (null !== $event->getResponse()) {
            return $event->getResponse();
        }

        $form = $formFactory->createForm();
        $form->setData($user);

        $form->handleRequest($request);

        // must be run after handle request such that the new password is present in plainPassword on the user
        $userService = $this->get('app.user');
        $userService->previousPasswordCheck($user);

        if ($form->isSubmitted() && $form->isValid() && $user->getPreviousPasswordCheck()) {
            $event = new FormEvent($form, $request);
            $dispatcher->dispatch(FOSUserEvents::RESETTING_RESET_SUCCESS, $event);

            $userManager->updateUser($user);

            if (null === $response = $event->getResponse()) {
                $url = $this->generateUrl('fos_user_profile_show');
                $response = new RedirectResponse($url);
            }

            $dispatcher->dispatch(
                FOSUserEvents::RESETTING_RESET_COMPLETED,
                new FilterUserResponseEvent($user, $request, $response)
            );

            return $response;
        } elseif ($user->getPreviousPasswordCheck() === false) {
            $this->addFlash(
                'error',
                'Sorry, but you can not re-use a previously used password.'
            );
        }

        return $this->render('FOSUserBundle:Resetting:reset.html.twig', array(
            'token' => $token,
            'form' => $form->createView(),
        ));
    }

    /**
     * check for valid csrf and send reset email
     */
    public function sendEmailAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        return parent::sendEmailAction($request);
    }
}
