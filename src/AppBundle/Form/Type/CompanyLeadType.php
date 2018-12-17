<?php

namespace AppBundle\Form\Type;

use EWZ\Bundle\RecaptchaBundle\Form\Type\EWZRecaptchaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use EWZ\Bundle\RecaptchaBundle\Validator\Constraints\IsTrue as RecaptchaTrue;

class CompanyLeadType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', EmailType::class)
            ->add('name', TextType::class)
            ->add('company', TextType::class)
            ->add('phone', TextType::class)
            ->add('phones', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'No. of phones to insure...',
                'choices' => [
                    'less than 5' => 'less than 5',
                    '5-10' => '5-10',
                    '10-50' => '10-50',
                    'more than 50' => 'more than 50',
                    'uncertain' => 'uncertain'
                ],
            ])
            ->add('timeframe', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'Purchasing timeframe...',
                'choices' => [
                    'immedidate' => 'immedidate',
                    'this quarter' => 'this quarter',
                    'next quarter' => 'next quarter',
                    'next year' => 'next year',
                    'uncertain' => 'uncertain'
                ],
            ])
            ->add('message', TextareaType::class)
            ->add('submit', SubmitType::class)
        ;

        $recaptcha = new RecaptchaTrue();
        $recaptcha->message = 'Please ensure you pass the reCAPTCHA check';

        $builder->add('recaptcha', EWZRecaptchaType::class, array(
            'attr' => array(
                'options' => array(
                    'theme' => 'light',
                    'type'  => 'image',
                    'size'  => 'normal',
                    'defer' => true,
                    'async' => true,
                )
            ),
            'mapped'      => false,
            'constraints' => array(
                $recaptcha
            )
        ));
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
