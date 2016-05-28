<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;

class PhoneSearchType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('os', ChoiceType::class, [
                'required' => false,
                'choices' => Phone::$osTypes,
                'multiple' => true,
                'expanded' => true,
                'data' => [Phone::OS_ANDROID, Phone::OS_IOS, PHONE::OS_CYANOGEN],
            ])
            ->add('active', ChoiceType::class, [
                'required' => false,
                'choices' => [true => 'Yes', false => 'No'],
                'expanded' => true,
                'placeholder' => false,
                'data' => true,
            ])
            ->add('rules', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'missing' => 'Missing profit',
                    'retired' => 'Should be retired',
                    'loss' => 'Neg profit',
                    'price' => 'Mismatch pricing',
                    'brightstar' => 'Problematic Replacements',
                    'replacement' => 'Replacement phones'
                ],
                'expanded' => false,
            ])
            ->add('search', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
    }
}
