<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', EmailType::class)
            ->add('name', TextType::class)
            ->add('policyNumber', TextType::class)
            ->add('phone', TextType::class)
            ->add('when', DateTimeType::class)
            ->add('where', TextType::class)
            ->add('timeToReach', TextType::class)
            ->add('signature', TextType::class)
            ->add('type', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'My phone is...',
                'choices' => [
                    'My phone is lost' => Claim::TYPE_LOSS,
                    'My phone was stolen' => Claim::TYPE_THEFT,
                    'My phone is damaged or not working' => Claim::TYPE_DAMAGE,
                ],
            ])
            ->add('message', TextareaType::class)
            ->add('submit', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnol',
        ));
    }
}
