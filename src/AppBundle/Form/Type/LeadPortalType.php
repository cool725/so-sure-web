<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class LeadPortalType extends AbstractType
{

    /**
     * @var boolean
     */
    private $required;

    /**
     * @param boolean $required
     */
    public function __construct($required)
    {

        $this->required = $required;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('submittedBy', ChoiceType::class, [
                'required' => $this->required,
                'placeholder' => false,
                'choices' => array(
                    'Choose a user to begin...' => null,
                    'Customer' => 'customer',
                    'Staff' => 'staff'
                ),
                // 'expanded' => true,
                'multiple' => false,
            ])
            ->add('firstName', HiddenType::class, ['required' => false])
            ->add('lastName', HiddenType::class, ['required' => false])
            ->add('name', TextType::class, ['required' => $this->required])
            ->add('email', EmailType::class, ['required' => $this->required])
            ->add('phone', ChoiceType::class, ['required' => $this->required])
            ->add('phone', ChoiceType::class, ['required' => $this->required])
            ->add('terms', CheckboxType::class, ['required' => $this->required])
            ->add('submit', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\LeadPortal',
        ));
    }
}
