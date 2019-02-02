<?php

namespace App\Command;

use AppBundle\Document\Oauth\AccessToken;
use AppBundle\Document\Oauth\AuthCode;
use AppBundle\Document\Oauth\RefreshToken;
use AppBundle\Document\Participation;
use AppBundle\Document\DateTrait;
use AppBundle\Service\PromotionService;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\DocumentNotFoundException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A commmand that deletes older oauth tokens related to non-existent users
 */
class OAuthUserCleanupCommand extends ContainerAwareCommand
{
    use DateTrait;
    const SERVICE_NAME = 'sosure:oauth:delete';
    protected static $defaultName = self::SERVICE_NAME;

    /** @var DocumentManager  */
    protected $dm;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this->setDescription('deletes older oauth tokens related to non-existent users');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->dm->getRepository(RefreshToken::class);
        foreach ($repo->findAll() as $token) {
            /** @var RefreshToken $token */
            try {
                $user = $token->getUser();
                if (!$user || !$user->getUsername()) {
                    throw new \Exception('Missing user');
                }
            } catch (\Exception $e) {
                $output->writeln(sprintf('Removing refresh token %s', $token->getId()));
                $this->dm->remove($token);
            }
        }
        $this->dm->flush();

        $repo = $this->dm->getRepository(AccessToken::class);
        foreach ($repo->findAll() as $token) {
            /** @var AccessToken $token */
            try {
                $user = $token->getUser();
                if (!$user || !$user->getUsername()) {
                    throw new \Exception('Missing user');
                }
            } catch (\Exception $e) {
                $output->writeln(sprintf('Removing access token %s', $token->getId()));
                $this->dm->remove($token);
            }
        }
        $this->dm->flush();

        $repo = $this->dm->getRepository(AuthCode::class);
        foreach ($repo->findAll() as $code) {
            /** @var AuthCode $code */
            try {
                $user = $code->getUser();
                if (!$user || !$user->getUsername()) {
                    throw new \Exception('Missing user');
                }
            } catch (\Exception $e) {
                $output->writeln(sprintf('Removing code %s', $code->getId()));
                $this->dm->remove($code);
            }
        }
        $this->dm->flush();
    }
}
