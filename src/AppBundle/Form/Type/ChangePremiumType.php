<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\CardRefund;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Repository\PaymentRepository;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use PhpParser\Node\Stmt\Label;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ChangePremiumType extends AbstractType
{
    use ValidatorTrait;

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $emailPreference = [
            'No' => 0,
            'Yes' => 1
        ];

        $builder
            ->add('emailPreference', ChoiceType::class, ['choices' => $emailPreference])
            ->add('amount', TextType::class)
            ->add('paytype', null, ['attr' => array(
                                                                'readonly' => true,
                                                            )])
            ->add('notes', TextareaType::class)
            ->add('add', SubmitType::class);

        $builder
            ->get('notes')
            ->addModelTransformer(new CallbackTransformer(
                function ($note) {
                    return $note;
                },
                function ($noteConformAlphanumeric) {
                    return $this->conformAlphanumericSpaceDot($noteConformAlphanumeric, 200, 1);
                }
            ));
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\UpdatePremium',
        ));
    }
}
