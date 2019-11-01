<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\ValidatorTrait;
use AppBundle\Repository\PhoneRepository;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class ImeiType extends AbstractType
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
            ->add('phone', DocumentType::class, [
                'placeholder' => 'Select a device',
                'class' => 'AppBundle:Phone',
                'query_builder' => function (PhoneRepository $dr) {
                    return $dr->findActiveInactive();
                },
                'required' => false,
                'choice_value' => 'id',
                'choice_label' => 'getNameFormSafe',
                'choice_attr' => function ($id) {
                    return [
                         'data-binder' => $id->getSalvaBinderMonthlyPremium(),
                    ];
                },
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
