<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Policy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\PhonePolicy;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PicSureStatusType extends BaseType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('note', TextareaType::class)
            ->add('update', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            /** @var PhonePolicy $policy */
            $policy = $event->getData();
            $form = $event->getForm();
            $choices = [
            ];

            // rejected to invalid (screen cracked, etc)
            if (in_array($policy->getPicSureStatus(), [
                PhonePolicy::PICSURE_STATUS_REJECTED,
            ])) {
                $choices = [
                    PhonePolicy::PICSURE_STATUS_INVALID => PhonePolicy::PICSURE_STATUS_INVALID,
                ];
            }

            // not started, manual, invalid to approved/pre-approved (policy upgrades, etc)
            if (in_array($policy->getPicSureStatus(), [
                PhonePolicy::PICSURE_STATUS_MANUAL,
                PhonePolicy::PICSURE_STATUS_INVALID,
                null
            ])) {
                $choices = [
                    'Not started' => null,
                    PhonePolicy::PICSURE_STATUS_MANUAL => PhonePolicy::PICSURE_STATUS_MANUAL,
                    PhonePolicy::PICSURE_STATUS_INVALID => PhonePolicy::PICSURE_STATUS_INVALID,
                    PhonePolicy::PICSURE_STATUS_APPROVED => PhonePolicy::PICSURE_STATUS_APPROVED,
                    PhonePolicy::PICSURE_STATUS_PREAPPROVED => PhonePolicy::PICSURE_STATUS_PREAPPROVED,
                ];
            }

            // approved to not started for vouchers, etc
            if (in_array($policy->getPicSureStatus(), [
                PhonePolicy::PICSURE_STATUS_APPROVED,
            ])) {
                $choices = [
                    'Not started' => null,
                    PhonePolicy::PICSURE_STATUS_MANUAL => PhonePolicy::PICSURE_STATUS_MANUAL,
                    PhonePolicy::PICSURE_STATUS_INVALID => PhonePolicy::PICSURE_STATUS_INVALID,
                    PhonePolicy::PICSURE_STATUS_APPROVED => PhonePolicy::PICSURE_STATUS_APPROVED,
                    PhonePolicy::PICSURE_STATUS_PREAPPROVED => PhonePolicy::PICSURE_STATUS_PREAPPROVED,
                ];
            }

            // approved to not started for vouchers, etc
            if (in_array($policy->getPicSureStatus(), [
                PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED,
            ])) {
                $choices = [
                    'Not started' => null,
                    PhonePolicy::PICSURE_STATUS_APPROVED => PhonePolicy::PICSURE_STATUS_APPROVED,
                    PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED => PhonePolicy::PICSURE_STATUS_CLAIM_APPROVED,
                ];
            }

            $form->add('picSureStatus', ChoiceType::class, [
                'required' => true,
                'multiple' => false,
                'expanded' => false,
                'choices' => $choices,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PicSureStatus',
        ));
    }
}
