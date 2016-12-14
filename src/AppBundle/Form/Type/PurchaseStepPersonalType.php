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
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @param RequestStack $requestStack
     * @param boolean      $required
     */
    public function __construct(RequestStack $requestStack, $required)
    {
        $this->requestStack = $requestStack;
        $this->required = $required;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('email', HiddenType::class, ['required' => false])
            ->add('firstName', HiddenType::class, ['required' => false])
            ->add('lastName', HiddenType::class, ['required' => false])
            ->add('name', TextType::class, ['required' => $this->required])
            ->add('birthday', DateType::class, [
                'required' => $this->required,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd/MM/yyyy'
            ])
            ->add('next', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $purchase = $event->getData();
            $form = $event->getForm();

            // TODO: Better check on mobile number
            if ($purchase->getMobileNumber() && strlen($purchase->getMobileNumber()) > 0) {
                $form->add('mobileNumber', HiddenType::class, ['required' => false]);
            } else {
                $form->add('mobileNumber', TextType::class, ['required' => $this->required]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PurchaseStepPersonal',
        ));
    }
}
