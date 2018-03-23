<?php

namespace PicsureMLBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use PicsureMLBundle\Document\TrainingData;
use PicsureMLBundle\Service\PicsureMLService;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PicsureMLVersionType extends AbstractType
{
    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var PicsureMLService
     */
    private $picsureMLService;

    /**
     * @param RequestStack $requestStack
     * @param PicsureMLService $picsureMLService
     */
    public function __construct(RequestStack $requestStack, PicsureMLService $picsureMLService)
    {
        $this->requestStack = $requestStack;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('version', ChoiceType::class, [
                'required' => true,
                'choices' => [
                    'None' => null,
                ]
            ])
            ->add('apply', SubmitType::class)
        ;

        $currentRequest = $this->requestStack->getCurrentRequest();
        $builder->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) use ($currentRequest) {
            $form = $event->getForm();
            if ($currentRequest->query->get('version')) {
                $form->get('version')->setData($currentRequest->query->get('version'));
            } else {
                $form->get('version')->setData(null);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'csrf_protection'   => false,
        ));
    }
}
