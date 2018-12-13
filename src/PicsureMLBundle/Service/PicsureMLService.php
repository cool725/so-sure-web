<?php

namespace PicsureMLBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use League\Flysystem\MountManager;
use PicsureMLBundle\Repository\TrainingDataRepository;
use PicsureMLBundle\Repository\TrainingVersionsInfoRepository;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use AppBundle\Document\PhonePolicy;
use AppBundle\Document\File\S3File;
use PicsureMLBundle\Document\TrainingVersionsInfo;
use PicsureMLBundle\Document\TrainingData;

class PicsureMLService
{
    const S3BUCKET_POLICY = 'policy.so-sure.com';
    const S3BUCKET_PICSURE = 'picsure.so-sure.com';

    /** @var DocumentManager */
    protected $appDm;

    /** @var DocumentManager */
    protected $picsureMLDm;

    /** @var MountManager */
    protected $mountManager;

    /** @var S3Client */
    protected $s3;

    /**
     * @param DocumentManager $appDm
     * @param DocumentManager $picsureMLDm
     * @param MountManager    $mountManager
     * @param S3Client        $s3
     */
    public function __construct(
        DocumentManager $appDm,
        DocumentManager $picsureMLDm,
        MountManager $mountManager,
        S3Client $s3
    ) {
        $this->appDm = $appDm;
        $this->picsureMLDm = $picsureMLDm;
        $this->mountManager = $mountManager;
        $this->s3 = $s3;
    }

    public function addFileForTraining($file, $status)
    {
        /** @var TrainingDataRepository $repo */
        $repo = $this->picsureMLDm->getRepository(TrainingData::class);
        if ($file->getFileType() == 'PicSureFile' && !$repo->imageExists($file->getKey())) {
            $image = new TrainingData();
            $image->setBucket(PicsureMLService::S3BUCKET_POLICY);
            $image->setImagePath($file->getKey());
            $image->setLabel($status);
            $this->picsureMLDm->persist($image);
        }
        $this->picsureMLDm->flush();
    }

    public function createNewTrainingVersion($version)
    {
        /** @var TrainingVersionsInfoRepository $repo */
        $repo = $this->picsureMLDm->getRepository(TrainingVersionsInfo::class);
        $versionInfo = $repo->findAll();

        $versionInfo = $versionInfo[0];
        $versionInfo->addVersion($versionInfo->getLatestVersion()+1);
        $versionInfo->setLatestVersion($versionInfo->getLatestVersion()+1);
        $this->picsureMLDm->persist($versionInfo);

        if ($version !== null) {
            /** @var TrainingDataRepository $repo */
            $repo = $this->picsureMLDm->getRepository(TrainingData::class);
            $qb = $repo->createQueryBuilder();
            $qb->field('versions')->equals($version);
            $results = $qb->getQuery()->execute();

            foreach ($results as $data) {
                $data->addVersion($versionInfo->getLatestVersion());
                $this->picsureMLDm->persist($data);
            }
        }

        $this->picsureMLDm->flush();
    }

    public function getTrainingVersions()
    {
        /** @var TrainingVersionsInfoRepository $repo */
        $repo = $this->picsureMLDm->getRepository(TrainingVersionsInfo::class);
        $versionInfo = $repo->findAll();

        if (count($versionInfo) == 0) {
            return [];
        } else {
            return $versionInfo[0]->getVersions();
        }
    }

    public function predict(S3File $file)
    {
        try {
            $result = $this->s3->getObject(array(
                'Bucket' => $file->getBucket(),
                'Key'    => $file->getKey(),
                'SaveAs' => '/tmp/'.$file->getFilename()
            ));
        } catch (S3Exception $e) {
            throw new \Exception('Error downloading S3 file '.$file->getKey());
        }

        $process = new Process(
            '/usr/bin/python /var/ops/scripts/image/deep-learning/predict.py /tmp/'.$file->getFilename()
        );
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $json = json_decode($process->getOutput(), true);

        if (isset($json['error'])) {
            throw new \Exception('Error: '.$json['error']['message']);
        } else {
            $scores = array();
            $scores[TrainingData::LABEL_UNDAMAGED] = floatval($json['scores'][TrainingData::LABEL_UNDAMAGED]);
            $scores[TrainingData::LABEL_INVALID] = floatval($json['scores'][TrainingData::LABEL_INVALID]);
            $scores[TrainingData::LABEL_DAMAGED] = floatval($json['scores'][TrainingData::LABEL_DAMAGED]);
            arsort($scores);

            $status = "";
            $confidence = 0.0;
            $threshold = 1.0;
            $found = false;

            reset($scores);
            while (!$found && $threshold > 0.05) {
                if (current($scores) > $threshold) {
                    $status = key($scores);
                    $confidence = $threshold;
                    $found = true;
                }
                $threshold -= 0.05;
            }
            if (!$found) {
                $status = TrainingData::LABEL_UNKNOWN;
            }

            $file->addMetadata('picsure-ml-score', $json['scores']);
            $file->addMetadata('picsure-ml-status', $status);
            $file->addMetadata('picsure-ml-confidence', $confidence);
            $this->appDm->flush();
        }
    }

