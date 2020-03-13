<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use AppBundle\Form\Type\PhoneMakeType;
use AppBundle\Form\Type\ModelDropdownType;

use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\Phone;
use AppBundle\Document\PhoneTrait;
use AppBundle\Document\Lead;
use AppBundle\Document\PhonePrice;

use AppBundle\Service\MixpanelService;

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
            return $this->redirectToRoute('purchase_step_phone');
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
            return $this->redirectToRoute('purchase_step_phone');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('phone_search_dropdown'),
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
                        return $this->redirectToRoute('phone_insurance_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory()
                        ], 301);
                    } else {
                        return $this->redirectToRoute('phone_insurance_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel()
                        ], 301);
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

    /**
     * @Route("/phone-search-dropdown", name="phone_search_dropdown")
     * @Route("/phone-search-dropdown/{type}", name="phone_search_dropdown_type")
     * @Route("/phone-search-dropdown/{type}/{id}", name="phone_search_dropdown_type_id")
     * @Template()
     */
    public function phoneSearchDropdownCardAction(Request $request, $type = null, $id = null)
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

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('phone_search_dropdown'),
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
                    $email = $this->getDataString($request->get('launch_phone'), 'email');
                    if ($email) {
                        $lead = new Lead();
                        $lead->setEmail($email);
                        $lead->setSource(Lead::SOURCE_QUOTE_EMAIL_HOME);
                        $leadRepo = $dm->getRepository(Lead::class);
                        $existingLead = $leadRepo->findOneBy(['email' => mb_strtolower($lead->getEmail())]);
                        if (!$existingLead) {
                            $dm->persist($lead);
                            $dm->flush();
                        } else {
                            $lead = $existingLead;
                        }
                        $days = new \DateTime();
                        $days = $days->add(new \DateInterval(sprintf('P%dD', 1)));
                        $quoteUrl = $this->setPhoneSession($request, $phone);
                        $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
                        $mailer = $this->get('app.mailer');
                        // @codingStandardsIgnoreStart
                        $mailer->sendTemplate(
                            sprintf('Your saved so-sure quote for %s', $phone),
                            $lead->getEmail(),
                            'AppBundle:Email:quote/priceGuarantee.html.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl, 'price' => $price->getMonthlyPremiumPrice()],
                            'AppBundle:Email:quote/priceGuarantee.txt.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl, 'price' => $price->getMonthlyPremiumPrice()]
                        );
                        // @codingStandardsIgnoreEnd
                        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                        $this->get('app.mixpanel')->queuePersonProperties([
                            '$email' => $lead->getEmail()
                        ], true);
                        $this->addFlash('success', sprintf(
                            "Thanks! An email of your quote is on it's way"
                        ));
                    }
                    if ($phone->getMemory()) {
                        return $this->redirectToRoute('phone_insurance_make_model_memory', [
                            'make' => $phone->getMakeCanonical(),
                            'model' => $phone->getEncodedModelCanonical(),
                            'memory' => $phone->getMemory()
                        ], 301);
                    } else {
                        return $this->redirectToRoute('phone_insurance_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel()
                        ], 301);
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

    /**
     * @Route("/model-search-dropdown", name="model_search_dropdown")
     * @Route("/model-search-dropdown/{type}", name="model_search_dropdown_type")
     * @Route("/model-search-dropdown/{type}/{id}", name="model_search_dropdown_type_id")
     * @Template()
     */
    public function modelSearchDropdownAction(Request $request, $type = null, $id = null)
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
            return $this->redirectToRoute('purchase_step_phone');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('model_search_dropdown'),
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
                        return $this->redirectToRoute('phone_insurance_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory(),
                        ], 301);
                    } else {
                        return $this->redirectToRoute('phone_insurance_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                        ], 301);
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

    /**
     * @Route("/memory-search-dropdown", name="memory_search_dropdown")
     * @Route("/memory-search-dropdown/{type}", name="memory_search_dropdown_type")
     * @Route("/memory-search-dropdown/{type}/{id}", name="memory_search_dropdown_type_id")
     * @Template()
     */
    public function memorySearchDropdownAction(Request $request, $type = null, $id = null)
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
            return $this->redirectToRoute('purchase_step_phone');
        } elseif ($phone && in_array($type, ['learn-more'])) {
            if ($session = $request->getSession()) {
                $session->set('quote', $phone->getId());
            }
        }

        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('memory_search_dropdown'),
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
                        return $this->redirectToRoute('phone_insurance_make_model_memory', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                            'memory' => $phone->getMemory(),
                        ], 301);
                    } else {
                        return $this->redirectToRoute('phone_insurance_make_model', [
                            'make' => $phone->getMake(),
                            'model' => $phone->getEncodedModel(),
                        ], 301);
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
