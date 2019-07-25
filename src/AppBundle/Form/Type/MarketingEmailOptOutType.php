<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Opt\EmailOptOut;

/**
 * Represents a form that only allows users to opt out of marketing emails.
 */
class MarketingEmailOptOutType extends AbstractType
{
    const CHOICES = [
        'I do not want to receive marketing emails from so-sure' => EmailOptOut::OPTOUT_CAT_MARKETING
    ];

    /**
     * @Override
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', HiddenType::class)
            ->add('notes', HiddenType::class, [
                'data' => json_encode(self::CHOICES)
            ])
            ->add('categories', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => self::CHOICES,
                'data' => [$options['checked'] ? EmailOptOut::OPTOUT_CAT_MARKETING : '']
            ])
            ->add('update', SubmitType::class);
    }

    /**
     * @Override
     */
    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setRequired('checked');
    }
}
