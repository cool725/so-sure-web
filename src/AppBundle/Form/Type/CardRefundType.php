<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Form\CardRefund;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Repository\PaymentRepository;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
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

class CardRefundType extends AbstractType
{
    use ValidatorTrait;

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('amount', TextType::class)
            ->add('totalCommission', TextType::class)
            ->add('notes', TextareaType::class)
            ->add('add', SubmitType::class);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            /** @var CardRefund $judoRefund */
            $judoRefund = $event->getData();
            $form = $event->getForm();

            $form->add('payment', ChoiceType::class, [
                'placeholder' => 'Select a payment',
                'choices' => $judoRefund->getPolicy()->getSuccessfulPaymentCredits(),
                'choice_value' => 'id',
                'choice_label' => 'toString',
            ]);
        });

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
            'data_class' => 'AppBundle\Document\Form\CardRefund',
        ));
    }
}
