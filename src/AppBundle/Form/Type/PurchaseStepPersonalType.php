<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PurchaseStepPersonalType extends AbstractType
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
            ->add('email', EmailType::class, ['attr' => ['readonly' => true], 'disabled' => true])
            ->add('mobileNumber', TextType::class, ['attr' => ['readonly' => true], 'disabled' => true])
            ->add('firstName', TextType::class, ['required' => $this->required])
            ->add('lastName', TextType::class, ['required' => $this->required])
            ->add('birthday', DateType::class, ['required' => $this->required, 'widget' => 'single_text', 'html5' => false, 'format' => 'dd/MM/yyyy'])
            ->add('next', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PurchaseStepPersonal',
        ));
    }
}
