<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Phone;
use AppBundle\Repository\PhoneRepository;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class LeadPortalType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('submittedBy', ChoiceType::class, [
                'required' => true,
                'choices' => array(
                    'Customer' => 'customer',
                    'Staff' => 'staff'
                ),
                'placeholder' => 'Choose a user to begin...',
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
                'preferred_choices' => function (Phone $phone) {
                    return $phone->isHighlight();
                },
            ])
            ->add('terms', CheckboxType::class, [
                'required' => true,
                'mapped' => false
            ])
            ->add('submit', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Lead',
        ));
    }
}
