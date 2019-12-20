<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CompetitionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('questionOne', ChoiceType::class, [
                'expanded' => true,
                'choices' => [
                    'A' => 'A',
                    'B' => 'B',
                    'C' => 'C'
                ]
            ])
            ->add('questionTwo', ChoiceType::class, [
                'expanded' => true,
                'choices' => [
                    'A' => 'A',
                    'B' => 'B',
                    'C' => 'C'
                ]
            ])
            ->add('questionThree', ChoiceType::class, [
                'expanded' => true,
                'choices' => [
                    'A' => 'A',
                    'B' => 'B',
                    'C' => 'C'
                ]
            ])
            ->add('submit', SubmitType::class)
        ;
    }
}
