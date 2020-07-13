<?php

namespace AppBundle\Command;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Document\Oauth\RefreshToken;
use AppBundle\Document\Oauth\AccessToken;
use AppBundle\Document\User;

class TokensMaintenanceCommand extends ContainerAwareCommand
{
    /** @var DocumentManager  */
    protected $dm;
    /** @var boolean $wet */
    protected $wet;

    public function __construct(DocumentManager $dm)
    {
        parent::__construct();
        $this->dm = $dm;
    }

    protected function configure()
    {
        $this
            ->setName('sosure:tokens')
            ->setDescription('Run a maintenance on the oauth tokens')
            ->addOption(
                'wet',
                null,
                InputOption::VALUE_NONE,
                'Wihtout this option no changes are persisted.'
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        /** @var boolean $wet */
        $this->wet = $input->getOption('wet') == true;

        if (!$this->wet) {
            $output->writeln("! This is a dry run, use --wet option to persist changes");
        }

        $refreshTokensRepo = $this->dm->getRepository(RefreshToken::class);
        // $accessTokensRepo = $this->dm->getRepository(AccessToken::class);

        //Get the access tokens that should have a valid refresh token
        $accessTokens = $this->dm->createQueryBuilder(AccessToken::class)
          ->field('expiresAt')->gte(time() - (2629743*7))
          ->sort('expiresAt', 'desc')
          ->getQuery()
          ->execute();
        $multiples = array();
        /** @var AccessToken $accessToken */
        foreach ($accessTokens as $accessToken) {
            /** @var User $user */
            $user = $accessToken->getUser();
            $userID = $user->getId();
            // If not only token for the user then we create an increment to
            // get the corrrsponding refreshToken
            if (array_key_exists($userID, $multiples)) {
                $multiples[$userID]++;
            } else {
                $multiples[$userID]=0;
            }
            $refreshTokens = $refreshTokensRepo->findBy(
                ['user.id'=> $userID],
                ['expiresAt' => 'desc']
            );
            if (!$refreshTokens) {
                $output->writeln("No Corresponding Refresh Tokens for the Access Token: " . $accessToken->getId());
                continue;
            }
            /** @var RefreshToken $refreshToken */
            if (array_key_exists($multiples[$userID], $refreshTokens)) {
                $refreshToken = $refreshTokens[$multiples[$userID]];
                if ($refreshToken->getExpiresAt() < $accessToken->getExpiresAt()) {
                    $output->writeln("RefreshToken " . $refreshToken->getId() . " is going to be extended");
                    //If the refreshToken expires before the accessToken
                    //Then set refreshToken to expire a month after the accessToken
                    $newExpiresAt = $accessToken->getExpiresAt() + 2629743;
                    $output->writeln("New expiration: " . $newExpiresAt);
                    if ($this->wet) {
                        $refreshToken->setExpiresAt($newExpiresAt);
                    }
                }
            } else {
                $output->writeln("No Corresponding Refresh Token for the Access Token: " . $accessToken->getId());
                continue;
            }
        }
        if ($this->wet) {
            $this->dm->flush();
        }
    }
}
