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

/**
 * Makes a form which lets you make a reward.
 */
class RewardType extends AbstractType
{
    /**
     * @Override
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('firstName', TextType::class, ['required' => true])
            ->add('lastName', TextType::class, ['required' => true])
            ->add('code', TextType::class, ['required' => false])
            ->add('email', EmailType::class, ['required' => true])
            ->add('defaultValue', TextType::class, ['required' => true])
            ->add('expiryDate', DateType::class, [
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => ['year' => 'YYYY', 'month' => 'MM', 'day' => 'DD'],
                  'required' => true
            ])
            ->add('policyAgeMin', TextType::class, ['required' => false])
            ->add('policyAgeMax', TextType::class, ['required' => false])
            ->add('usageLimit', TextType::class, ['required' => false])
            ->add('hasNotClaimed', CheckboxType::class, ['required' => false])
            ->add('hasRenewed', CheckboxType::class, ['required' => false])
            ->add('hasCancelled', CheckboxType::class, ['required' => false])
            ->add('isFirst', CheckboxType::class, ['required' => false])
            ->add('isSignUpBonus', CheckboxType::class, ['required' => false])
            ->add('isConnectionBonus', CheckboxType::class, ['required' => false])
            ->add('termsAndConditions', TextareaType::class, ['required' => false])
            ->add('next', SubmitType::class);
    }

    /**
     * @Override
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(['data_class' => 'AppBundle\Document\Form\CreateReward']);
    }
}
