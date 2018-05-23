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

class EmailOptOutType extends AbstractType
{
    public static $choices = [
        // @codingStandardsIgnoreStart
        'I do not want to receive invitations to so-sure from other so-sure members' =>
            EmailOptOut::OPTOUT_CAT_INVITATIONS,
        'I do not want to receive any non-essential communications from so-sure (such as reward pot reminders and pic-sure reminders)' =>
            EmailOptOut::OPTOUT_CAT_MARKETING,
        // @codingStandardsIgnoreEnd
    ];

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', HiddenType::class)
            ->add('notes', HiddenType::class, [
                'data' => json_encode(self::$choices)
            ])
            ->add('categories', ChoiceType::class, [
                'required' => false,
                'multiple' => true,
                'expanded' => true,
                'choices' => self::$choices,
            ])
            ->add('update', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Opt\EmailOptOut',
        ));
    }
}
