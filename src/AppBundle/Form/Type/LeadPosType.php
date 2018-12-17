<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Phone;
use AppBundle\Repository\PhoneRepository;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LeadPosType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('submittedBy', ChoiceType::class, [
                'required' => true,
                'choices' => array(
                    'Interested in so-sure' => 'customer',
                    'A member of Staff' => 'staff'
                ),
                'placeholder' => 'I am...',
                'multiple' => false,
                'mapped' => false
            ])
            ->add('name', TextType::class, [
                'required' => true
            ])
            ->add('email', EmailType::class, [
                'required' => true
            ])
            ->add('phone', DocumentType::class, [
                'placeholder' => 'Select your device',
                'class' => 'AppBundle:Phone',
                'query_builder' => function (PhoneRepository $dr) {
                    return $dr->findActive();
                },
                'choice_label' => 'getNameFormSafe',
                'choice_value' => 'id',
                'preferred_choices' => function (Phone $phone) {
                    return $phone->isHighlight();
                },
            ])
            ->add('optin', ChoiceType::class, [
                'choices' => [
                    'I would like to receive emails from so-sure!' => true,
                    'I am not interested in receiving any information' => false,
                ],
                'required' => true,
                'expanded' => true,
            ])
            ->add('state', HiddenType::class, ['mapped' => false])
            ->add('submit', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Lead',
        ));
    }
}
