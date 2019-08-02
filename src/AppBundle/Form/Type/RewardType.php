<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Reward;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class RewardType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('firstName', TextType::class)
            ->add('lastName', TextType::class)
            ->add('code', TextType::class)
            ->add('email', EmailType::class)
            ->add('defaultValue', TextType::class)
            ->add('expiryDate', DateType::class, [
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => ['year' => 'YYYY', 'month' => 'MM', 'day' => 'DD'],
            ])
            ->add('policyAgeMin', TextType::class)
            ->add('policyAgeMax', TextType::class)
            ->add('usageLimit', TextType::class)
            ->add('hasNotClaimed', CheckboxType::class)
            ->add('hasRenewed', CheckboxType::class)
            ->add('hasCancelled', CheckboxType::class)
            ->add('termsAndConditions', TextareaType::class)
            ->add('next', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\CreateReward',
        ));
    }
}
