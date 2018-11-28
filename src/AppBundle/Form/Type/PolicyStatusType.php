<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\PhonePolicy;
use AppBundle\Document\SalvaPhonePolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PolicyStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('status', ChoiceType::class, [
                'choices' => [
                    PhonePolicy::STATUS_CANCELLED,
                    PhonePolicy::STATUS_ACTIVE,
                    PhonePolicy::STATUS_DECLINED_RENEWAL,
                    PhonePolicy::STATUS_EXPIRED,
                    PhonePolicy::STATUS_EXPIRED_CLAIMABLE,
                    PhonePolicy::STATUS_EXPIRED_WAIT_CLAIM,
                    PhonePolicy::STATUS_MULTIPAY_REJECTED,
                    PhonePolicy::STATUS_MULTIPAY_REQUESTED,
                    PhonePolicy::STATUS_PENDING,
                    PhonePolicy::STATUS_PENDING_RENEWAL,
                    PhonePolicy::STATUS_RENEWAL,
                    PhonePolicy::STATUS_UNPAID,
                    PhonePolicy::STATUS_UNRENEWED,
                    null => 'null'
                ],
                'choice_label' => function ($choice, $key, $value) {
                    return $value;
                },
                'preferred_choices' => [
                    PhonePolicy::STATUS_PENDING,
                    'null',
                ],
                'placeholder' => 'Choose a status',
                'required' => true
            ])
            ->add('update', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\PhonePolicy',
        ));
    }
}
