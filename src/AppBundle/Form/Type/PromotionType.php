<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Promotion;

class PromotionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $periods = [
            "Instant reward" => 0,
            14 => 14,
            30 => 30,
            60 => 60
        ];
        $builder->add('name', TextType::class)
            ->add('conditionPeriod', ChoiceType::class, ['choices' => $periods])
            ->add(
                'conditionInvitations',
                NumberType::class,
                ['constraints' => [new Assert\Range(['min' => 0, 'max' => 50])], 'data' => 0]
            )
            ->add('conditionAllowClaims', CheckBoxType::class, ['data' => true, 'required' => false])
            ->add('reward', ChoiceType::class, ['choices' => Promotion::REWARDS])
            ->add(
                'rewardAmount',
                NumberType::class,
                ['constraints' => [new Assert\Range(['min' => 1, 'max' => 50])], 'required' => false]
            )
            ->add('next', SubmitType::class)
            ->getForm();
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Promotion',
        ));
    }
}
