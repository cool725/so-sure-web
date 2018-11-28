<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
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
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @param RequestStack $requestStack
     * @param boolean      $required
     */
    // public function __construct(RequestStack $requestStack, $required)
    // {
    //     $this->requestStack = $requestStack;
    //     $this->required = $required;
    // }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('submittedBy', ChoiceType::class, [
                'required' => $this->required,
                'placeholder' => false,
                'choices' => array(
                    'Customer' => 'customer',
                    'Staff' => 'staff'
                ),
                'expanded' => true,
                'multiple' => false,
            ])
            ->add('firstName', HiddenType::class, ['required' => false])
            ->add('lastName', HiddenType::class, ['required' => false])
            ->add('name', TextType::class, ['required' => $this->required])
            ->add('email', EmailType::class, ['required' => $this->required])
            ->add('make', ChoiceType::class, ['required' => $this->required])
            ->add('model', ChoiceType::class, ['required' => $this->required])
            ->add('memory', ChoiceType::class, ['required' => $this->required])
            ->add('submit', SubmitType::class);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\LeadPortal',
        ));
    }
}
