<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\ValidatorTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class DetectedImeiType extends AbstractType
{
    use ValidatorTrait;

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('imei', TextType::class, [
                'attr' => [
                    'pattern' => '[0-9]{15}',
                    'title' => '15 digit number'
                ]
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
                function ($noteConformAlphanumeric) {
                    return $this->conformAlphanumericSpaceDot($noteConformAlphanumeric, 2500, 1);
                }
            ))
        ;
    }
}
