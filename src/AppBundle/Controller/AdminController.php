<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use AppBundle\Document\Phone;
use AppBundle\Document\User;
use AppBundle\Form\Type\PhoneType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineODMMongoDBAdapter;

/**
 * @Route("/admin")
 */
class AdminController extends BaseController
{
    /**
     * @Route("/", name="admin_home")
     * @Template
     */
    public function indexAction()
    {
        return [];
    }

    /**
     * @Route("/phones", name="admin_phones")
     * @Template
     */
    public function phonesAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $pager = $this->pager($request, $repo->createQueryBuilder());

        return [
            'phones' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'pager' => $pager
        ];
    }

    /**
     * @Route("/phone", name="admin_phone_add")
     * @Method({"POST"})
     */
    public function phoneAddAction(Request $request)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $devices = explode(",", $request->get('devices'));
        $devices = array_filter(array_map('trim', $devices));
        $phone = new Phone();
        $phone->setMake($request->get('make'));
        $phone->setModel($request->get('model'));
        $phone->setDevices($devices);
        $phone->setMemory($request->get('memory'));
        $phone->setPolicyPrice($request->get('policy'));
        $phone->setLossPrice($request->get('loss'));
        $dm->persist($phone);
        $dm->flush();
        $this->addFlash(
            'notice',
            'Your changes were saved!'
        );

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }
    
    /**
     * @Route("/phone/{id}", name="admin_phone_edit")
     * @Method({"POST"})
     */
    public function phoneEditAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            $devices = explode(",", $request->get('devices'));
            $devices = array_filter(array_map('trim', $devices));
            $phone->setMake($request->get('make'));
            $phone->setModel($request->get('model'));
            $phone->setDevices($devices);
            $phone->setMemory($request->get('memory'));
            $phone->setPolicyPrice($request->get('policy'));
            $phone->setLossPrice($request->get('loss'));
            $dm->flush();
            $this->addFlash(
                'notice',
                'Your changes were saved!'
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/phone/{id}", name="admin_phone_delete")
     * @Method({"DELETE"})
     */
    public function phoneDeleteAction(Request $request, $id)
    {
        if (!$this->isCsrfTokenValid('default', $request->get('token'))) {
            throw new \InvalidArgumentException('Invalid csrf token');
        }

        $dm = $this->getManager();
        $repo = $dm->getRepository(Phone::class);
        $phone = $repo->find($id);
        if ($phone) {
            $dm->remove($phone);
            $dm->flush();
            $this->addFlash(
                'notice',
                'Phone deleted!'
            );
        }

        return new RedirectResponse($this->generateUrl('admin_phones'));
    }

    /**
     * @Route("/users", name="admin_users")
     * @Template
     */
    public function usersAction(Request $request)
    {
        $csrf = $this->get('form.csrf_provider');
        $dm = $this->getManager();
        $repo = $dm->getRepository(User::class);
        $users = $repo->createQueryBuilder();
        $pager = $this->pager($request, $users);

        return [
            'users' => $pager->getCurrentPageResults(),
            'token' => $csrf->generateCsrfToken('default'),
            'pager' => $pager
        ];
    }
}