    public function sync()
    {
        /** @var TrainingDataRepository $repo */
        $repo = $this->picsureMLDm->getRepository(TrainingData::class);
        $images = $repo->createQueryBuilder()
                        ->select('imagePath')
                        ->getQuery()->execute();
        $paths = [];
        foreach ($images as $image) {
            $paths[] = $image->getImagePath();
        }

        $s3Repo = $this->appDm->getRepository(S3File::class);
        $picsureFiles = $s3Repo->findBy(['fileType' => 'picsure']);

        foreach ($picsureFiles as $file) {
            if (!in_array($file->getKey(), $paths)) {
                $image = new TrainingData();
                $image->setBucket(PicsureMLService::S3BUCKET_POLICY);
                $image->setImagePath($file->getKey());
                $metadata = $file->getMetadata();
                $status = null;
                if (isset($metadata['picsure-status'])) {
                    $status = $metadata['picsure-status'];
                }
                if (!empty($status)) {
                    if ($status == PhonePolicy::PICSURE_STATUS_APPROVED) {
                        $image->setLabel(TrainingData::LABEL_UNDAMAGED);
                    } elseif ($status == PhonePolicy::PICSURE_STATUS_INVALID) {
                        $image->setLabel(TrainingData::LABEL_INVALID);
                    } elseif ($status == PhonePolicy::PICSURE_STATUS_REJECTED) {
                        $image->setLabel(TrainingData::LABEL_DAMAGED);
                    }
                }
                $this->picsureMLDm->persist($image);
            }
        }

        $filesystem = $this->mountManager->getFilesystem('s3picsure_fs');
        $result = $filesystem->listContents('external-data', true);
        foreach ($result as $object) {
            if (!in_array($object['path'], $paths)) {
                $image = new TrainingData();
                $image->setBucket(PicsureMLService::S3BUCKET_PICSURE);
                $image->setImagePath($object['path']);
                $this->picsureMLDm->persist($image);
            }
        }

        $this->picsureMLDm->flush();
    }

    public function output($version)
    {
        $filesystem = $this->mountManager->getFilesystem('s3picsure_fs');

        /** @var TrainingDataRepository $repo */
        $repo = $this->picsureMLDm->getRepository(TrainingData::class);
        $qb = $repo->createQueryBuilder();
        $qb->field('versions')->equals($version);
        $qb->sort('id', 'desc');
        $results = $qb->getQuery()->execute();

        $csv = [];

        foreach ($results as $result) {
            if ($result->hasLabel() && $result->hasAnnotation()) {
                $csv[] = sprintf(
                    "%s/%s %d %d %d %d %s",
                    $result->getBucket(),
                    $result->getImagePath(),
                    $result->getX(),
                    $result->getY(),
                    $result->getWidth(),
                    $result->getHeight(),
                    $result->getLabel()
                );
            }
        }

        $fs = new Filesystem();
        try {
            $file = sprintf("%s/training-data.csv", sys_get_temp_dir());
            $fs->dumpFile($file, implode(PHP_EOL, $csv));
            $stream = fopen($file, 'r+');
            if ($stream != false) {
                $filesystem->putStream(sprintf("trained-data/%s/training-data.csv", $version), $stream);
                fclose($stream);
            }
        } catch (IOExceptionInterface $e) {
            return sprintf('Error writing csv: %s', $e->getPath());
        }

        return true;
    }

    public function annotate()
    {
        $filesystem = $this->mountManager->getFilesystem('s3picsure_fs');

        /** @var TrainingDataRepository $repo */
        $repo = $this->picsureMLDm->getRepository(TrainingData::class);

        $annotations = [];

        $qb = $repo->createQueryBuilder();
        $qb->sort('id', 'desc');
        $results = $qb->getQuery()->execute();

        foreach ($results as $result) {
            if ($result->getForDetection() && $result->hasAnnotation()) {
                $annotations[] = sprintf(
                    "%s/%s %d %d %d %d",
                    $result->getBucket(),
                    $result->getImagePath(),
                    $result->getX(),
                    $result->getY(),
                    $result->getWidth(),
                    $result->getHeight()
                );
            }
        }

        $fs = new Filesystem();
        try {
            $file = sprintf("%s/annotations.txt", sys_get_temp_dir());
            $fs->dumpFile($file, implode(PHP_EOL, $annotations));
            $stream = fopen($file, 'r+');
            if ($stream != false) {
                $filesystem->putStream('annotations.txt', $stream);
                fclose($stream);
            }
        } catch (IOExceptionInterface $e) {
            return sprintf('Error writing annotations: %s', $e->getPath());
        }

        return true;
    }
}
