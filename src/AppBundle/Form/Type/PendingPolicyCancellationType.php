<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Policy;

class PendingPolicyCancellationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('pendingCancellation', DateTimeType::class, [
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd-MM-yyyy HH:mm',
                'attr' => ['class' => 'datetimepicker30', 'placeholder' => '30 days after notice']
            ])
            ->add('clear', SubmitType::class)
            ->add('cancel', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Policy',
        ));
    }
}
