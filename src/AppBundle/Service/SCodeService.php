<?php
namespace AppBundle\Service;

use AppBundle\Repository\SCodeRepository;
use Psr\Log\LoggerInterface;
use Doctrine\ODM\MongoDB\DocumentManager;

use AppBundle\Document\Policy;
use AppBundle\Document\SCode;
use AppBundle\Document\User;

class SCodeService
{
    const MAX_UNIQUE_SCODE_GENERATION_ATTEMPTS = 10;

    /** @var LoggerInterface */
    protected $logger;

    /** @var DocumentManager */
    protected $dm;

    /** @var RouterService */
    protected $routerService;

    /** @var ShortLinkService */
    protected $shortLink;

    /** @var BranchService */
    protected $branch;

    /**
     * @param DocumentManager  $dm
     * @param LoggerInterface  $logger
     * @param RouterService    $routerService
     * @param ShortLinkService $shortLink
     * @param BranchService    $branch
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        RouterService $routerService,
        ShortLinkService $shortLink,
        BranchService $branch
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->routerService = $routerService;
        $this->shortLink = $shortLink;
        $this->branch = $branch;
    }

    public function generateSCode($user, $type)
    {
        $scode = $this->generateUniqueSCode($user, $type);
        $shortLink = $this->branch->generateSCode($scode->getCode());
        // branch is preferred, but can fallback to old website version if branch is down
        if (!$shortLink) {
            $link = $this->routerService->generateUrl('scode', ['code' => $scode->getCode()]);
            $shortLink = $this->shortLink->addShortLink($link);
        }
        $scode->setShareLink($shortLink);

        return $scode;
    }

    public function generateUniqueSCode($user, $type, $rand = null)
    {
        if ($rand === null) {
            $rand = $type == SCode::TYPE_MULTIPAY;
        }

        $scode = new SCode();
        $scode->setType($type);
        /** @var SCodeRepository $scodeRepo */
        $scodeRepo = $this->dm->getRepository(SCode::class);
        //print SCode::getNameForCode($user, $type) . PHP_EOL;
        $existingCount = $scodeRepo->getCountForName(
            SCode::getNameForCode($user, $type)
        );
        if ($rand) {
            $scode->generateNamedCode($user, rand(1, 9999));
        } else {
            $scode->generateNamedCode($user, $existingCount + 1);
        }
        $count = 1;

        while ($scodeRepo->findOneBy(['code' => $scode->getCode()]) !== null) {
            if ($rand) {
                $scode->generateNamedCode($user, rand(1, 9999));
            } else {
                $scode->generateNamedCode($user, $existingCount + 1 + $count);
            }

            if ($count > self::MAX_UNIQUE_SCODE_GENERATION_ATTEMPTS) {
                throw new \Exception('Unable to generate unique scode');
            }
            $count++;
        }
        //print $scode->getCode() . PHP_EOL;

        return $scode;
    }
}
