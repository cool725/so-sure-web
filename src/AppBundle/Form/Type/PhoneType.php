<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;

class PhoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('phone', DocumentType::class, [
                    'class' => 'AppBundle:Phone',
                    'query_builder' => function (DocumentRepository $dr) {
                        return $dr->createQueryBuilder('p')
                            ->field('make')->notEqual("ALL")
                            ->field('active')->equals(true)
                            ->field('os')->in(['Android', 'iOS', 'Cyanogen'])
                            ->sort('make', 'asc')
                            ->sort('model', 'asc')
                            ->sort('memory', 'asc');
                    }
            ])
            ->add('next', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Policy',
        ));
    }
}
