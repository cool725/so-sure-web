<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\AffiliateCompany;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class AffiliateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $timeRanges = [
            14 => 14,
            30 => 30,
            60 => 60,
            90 => 90
        ];
        $renewalTimeRanges = [
            0 => 0,
            14 => 14,
            30 => 30,
            60 => 60,
            90 => 90
        ];
        $leadSources = [
            'invitation' => 'invitation',
            'scode' => 'scode',
            'affiliate' => 'affiliate'
        ];
        $chargeModels = [
            "One off Charges" => AffiliateCompany::MODEL_ONE_OFF,
            "Ongoing Charges" => AffiliateCompany::MODEL_ONGOING
        ];
        $builder
            ->add('name', TextType::class)
            ->add('address1', TextType::class)
            ->add('address2', TextType::class, ['required' => false])
            ->add('address3', TextType::class, ['required' => false])
            ->add('city', TextType::class)
            ->add('postcode', TextType::class)
            ->add('chargeModel', ChoiceType::class, ['required' => true, 'choices' => $chargeModels])
            ->add('cpa', NumberType::class, ['constraints' => [new Assert\Range(['min' => 0, 'max' => 20])]])
            ->add('days', ChoiceType::class, ['required' => true, 'choices' => $timeRanges])
            ->add('renewalDays', ChoiceType::class, ['choices' => $renewalTimeRanges])
            ->add('campaignSource', TextType::class, ['required' => false])
            ->add('campaignName', TextType::class, ['required' => false])
            ->add('leadSource', ChoiceType::class, ['required' => false, 'choices' => $leadSources])
            ->add('leadSourceDetails', TextType::class, ['required' => false ])
            ->add('next', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
