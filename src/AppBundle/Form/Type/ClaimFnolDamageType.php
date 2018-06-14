<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimFnolDamageType extends AbstractType
{

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('typeDetails', ChoiceType::class, [
                'required' => true,
                'placeholder' => '',
                'choices' => [
                    'Broken screen' => Claim::DAMAGE_BROKEN_SCREEN,
                    'Water damage' => Claim::DAMAGE_WATER,
                    'Out of warranty breakdown' => Claim::DAMAGE_OUT_OF_WARRANTY,
                    'Other' => Claim::DAMAGE_OTHER,
                ],
            ])
            ->add('typeDetailsOther', TextType::class)
            ->add('monthOfPurchase', TextType::class, ['required' => true])
            ->add('yearOfPurchase', TextType::class, ['required' => true])
            ->add('phoneStatus', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'status',
                'choices' => [
                    'New' => Claim::PHONE_STATUS_NEW,
                    'Refurbished' => Claim::PHONE_STATUS_REFURBISHED,
                    'Second hand' => Claim::PHONE_STATUS_SECOND_HAND,
                ],
            ])
            ->add('isUnderWarranty', ChoiceType::class, [
                'required' => true,
                'placeholder' => 'warranty',
                'choices' => [
                    'is' => 1,
                    'is not' => 0
                ],
            ])
            ->add('proofOfUsage', FileType::class)
            ->add('pictureOfPhone', FileType::class)
            ->add('confirm', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $form = $event->getForm();
            $claim = $event->getData()->getClaim();

        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimFnolDamage',
        ));
    }
}
