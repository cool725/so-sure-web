<?php

namespace AppBundle\Form\Type;

use AppBundle\Service\RequestService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Doctrine\Bundle\MongoDBBundle\Form\Type\DocumentType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Doctrine\ODM\MongoDB\DocumentRepository;
use AppBundle\Document\Phone;

class PhoneCombinedType extends AbstractType
{
    /**
     * @var boolean
     */
    private $required;

    protected $dm;

    /** @var RequestService */
    protected $requestService;

    /**
     * @param DocumentManager $dm
     * @param boolean         $required
     * @param RequestService  $requestService
     */
    public function __construct(DocumentManager $dm, $required, RequestService $requestService)
    {
        $this->dm = $dm;
        $this->required = $required;
        $this->requestService = $requestService;
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $make = $builder->getData()->getMake();
        $models = [];
        $phonePlaceholder = 'make';
        if ($make) {
            $phones = $this->dm->getRepository(Phone::class)->findActiveModels($make);
            foreach ($phones as $phone) {
                $models[$phone->getId()] = $phone->getModelMemory();
            }
            $phonePlaceholder = sprintf('Select your %s device', $make);
        }
        // $models[''] = $phonePlaceholder;
        $makeChoiceOptions = [
            'placeholder' => 'make',
            'choices' => $this->dm->getRepository(Phone::class)->findActiveMakes(),
            'required' => $this->required,
        ];
        $makeChoiceOptions['preferred_choices'] = ['Apple', 'Samsung', 'Huawei', 'Google', 'OnePlus'];

        $builder
            ->add('make', ChoiceType::class, $makeChoiceOptions)
            ->add('model', ChoiceType::class, [
                'choices' => $models,
                'required' => $this->required
            ])
            ->add('email', EmailType::class, [
                'required' => false,
                'disabled' => true
            ])
            ->add('next', SubmitType::class)
        ;
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'AppBundle\Document\Form\PhoneCombined',
            'csrf_protection'   => true,
        ));
    }
}
