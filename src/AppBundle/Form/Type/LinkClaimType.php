<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\ValidatorTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class LinkClaimType extends AbstractType
{
    use ValidatorTrait;

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('id', TextType::class, [
                'required' => false
            ])
            ->add('number', TextType::class, [
                'required' => false
            ])
            ->add('note', TextareaType::class)
            ->add('submit', SubmitType::class)
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
