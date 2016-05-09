<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class CancelPolicyType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('cancelledReason', ChoiceType::class, ['choices' => [
                Policy::CANCELLED_UNPAID => Policy::CANCELLED_UNPAID,
                Policy::CANCELLED_FRAUD => Policy::CANCELLED_FRAUD,
                Policy::CANCELLED_GOODWILL => Policy::CANCELLED_GOODWILL,
                Policy::CANCELLED_COOLOFF => Policy::CANCELLED_COOLOFF,
            ]])
            ->add('cancel', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
