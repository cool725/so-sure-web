<?php

namespace AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PhoneSearchType extends AbstractType
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @param RequestStack $requestStack
     */
    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('os', ChoiceType::class, [
                'required' => false,
                'choices' => Phone::$osTypes,
                'multiple' => true,
                'expanded' => true,
                'data' => [Phone::OS_ANDROID, Phone::OS_IOS, PHONE::OS_CYANOGEN],
            ])
            ->add('active', ChoiceType::class, [
                'required' => false,
                'choices' => [true => 'Yes', false => 'No'],
                'expanded' => false,
                'placeholder' => false,
                'data' => true,
            ])
            ->add('rules', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    'missing' => 'Missing profit',
                    'retired' => 'Should be retired',
                    'loss' => 'Neg profit',
                    'price' => 'Mismatch pricing',
                    'brightstar' => 'Problematic Replacements',
                    'replacement' => 'Replacement phones'
                ],
                'expanded' => false,
            ])
            ->add('search', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            $form->get('os')->setData($currentRequest->query->get('os'));
            $form->get('active')->setData($currentRequest->query->get('active'));
            $form->get('rules')->setData($currentRequest->query->get('rules'));
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'csrf_protection'   => false,
        ));
    }

    public function getName()
    {
        return null;
    }
}
