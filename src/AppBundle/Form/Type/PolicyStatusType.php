<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\PhonePolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PolicyStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('status', ChoiceType::class, [
                'choices' => [
                    PhonePolicy::STATUS_ACTIVE,
                    PhonePolicy::STATUS_UNPAID,
                    null => 'null'
                ],
                'choice_label' => function ($choice, $key, $value) {
                    return $choice;
                },
                'preferred_choices' => [
                    PhonePolicy::STATUS_PENDING,
                    'null'
                ],
                'placeholder' => 'Choose a status',
                'required' => true
            ])
            ->add('update', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            /** @var PhonePolicy $phonePolicy */
            $phonePolicy = $event->getData();

            if (!$phonePolicy->getStatus()) {
                $choices['null'] = null;
            } else {
                $choices[$phonePolicy->getStatus()] = $phonePolicy->getStatus();
            }

            if ($phonePolicy->getStatus() === PhonePolicy::STATUS_ACTIVE) {
                $choices[PhonePolicy::STATUS_UNPAID] = PhonePolicy::STATUS_UNPAID;
            }

            if ($phonePolicy->getStatus() === PhonePolicy::STATUS_UNPAID) {
                $choices[PhonePolicy::STATUS_ACTIVE] = PhonePolicy::STATUS_ACTIVE;
            }

            if ($phonePolicy->getStatus() === PhonePolicy::STATUS_PENDING) {
                $choices['null'] = null;
            }

            if ($phonePolicy->getStatus() === null) {
                $choices[PhonePolicy::STATUS_PENDING] = PhonePolicy::STATUS_PENDING;
            }

            $form->add('status', ChoiceType::class, [
                'choices' => $choices,
                'choice_label' => function ($choice, $key, $value) {
                    return $key;
                },
                'preferred_choices' => $phonePolicy->getStatus() ?? 'null',
                'placeholder' => 'Choose a status',
                'required' => true
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\PhonePolicy',
        ));
    }
}
