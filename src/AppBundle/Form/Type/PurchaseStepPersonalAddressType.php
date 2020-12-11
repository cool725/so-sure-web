<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\BirthdayType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;
use AppBundle\Validator\Constraints\AgeValidator;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PurchaseStepPersonalAddressType extends AbstractType
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
        $years = [];
        $now = \DateTime::createFromFormat('U', time());
        for ($year = (int) $now->format('Y'); $year >= $now->format('Y') - AgeValidator::MAX_AGE; $year--) {
            $years[] = $year;
        }
        $builder
            ->add('firstName', TextType::class, ['required' => $this->required])
            ->add('lastName', TextType::class, ['required' => $this->required])
            ->add('userOptIn', CheckboxType::class, ['required' => false])
            ->add('birthday', BirthdayType::class, [
                  'required' => $this->required,
                  'format'   => 'dd/MM/yyyy',
                  'widget' => 'single_text',
                  'placeholder' => array(
                      'year' => 'YYYY', 'month' => 'MM', 'day' => 'DD',
                  ),
                  'years' => $years,
            ])
            ->add('mobileNumber', TelType::class, ['required' => $this->required])
            ->add('addressLine1', TextType::class, ['required' => $this->required])
            ->add('addressLine2', TextType::class, ['required' => false])
            ->add('addressLine3', TextType::class, ['required' => false])
            ->add('city', TextType::class, ['required' => $this->required])
            ->add('postcode', TextType::class, ['required' => $this->required])
            ->add('next', SubmitType::class)
            ->add('manual_next', SubmitType::class)
        ;
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $purchaseStepData = $event->getData();
            $user = $purchaseStepData->getUser();
            $form = $event->getForm();
            $form->add('email', EmailType::class, [
                'required' => $this->required,
                'disabled' => $user ? $user->hasPolicy() : false,
            ]);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PurchaseStepPersonalAddress',
        ));
    }
}
