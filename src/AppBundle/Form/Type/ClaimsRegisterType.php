<?php

namespace AppBundle\Form\Type;

use AppBundle\Repository\ClaimRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Claim;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;

class ClaimsRegisterType extends AbstractType
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
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {
            $claimsCheck = $event->getData();
            $form = $event->getForm();

            $form->add('claim', DocumentType::class, [
                    'required' => $this->required,
                    'placeholder' => 'Select a claim',
                    'class' => 'AppBundle:Claim',
                    'choice_label' => 'number',
                    'query_builder' => function (ClaimRepository $dr) use ($claimsCheck) {
                        return $dr->findByPolicy($claimsCheck->getPolicy());
                    }
            ])->add('run', SubmitType::class);
        });
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\ClaimsCheck',
        ));
    }
}
