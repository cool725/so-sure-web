<?php

namespace AppBundle\Form\Type;

use AppBundle\Document\Phone;
use AppBundle\Repository\PhoneRepository;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use function foo\func;
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
     * @var RequestStack
     */
    private $requestStack;


    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('submittedBy', ChoiceType::class, [
                'required' => $this->required,
                'choices' => array(
                    'Customer' => 'customer',
                    'Staff' => 'staff'
                ),
                'placeholder' => 'Choose a user to begin...',
                'multiple' => false,
            ])
            ->add('firstName', HiddenType::class, ['required' => false])
            ->add('lastName', HiddenType::class, ['required' => false])
            ->add('name', TextType::class, ['required' => $this->required])
            ->add('email', EmailType::class, ['required' => $this->required])
            ->add('phone', DocumentType::class, [
                'placeholder' => 'Select your device',
                'class' => 'AppBundle:Phone',
                'query_builder' => function (PhoneRepository $dr) {
                    return $dr->findActive();
                },
                'preferred_choices' => function (Phone $phone) {
                    return $phone->isHighlight();
                },
            ])
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
