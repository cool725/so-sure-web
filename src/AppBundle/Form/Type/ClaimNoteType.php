<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Phone;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Claim;
use AppBundle\Document\PhonePolicy;
use AppBundle\Service\ReceperioService;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class ClaimNoteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('notes', TextareaType::class, [
                'required' => true
            ])
            ->add('update', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Claim',
        ));
    }
}
