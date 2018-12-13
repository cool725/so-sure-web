<?php

namespace AppBundle\Form\Type;

use AppBundle\Repository\PhoneRepository;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ImeiType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('imei', TextType::class, [
                'attr' => [
                    'pattern' => '[0-9]{15}',
                    'title' => '15 digit number'
                ]
            ])
            ->add('phone', DocumentType::class, [
                'placeholder' => 'Select a device',
                'class' => 'AppBundle:Phone',
                'query_builder' => function (PhoneRepository $dr) {
                    return $dr->findActive();
                },
                'required' => false
            ])
            ->add('note', TextareaType::class, [
                'trim' => true
            ])
            ->add('update', SubmitType::class)
        ;

        $builder
            ->get('note')
            ->addModelTransformer(new CallbackTransformer(
                function ($note) {
                    return $note;
                },
                function ($noteAlphanumeric) {
                    return preg_replace("/[^A-Za-z0-9 ]/", '', $noteAlphanumeric);
                }
            ))
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
        ));
    }
}
