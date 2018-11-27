<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\SalvaPhonePolicy;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SalvaStatusType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('salvaStatus', ChoiceType::class, [
                'choices' => [
                    SalvaPhonePolicy::SALVA_STATUS_PENDING_UPDATE,
                    SalvaPhonePolicy::SALVA_STATUS_PENDING ,
                    SalvaPhonePolicy::SALVA_STATUS_PENDING_CANCELLED,
                    SalvaPhonePolicy::SALVA_STATUS_CANCELLED,
                    SalvaPhonePolicy::SALVA_STATUS_WAIT_CANCELLED,
                    SalvaPhonePolicy::SALVA_STATUS_ACTIVE,
                    SalvaPhonePolicy::SALVA_STATUS_PENDING_REPLACEMENT_CANCEL,
                    SalvaPhonePolicy::SALVA_STATUS_PENDING_REPLACEMENT_CREATE,
                    SalvaPhonePolicy::SALVA_STATUS_SKIPPED
                ],
                'choice_label' => function ($choice, $key, $value) {
                    return $value;
                },
                'preferred_choices' => [
                    SalvaPhonePolicy::SALVA_STATUS_WAIT_CANCELLED,
                    SalvaPhonePolicy::SALVA_STATUS_PENDING_CANCELLED
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
            'data_class' => 'AppBundle\Document\Policy',
        ));
    }
}
