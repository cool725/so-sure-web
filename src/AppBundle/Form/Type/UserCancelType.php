<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use AppBundle\Validator\Constraints\AgeValidator;
use AppBundle\Document\Policy;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Opt\EmailOptOut;

class UserCancelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('reason', ChoiceType::class, ['choices' => [
                'Already broken/damaged' => Policy::COOLOFF_REASON_DAMAGED,
                'Too Expensive' => Policy::COOLOFF_REASON_COST,
                'Insured with another provider' => Policy::COOLOFF_REASON_ELSEWHERE,
                'Already covered' => Policy::COOLOFF_REASON_EXISTING,
                'I don\'t want phone insurance' => Policy::COOLOFF_REASON_UNDESIRED,
                'Technical difficulties' => Policy::COOLOFF_REASON_TECHNICAL,
                'Validation is annoying' => Policy::COOLOFF_REASON_PICSURE,
                'Other' => Policy::COOLOFF_REASON_UNKNOWN,
            ],
                'placeholder' => 'Select a reason',
            ])
            ->add('othertxt', TextType::class)
            ->add('cancel', SubmitType::class)
        ;
    }
}
