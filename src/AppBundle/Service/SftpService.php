<?php
namespace AppBundle\Service;

use AppBundle\Document\File\DirectGroupFile;
use AppBundle\Document\File\S3File;
use phpseclib\Crypt\RSA;
use phpseclib\Net\SFTP;
use Psr\Log\LoggerInterface;
use Aws\S3\S3Client;
use AppBundle\Classes\DaviesHandlerClaim;
use AppBundle\Document\Claim;
use AppBundle\Document\Phone;
use AppBundle\Document\CurrencyTrait;
use AppBundle\Document\DateTrait;
use AppBundle\Document\File\DaviesFile;
use Doctrine\ODM\MongoDB\DocumentManager;
use VasilDakov\Postcode\Postcode;

class SftpService
{
    use CurrencyTrait;
    use DateTrait;

    const PROCESSED_FOLDER = 'Processed';
    const FAILED_FOLDER = 'Failed';

    const LOGIN_PASSWORD = 'password';
    const LOGIN_KEYFILE = 'keyfile';
    const LOGIN_KEYFILE_PASSWORD = 'keyfile-password';

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var S3Client */
    protected $s3;

    /** @var string */
    protected $bucket;

    /** @var string */
    protected $path;

    /** @var string */
    protected $server;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $keyFile;

    /** @var string */
    protected $zipPassword;

    /** @var string */
    protected $environment;

    /** @var SFTP */
    protected $sftp;

    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        S3Client $s3,
        $environment,
        $bucket,
        $pathPrefix,
        array $sftpDetails,
        $zipPassword
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->s3 = $s3;
        $this->environment = $environment;
        $this->bucket = $bucket;
        $this->path = sprintf('%s/%s', $pathPrefix, $this->environment);
        $this->server = $sftpDetails[0];
        $this->username = $sftpDetails[1];
        $this->password = $sftpDetails[2];
        $this->keyFile = $sftpDetails[3];
        $this->zipPassword = $zipPassword;
    }

    private function getLoginType()
    {
        if ($this->keyFile && file_exists($this->keyFile)) {
            if (mb_strlen($this->password) > 0) {
                return self::LOGIN_KEYFILE_PASSWORD;
            } else {
                return self::LOGIN_KEYFILE;
            }
        } elseif (mb_strlen($this->password) > 0) {
            return self::LOGIN_PASSWORD;
        }

        throw new \Exception('Unable to determine login method');
    }

    private function loginSftp()
    {
        $this->sftp = new SFTP($this->server);
        $this->sftp->enableQuietMode();

        $loginType = $this->getLoginType();
        $key = new RSA();
        if (in_array($loginType, [self::LOGIN_KEYFILE_PASSWORD, self::LOGIN_KEYFILE])) {
            $key->loadKey(file_get_contents($this->keyFile));
        }

        $loginSuccess = false;
        if ($loginType == self::LOGIN_KEYFILE_PASSWORD) {
            $loginSuccess = $this->sftp->login($this->username, $key) ||
                $this->sftp->login($this->username, $this->password);
        } elseif ($loginType == self::LOGIN_KEYFILE) {
            $loginSuccess = $this->sftp->login($this->username, $key);
        } elseif ($loginType == self::LOGIN_PASSWORD) {
            $loginSuccess = $this->sftp->login($this->username, $this->password);
        }

        if (!$loginSuccess) {
            throw new \Exception(sprintf(
                'Login Failed for type %s. Msg: %s',
                $loginType,
                $this->sftp->getLastSFTPError()
            ));
        }
    }

    /**
     * @return array
     */
    public function listSftp($extension = '.zip')
    {
        if (!$this->sftp) {
            $this->loginSftp();
        }
        $files = $this->sftp->nlist('.', true);
        if ($files === false) {
            throw new \Exception(sprintf(
                'List folder Failed. Msg: %s',
                $this->sftp->getLastSFTPError()
            ));
        }
        $list = [];
        foreach ($files as $file) {
            if (mb_stripos($file, $extension) !== false) {
                $list[] = $file;
            }
        }

        return $list;
    }

    public function moveSftp($file, $folder)
    {
        if (!$this->sftp) {
            $this->loginSftp();
        }

        // it may take too long to process the file - if it fails, try logging in again
        if (!$this->sftp->rename($file, sprintf('%s/%s', $folder, $file))) {
            $this->loginSftp();
            if (!$this->sftp->rename($file, sprintf('%s/%s', $folder, $file))) {
                throw new \Exception(sprintf(
                    'Login Failed. Msg: %s',
                    $this->sftp->getLastSFTPError()
                ));
            }
        }
    }

    public function uploadS3($file, $name, $folder, S3File $s3File)
    {
        $now = \DateTime::createFromFormat('U', time());
        $extension = sprintf('.%s', pathinfo($name, PATHINFO_EXTENSION));
        $s3Key = sprintf(
            '%s/%s/%d/%s-%s%s',
            $this->path,
            $folder,
            $now->format('Y'),
            basename($name, $extension),
            $now->format('U'),
            $extension
        );
        $result = $this->s3->putObject(array(
            'Bucket' => $this->bucket,
            'Key'    => $s3Key,
            'SourceFile' => $file,
        ));

        $s3File->setBucket($this->bucket);
        $s3File->setKey($s3Key);
        if ($s3File instanceof DirectGroupFile) {
            $s3File->setSuccess($folder == self::PROCESSED_FOLDER);
        }
        $this->dm->persist($file);
        $this->dm->flush();

        return $s3Key;
    }

    public function generateTempFile()
    {
        $tempFile = tempnam(sys_get_temp_dir(), "s3email");

        return $tempFile;
    }

    public function downloadFile($file)
    {
        if (!$this->sftp) {
            $this->loginSftp();
        }
        $tempFile = $this->generateTempFile();

        if ($this->sftp->get($file, $tempFile) === false) {
            throw new \Exception(sprintf(
                'Failed to download file %s. Msg: %s',
                $file,
                $this->sftp->getLastSFTPError()
            ));
        }

        return $tempFile;
    }

    public function unzipFile($file, $extension = '.xlsx')
    {
        $files = [];

        $zip = new \ZipArchive();
        if ($zip->open($file) === true) {
            if ($zip->setPassword($this->zipPassword)) {
                if (!$zip->extractTo(sys_get_temp_dir())) {
                    throw new \Exception("Extraction failed (wrong password?)");
                }
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (mb_stripos($zip->getNameIndex($i), $extension) !== false) {
                        $files[] = sprintf('%s/%s', sys_get_temp_dir(), $zip->getNameIndex($i));
                    }
                }
            }

            $zip->close();
        }

        return $files;
    }
}
