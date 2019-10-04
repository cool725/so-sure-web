<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\PhonePrice;
use AppBundle\Document\ValidatorTrait;
use AppBundle\Document\Form\CardRefund;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Represents creating an offer from a form.
 */
class OfferType extends AbstractType
{
    /**
     * @inheritDoc
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name', TextType::class)
            ->add('gwp', NumberType::class)
            ->add('stream', ChoiceType::class, [
                'choices' => array_combine(PhonePrice::STREAM_POSITIONS, PhonePrice::STREAM_POSITIONS)
            ])
            ->add('damage', NumberType::class)
            ->add('warranty', NumberType::class)
            ->add('extendedWarranty', NumberType::class)
            ->add('loss', NumberType::class)
            ->add('theft', NumberType::class)
            ->add('picsureDamage', NumberType::class)
            ->add('picsureWarranty', NumberType::class)
            ->add('picsureExtendedWarranty', NumberType::class)
            ->add('picsureLoss', NumberType::class)
            ->add('picsureTheft', NumberType::class)
            ->add('add', SubmitType::class);
    }
}
