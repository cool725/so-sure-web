<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;

class ClaimsCheckType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('type', ChoiceType::class, ['choices' => [
                Claim::TYPE_LOSS => Claim::TYPE_LOSS,
                Claim::TYPE_THEFT => Claim::TYPE_THEFT,
                Claim::TYPE_DAMAGE => Claim::TYPE_DAMAGE,
            ]])
            ->add('run', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
    }
}
