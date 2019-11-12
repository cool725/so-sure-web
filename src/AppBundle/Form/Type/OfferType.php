<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\PhonePrice;
use AppBundle\Document\ValidatorTrait;
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
            ->add("name", TextType::class)
            ->add("gwp", NumberType::class)
            ->add("stream", ChoiceType::class, [
                "choices" => array_combine(PhonePrice::STREAM_POSITIONS, PhonePrice::STREAM_POSITIONS),
                "data" => PhonePrice::STREAM_ALL
            ])
            ->add("damage", NumberType::class, ["data" => 150])
            ->add("warranty", NumberType::class, ["data" => 150])
            ->add("extendedWarranty", NumberType::class, ["data" => 150])
            ->add("loss", NumberType::class, ["data" => 150])
            ->add("theft", NumberType::class, ["data" => 150])
            ->add("picsureDamage", NumberType::class, ["data" => 50])
            ->add("picsureWarranty", NumberType::class, ["data" => 50])
            ->add("picsureExtendedWarranty", NumberType::class, ["data" => 50])
            ->add("picsureLoss", NumberType::class, ["data" => 75])
            ->add("picsureTheft", NumberType::class, ["data" => 75])
            ->add("add", SubmitType::class);
    }
}
