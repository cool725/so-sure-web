<?php

namespace AppBundle\Controller;

use AppBundle\Classes\SoSure;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use AppBundle\Form\Type\PhoneMakeType;
use AppBundle\Form\Type\PhoneCombinedType;
use AppBundle\Form\Type\ModelDropdownType;

use AppBundle\Document\Form\PhoneMake;
use AppBundle\Document\Form\PhoneCombined;
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
        $price = null;
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
                    $this->setPhoneSession($request, $phone);
                    $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
                    $event = MixpanelService::EVENT_QUOTE_TO_DETAILS;
                    $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                        'Device Selected' => $phone->__toString(),
                        'Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ]);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        'First Device Selected' => $phone->__toString(),
                        'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ], true);
                    return $this->redirectToRoute('purchase', [], 301);
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
     * @Route("/phone-search-dropdown-two", name="phone_search_dropdown_two")
     * @Route("/phone-search-dropdown-two/{type}", name="phone_search_dropdown_type_two")
     * @Route("/phone-search-dropdown-two/{type}/{id}", name="phone_search_dropdown_type_id_two")
     * @Template()
     */
    public function phoneSearchDropdownCardAction(
        Request $request,
        $type = null,
        $id = null,
        $source = null
    ) {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $price = null;
        $phoneMake = new PhoneMake();
        if ($id) {
            $phone = $phoneRepo->find($id);
            if ($phone) {
                $phoneMake->setMake($phone->getMake());
            }
        }
        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('phone_search_dropdown_two'),
            ])
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $email = $this->getDataString($request->get('launch_phone'), 'email');
            $session = $this->get('session');
            $session->set('email', $email);
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'memory');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw new \Exception('unknown phone');
                    }
                    $quoteUrl = $this->setPhoneSession($request, $phone);
                    $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
                    if ($email) {
                        $lead = new Lead();
                        $lead->setEmail($email);
                        // TODO: This could be done better
                        $lead->setSource(Lead::SOURCE_QUOTE_PHONE_LANDING_PAGE);
                        if ($source == 'make') {
                            $lead->setSource(Lead::SOURCE_QUOTE_MAKE_LANDING_PAGE);
                        }
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
                        $utm = '?utm_source=quote_email_homepage&utm_medium=email&utm_content=email_required';
                        $mailer = $this->get('app.mailer');
                        // @codingStandardsIgnoreStart
                        $mailer->sendTemplate(
                            sprintf('Your saved so-sure quote for %s', $phone),
                            $lead->getEmail(),
                            'AppBundle:Email:quote/priceGuarantee.html.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl.$utm, 'price' => $price->getMonthlyPremiumPrice()],
                            'AppBundle:Email:quote/priceGuarantee.txt.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl.$utm, 'price' => $price->getMonthlyPremiumPrice()]
                        );
                        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                        $this->get('app.mixpanel')->queuePersonProperties([
                            '$email' => $lead->getEmail()
                        ], true);
                        $this->addFlash('success', sprintf(
                            "Thanks! An email of your quote is on it's way to: %s", $lead->getEmail()
                        ));
                        // @codingStandardsIgnoreEnd
                    }
                    $this->setPhoneSession($request, $phone);
                    $event = MixpanelService::EVENT_HOME_TO_DETAILS;
                    $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                        'Device Selected' => $phone->__toString(),
                        'Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ]);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        'First Device Selected' => $phone->__toString(),
                        'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ], true);
                    return $this->redirectToRoute('purchase', [], 301);
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
     * @Route("/phone-search-dropdown-three", name="phone_search_dropdown_three")
     * @Route("/phone-search-dropdown-three/{type}", name="phone_search_dropdown_type_three")
     * @Route("/phone-search-dropdown-three/{type}/{id}", name="phone_search_dropdown_type_id_three")
     * @Template()
     */
    public function phoneSearchDropdownCardLandingAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $price = null;
        $phoneMake = new PhoneMake();
        if ($id) {
            $phone = $phoneRepo->find($id);
            if ($phone) {
                $phoneMake->setMake($phone->getMake());
            }
        }
        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneMakeType::class, $phoneMake, [
                'action' => $this->generateUrl('phone_search_dropdown_three'),
            ])
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $email = $this->getDataString($request->get('launch_phone'), 'email');
            $session = $this->get('session');
            $session->set('email', $email);
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'memory');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw new \Exception('unknown phone');
                    }
                    $quoteUrl = $this->setPhoneSession($request, $phone);
                    $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
                    if ($email) {
                        $lead = new Lead();
                        $lead->setEmail($email);
                        $lead->setSource(Lead::SOURCE_QUOTE_EMAIL_HOME_REQUIRED);
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
                        $utm = '?utm_source=quote_email_homepage&utm_medium=email&utm_content=email_required';
                        $mailer = $this->get('app.mailer');
                        // @codingStandardsIgnoreStart
                        $mailer->sendTemplate(
                            sprintf('Your saved so-sure quote for %s', $phone),
                            $lead->getEmail(),
                            'AppBundle:Email:quote/priceGuarantee.html.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl.$utm, 'price' => $price->getMonthlyPremiumPrice()],
                            'AppBundle:Email:quote/priceGuarantee.txt.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl.$utm, 'price' => $price->getMonthlyPremiumPrice()]
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
                    $this->setPhoneSession($request, $phone);
                    $event = MixpanelService::EVENT_QUOTE_TO_DETAILS;
                    $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                        'Device Selected' => $phone->__toString(),
                        'Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ]);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        'First Device Selected' => $phone->__toString(),
                        'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ], true);
                    return $this->redirectToRoute('purchase', [], 301);
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
        $price = null;
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
                    $this->setPhoneSession($request, $phone);
                    $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
                    $event = MixpanelService::EVENT_MODEL_PAGE_TO_DETAILS;
                    $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                        'Device Selected' => $phone->__toString(),
                        'Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ]);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        'First Device Selected' => $phone->__toString(),
                        'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ], true);
                    return $this->redirectToRoute('purchase', [], 301);
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
        $price = null;
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
                    $this->setPhoneSession($request, $phone);
                    $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
                    $event = MixpanelService::EVENT_MODEL_PAGE_TO_DETAILS;
                    $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                        'Device Selected' => $phone->__toString(),
                        'Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ]);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        'First Device Selected' => $phone->__toString(),
                        'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ], true);
                    return $this->redirectToRoute('purchase', [], 301);
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
     * @Route("/phone-search-combined", name="phone_search_combined")
     * @Route("/phone-search-combined/{type}", name="phone_search_combined_type")
     * @Route("/phone-search-combined/{type}/{id}", name="phone_search_combined_type_id")
     * @Template()
     */
    public function phoneSearchCombinedAction(Request $request, $type = null, $id = null)
    {
        $dm = $this->getManager();
        $phoneRepo = $dm->getRepository(Phone::class);
        $phone = null;
        $price = null;
        $phoneMake = new PhoneCombined();
        if ($id) {
            $phone = $phoneRepo->find($id);
            if ($phone) {
                $phoneMake->setMake($phone->getMake());
            }
        }
        $formPhone = $this->get('form.factory')
            ->createNamedBuilder('launch_phone', PhoneCombinedType::class, $phoneMake, [
                'action' => $this->generateUrl('phone_search_combined'),
            ])
            ->getForm();

        if ('POST' === $request->getMethod()) {
            $email = $this->getDataString($request->get('launch_phone'), 'email');
            $session = $this->get('session');
            $session->set('email', $email);
            if ($request->request->has('launch_phone')) {
                $phoneId = $this->getDataString($request->get('launch_phone'), 'model');
                if ($phoneId) {
                    $phone = $phoneRepo->find($phoneId);
                    if (!$phone) {
                        throw new \Exception('unknown phone');
                    }
                    $quoteUrl = $this->setPhoneSession($request, $phone);
                    $price = $phone->getCurrentPhonePrice(PhonePrice::STREAM_MONTHLY);
                    if ($email) {
                        $lead = new Lead();
                        $lead->setEmail($email);
                        $lead->setSource(Lead::SOURCE_QUOTE_EMAIL_HOME_REQUIRED);
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
                        $utm = '?utm_source=quote_email_homepage&utm_medium=email&utm_content=email_required';
                        $mailer = $this->get('app.mailer');
                        // @codingStandardsIgnoreStart
                        $mailer->sendTemplate(
                            sprintf('Your saved so-sure quote for %s', $phone),
                            $lead->getEmail(),
                            'AppBundle:Email:quote/priceGuarantee.html.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl.$utm, 'price' => $price->getMonthlyPremiumPrice()],
                            'AppBundle:Email:quote/priceGuarantee.txt.twig',
                            ['phone' => $phone, 'days' => $days, 'quoteUrl' => $quoteUrl.$utm, 'price' => $price->getMonthlyPremiumPrice()]
                        );
                        $this->get('app.mixpanel')->queueTrack(MixpanelService::EVENT_LEAD_CAPTURE);
                        $this->get('app.mixpanel')->queuePersonProperties([
                            '$email' => $lead->getEmail()
                        ], true);
                        $this->addFlash('success', sprintf(
                            "Thanks! An email of your quote is on it's way to: %s", $lead->getEmail()
                        ));
                        // @codingStandardsIgnoreEnd
                    }
                    $this->setPhoneSession($request, $phone);
                    $event = MixpanelService::EVENT_HOME_TO_DETAILS;
                    $this->get('app.mixpanel')->queueTrackWithUtm($event, [
                        'Device Selected' => $phone->__toString(),
                        'Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ]);
                    $this->get('app.mixpanel')->queuePersonProperties([
                        'First Device Selected' => $phone->__toString(),
                        'First Monthly Cost' => $price->getMonthlyPremiumPrice(),
                    ], true);
                    return $this->redirectToRoute('purchase', [], 301);
                }
            }
        }

        return [
            'form_phone_combined' => $formPhone->createView(),
            'phones' => $this->getPhonesArray(),
            'type' => $type,
            'phone' => $phone,
        ];
    }
}
