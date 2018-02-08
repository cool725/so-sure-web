<?php

namespace PicsureMLBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use PicsureMLBundle\Document\Image;
use Psr\Log\LoggerInterface;

class PicsureMLService
{
    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     */
    public function __construct(DocumentManager $dm, LoggerInterface $logger)
    {
        $this->dm = $dm;
        $this->logger = $logger;
    }

/*
    public function sync($filesystem)
    {
        $repo = $this->dm->getRepository(Image::class);

        $contents = $filesystem->listContents('ml', true);
        foreach ($contents as $object) {
            if ($object['type'] == "file" && !$repo->imageExists($object['path'])) {
                $image = new Image();
                $image->setPath($object['path']);
                $this->dm->persist($image);
            }
        }

        $this->dm->flush();
    }

    public function annotate($filesystem)
    {
        $repo = $this->dm->getRepository(Image::class);

        $annotations = [];

        $qb = $repo->createQueryBuilder();
        $qb->sort('id', 'desc');
        $results = $qb->getQuery()->execute();

        foreach ($results as $result) {
            if ($result->hasAnnotation()) {
                $annotations[] = sprintf(
                    "%s 1 %d %d %d %d",
                    $result->getPath(),
                    $result->getX(),
                    $result->getY(),
                    $result->getWidth(),
                    $result->getHeight()
                );
            }
        }
    }
    */
    
}
