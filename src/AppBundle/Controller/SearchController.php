<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;
// use AppBundle\Document\Opt\EmailOptIn;
// use AppBundle\Form\Type\EmailOptInType;
// use AppBundle\Form\Type\EmailOptOutType;
// use AppBundle\Repository\OptOut\EmailOptOutRepository;
// use AppBundle\Service\InvitationService;
// use AppBundle\Service\MailerService;
// use AppBundle\Service\RateLimitService;
// use AppBundle\Service\RequestService;
// use PHPStan\Rules\Arrays\AppendedArrayItemTypeRule;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

// use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
// use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
// use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
// use Symfony\Component\Form\Extension\Core\Type\EmailType;
// use Symfony\Component\Form\Extension\Core\Type\HiddenType;
// use Symfony\Component\Form\Extension\Core\Type\SubmitType;
// use Symfony\Component\Form\Extension\Core\Type\TextType;
// use Symfony\Component\Form\Extension\Core\Type\TextareaType;
// use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
// use Symfony\Component\HttpFoundation\Session\Session;
// use Symfony\Component\HttpFoundation\JsonResponse;
// use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

// use AppBundle\Form\Type\LaunchType;
// use AppBundle\Form\Type\LeadEmailType;
// use AppBundle\Form\Type\RegisterUserType;
use AppBundle\Form\Type\PhoneMakeType;
// use AppBundle\Form\Type\PhoneType;
// use AppBundle\Form\Type\SmsAppLinkType;
// use AppBundle\Form\Type\ClaimFnolType;

// use AppBundle\Document\Form\Register;
use AppBundle\Document\Form\PhoneMake;
// use AppBundle\Document\Form\ClaimFnol;
// use AppBundle\Document\User;
// use AppBundle\Document\Claim;
// use AppBundle\Document\Lead;
use AppBundle\Document\Phone;

// use AppBundle\Document\PhonePolicy;
// use AppBundle\Document\Policy;
use AppBundle\Document\PhoneTrait;

// use AppBundle\Document\Opt\EmailOptOut;
// use AppBundle\Document\Invitation\EmailInvitation;
// use AppBundle\Document\PolicyTerms;

// use AppBundle\Service\MixpanelService;
// use AppBundle\Service\SixpackService;

class SearchController extends BaseController
{
    use PhoneTrait;


    /**
     * @Route("/phone-search", name="phone_search")
     * @Route("/phone-search/{type}", name="phone_search_type")
     * @Route("/phone-search/{type}/{id}", name="phone_search_type_id")
     * @Template()
     */
    public function phoneSearchAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        if ($id) {
            $phone = $phoneRepo->find($id);
        }

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_policy');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
           // return $this->redirectToRoute('learn_more_phone', ['id' => $id]);
        }

        return [
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }

    /**
     * @Route("/phone-search-dropdown", name="phone_search_dropdown")
     * @Route("/phone-search-dropdown/{type}", name="phone_search_dropdown_type")
     * @Route("/phone-search-dropdown/{type}/{id}", name="phone_search_dropdown_type_id")
     * @Template()
     */
    public function phoneSearchDropdownAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $phoneMake = new PhoneMake();
        if ($id) {
            $phone = $phoneRepo->find($id);
            if ($phone) {
                $phoneMake->setMake($phone->getMake());
            }
        }

        if ($phone && in_array($type, ['purchase-select', 'purchase-change'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }

            // don't check for partial partial as selected phone may be different from partial policy phone
            return $this->redirectToRoute('purchase_step_policy');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('select_phone_make_dropdown'),
            ])
            ->getForm();
        if ('POST' === $request->getMethod()) {
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'memory');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw new \Exception('unknown phone');
                    }
                    if ($phone->getMemory()) {
                        return $this->redirectToRoute('quote_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory(),
                        ]);
                    } else {
                        return $this->redirectToRoute('quote_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                        ]);
                    }
                }
            }
        }

        return [
            'form_phone' => $formPhone->createView(),
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }
}
