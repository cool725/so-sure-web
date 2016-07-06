<?php

use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Config\Loader\LoaderInterface;

class AppKernel extends Kernel
{
    public function getCacheDir()
    {
        if (in_array($this->getEnvironment(), array('test', 'vagrant'), true)) {
            return '/dev/shm/cache/'.$this->environment.'/cache';
        } else {
            return parent::getCacheDir();
        }
    }

    public function registerBundles()
    {
        $bundles = array(
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new Symfony\Bundle\TwigBundle\TwigBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Symfony\Bundle\SwiftmailerBundle\SwiftmailerBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
            new Doctrine\Bundle\MongoDBBundle\DoctrineMongoDBBundle(),
            new FOS\UserBundle\FOSUserBundle(),
            new Symfony\Bundle\AsseticBundle\AsseticBundle(),
            new HWI\Bundle\OAuthBundle\HWIOAuthBundle(),
            new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),
            new JMS\AopBundle\JMSAopBundle(),
            new JMS\SecurityExtraBundle\JMSSecurityExtraBundle(),
            new JMS\DiExtraBundle\JMSDiExtraBundle($this),
            new WhiteOctober\PagerfantaBundle\WhiteOctoberPagerfantaBundle(),
            new AppBundle\AppBundle(),
            new Staffim\RollbarBundle\StaffimRollbarBundle(),
            new Snc\RedisBundle\SncRedisBundle(),
            new Stof\DoctrineExtensionsBundle\StofDoctrineExtensionsBundle(),
            new Vich\UploaderBundle\VichUploaderBundle(),
            new Oneup\FlysystemBundle\OneupFlysystemBundle(),
            new Knp\Bundle\SnappyBundle\KnpSnappyBundle(),
        );

        if (in_array($this->getEnvironment(), array('dev', 'test', 'vagrant'), true)) {
            $bundles[] = new Symfony\Bundle\DebugBundle\DebugBundle();
            $bundles[] = new Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
            $bundles[] = new Sensio\Bundle\DistributionBundle\SensioDistributionBundle();
            $bundles[] = new Sensio\Bundle\GeneratorBundle\SensioGeneratorBundle();
            $bundles[] = new Liip\FunctionalTestBundle\LiipFunctionalTestBundle();
            $bundles[] = new Doctrine\Bundle\FixturesBundle\DoctrineFixturesBundle();
        }

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load($this->getRootDir().'/config/config_'.$this->getEnvironment().'.yml');
    }
}
