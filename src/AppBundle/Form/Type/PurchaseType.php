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

class PurchaseType extends AbstractType
{
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
            ->add('email', EmailType::class, ['attr' => ['readonly' => true], 'disabled' => true])
            ->add('firstName', TextType::class, ['required' => $this->required])
            ->add('lastName', TextType::class, ['required' => $this->required])
            ->add('mobileNumber', TextType::class, ['required' => $this->required])
            ->add('birthday', DateType::class, [
                'required' => $this->required,
                'widget' => 'single_text',
                'html5' => false,
                'format' => 'dd/MM/yyyy'
            ])
            ->add('imei', TextType::class, ['required' => $this->required])
            ->add('addressLine1', TextType::class, ['required' => $this->required])
            ->add('addressLine2', TextType::class, ['required' => false])
            ->add('addressLine3', TextType::class, ['required' => false])
            ->add('city', TextType::class, ['required' => $this->required])
            ->add('postcode', TextType::class, ['required' => $this->required])
            ->add('submit', SubmitType::class)
        ;

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $purchase = $event->getData();
            $form = $event->getForm();

            if ($purchase->getPhone()->getMake() == "Apple") {
                $form->add('serialNumber', TextType::class, ['required' => $this->required]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\Purchase',
        ));
    }
}
