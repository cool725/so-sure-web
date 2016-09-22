<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\OptOut\EmailOptOut;

class EmailOptOutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', TextType::class)
            ->add('category', ChoiceType::class, [
                'required' => true,
                'choices' => [
                    EmailOptOut::OPTOUT_CAT_ALL => EmailOptOut::OPTOUT_CAT_ALL,
                    EmailOptOut::OPTOUT_CAT_INVITATIONS => EmailOptOut::OPTOUT_CAT_INVITATIONS,
                    EmailOptOut::OPTOUT_CAT_WEEKLY => EmailOptOut::OPTOUT_CAT_WEEKLY,
                ]
            ])
            ->add('notes', TextType::class, ['required' => false])
            ->add('update', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\OptOut\EmailOptOut',
        ));
    }
}
