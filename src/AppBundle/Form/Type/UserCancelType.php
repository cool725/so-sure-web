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
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Opt\EmailOptOut;

class UserCancelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('reason', ChoiceType::class, ['choices' => [
                'Already broken/damaged' => 'damaged',
                'Too Expensive' => 'cost',
                'Insuring with another provider' => 'elsewhere',
                'Already covered' => 'existing',
                'Do not want phone insurance' => 'undesired',
                'Technical difficulties' => 'technical',
                'pic-sure phone validation is annoying' => 'pic-sure',
                'Other' => 'unknown',
            ],
                'placeholder' => 'Select a reason',
            ])
            ->add('othertxt', TextType::class)
            ->add('cancel', SubmitType::class)
        ;
    }
}
