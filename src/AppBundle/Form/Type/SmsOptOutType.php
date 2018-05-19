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
use AppBundle\Document\Opt\SmsOptOut;

class SmsOptOutType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('mobile', TextType::class)
            ->add('category', ChoiceType::class, [
                'required' => true,
                'choices' => [
                    SmsOptOut::OPTOUT_CAT_ALL => SmsOptOut::OPTOUT_CAT_ALL,
                    SmsOptOut::OPTOUT_CAT_INVITATIONS => SmsOptOut::OPTOUT_CAT_INVITATIONS,
                    SmsOptOut::OPTOUT_CAT_MARKETING => SmsOptOut::OPTOUT_CAT_MARKETING,
                ]
            ])
            ->add('notes', TextType::class, ['required' => false])
            ->add('update', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\OptOut\SmsOptOut',
        ));
    }
}
