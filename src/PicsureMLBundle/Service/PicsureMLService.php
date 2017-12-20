<?php

namespace PicsureMLBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use PicsureMLBundle\Document\Image;

class PicsureMLService
{

    protected $dm;

    /**
     * @param DocumentManager $dm
     */
    public function __construct(DocumentManager $dm)
    {
        $this->dm = $dm;
    }

    public function sync($filesystem) {
        $repo = $this->dm->getRepository(Image::class);

		$contents = $filesystem->listContents('ml', true);
		foreach ($contents as $object) {
			if ( $object['type'] == "file" && !$repo->imageExists($object['path']) ) {
		    	$image = new Image();
		    	$image->setPath($object['path']);
		        $this->dm->persist($image);
			}
		}
		
		$this->dm->flush();
    }

    public function annotate($filesystem) {
    	$repo = $this->dm->getRepository(Image::class);

        $annotations = "";

        $qb = $repo->createQueryBuilder();
        $qb->sort('id', 'desc');
        $results = $qb->getQuery()->execute();

        foreach ($results as $result) {
            if ($result->getX() != null && $result->getY() != null && $result->getWidth() != null && $result->getHeight() != null) {
                $annotations .= $result->getPath().' 1 '.$result->getX().' '.$result->getY().' '.$result->getWidth().' '.$result->getHeight().PHP_EOL;
            }
        }

        $fs = new Filesystem();
        try {
            $fs->dumpFile(sys_get_temp_dir().'/annotations.txt', $annotations);

            $stream = fopen(sys_get_temp_dir().'/annotations.txt', 'r+');
            if ($stream != FALSE) {
                $filesystem->putStream('annotations.txt', $stream);
                fclose($stream);
            }
        } catch (IOExceptionInterface $e) {
            echo "An error occurred while writting the annotations to ".$e->getPath();
        }
    }

}
