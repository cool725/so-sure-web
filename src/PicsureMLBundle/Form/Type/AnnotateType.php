<?php

namespace PicsureMLBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\ButtonType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;

class AnnotateType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('x', TextType::class, ['required' => false])
            ->add('y', TextType::class, ['required' => false])
            ->add('width', TextType::class, ['required' => false])
            ->add('height', TextType::class, ['required' => false])
            ->add('annotate', SubmitType::class)
            ->add('clear', ButtonType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'PicsureMLBundle\Document\TrainingData',
        ));
    }
}
