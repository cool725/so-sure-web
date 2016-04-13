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
use AppBundle\Document\Claim;

class ClaimType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('number', TextType::class)
            ->add('type', ChoiceType::class, ['choices' => [
                Claim::TYPE_LOSS => Claim::TYPE_LOSS,
                Claim::TYPE_THEFT => Claim::TYPE_THEFT,
                Claim::TYPE_DAMAGE => Claim::TYPE_DAMAGE,
                Claim::TYPE_WITHDRAWN => Claim::TYPE_WITHDRAWN,
                Claim::TYPE_DECLINED => Claim::TYPE_DECLINED,
            ]])
            ->add('suspected_fraud', CheckboxType::class, ['required' => false])
            ->add('record', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Claim',
        ));
    }
}
