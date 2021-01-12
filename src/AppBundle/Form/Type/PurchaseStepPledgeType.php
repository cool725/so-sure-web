<?php

namespace AppBundle\Form\Type;

use AppBundle\Service\BaseImeiService;
use Psr\Log\LoggerInterface;
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
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use AppBundle\Document\Phone;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;

class PurchaseStepPledgeType extends AbstractType
{
    /**
     * @var boolean
     */
    private $required;

    /**
     * @var RequestStack
     */
    private $requestStack;

    protected $logger;

    /**
     * @param RequestStack    $requestStack
     * @param boolean         $required
     * @param LoggerInterface $logger
     */
    public function __construct(RequestStack $requestStack, $required, LoggerInterface $logger)
    {
        $this->requestStack = $requestStack;
        $this->required = $required;
        $this->logger = $logger;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('agreedDamage', CheckboxType::class, ['required' => $this->required])
            ->add('agreedAgeLocation', CheckboxType::class, ['required' => $this->required])
            ->add('agreedExcess', CheckboxType::class, ['required' => $this->required])
            ->add('agreedTerms', CheckboxType::class, ['required' => $this->required])
            // TODO: if user opted in earlier - don't show
            ->add('userOptIn', CheckboxType::class, ['required' => false])
            ->add('next', SubmitType::class)
        ;

    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PurchaseStepPledge',
        ));
    }
}
