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

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $server;

    /** @var string */
    protected $username;

    /** @var string */
    protected $password;

    /** @var string */
    protected $keyFile;

    /** @var string */
    protected $baseFolder;

    /** @var boolean */
    protected $recursive;

    /** @var SFTP */
    protected $sftp;

    public function __construct(
        LoggerInterface $logger,
        array $sftpDetails
    ) {
        $this->logger = $logger;
        $this->server = $sftpDetails[0];
        $this->username = $sftpDetails[1];
        $this->password = $sftpDetails[2];
        $this->keyFile = $sftpDetails[3];
        $this->baseFolder = $sftpDetails[4];
        $this->recursive = filter_var($sftpDetails[5], FILTER_VALIDATE_BOOLEAN);
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

        if ($this->baseFolder) {
            $this->sftp->chdir($this->baseFolder);
        }

        if (!file_exists(self::PROCESSED_FOLDER)) {
            $this->sftp->mkdir(self::PROCESSED_FOLDER);
        }

        if (!file_exists(self::FAILED_FOLDER)) {
            $this->sftp->mkdir(self::FAILED_FOLDER);
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
        $files = $this->sftp->nlist('.', $this->recursive);
        if ($files === false) {
            throw new \Exception(sprintf(
                'List folder Failed. Msg: %s',
                $this->sftp->getLastSFTPError()
            ));
        }
        $list = [];
        foreach ($files as $file) {
            $isProcessed = mb_stripos($file, self::PROCESSED_FOLDER) !== false;
            $isFailed = mb_stripos($file, self::FAILED_FOLDER) !== false;
            $hasExtension = mb_stripos($file, $extension) !== false;

            if ($hasExtension && !$isProcessed && !$isFailed) {
                $list[] = $file;
            }
        }

        return $list;
    }

    public function moveSftp($file, $success)
    {
        $this->moveSftpToFolder($file, $success ? self::PROCESSED_FOLDER : self::FAILED_FOLDER);
    }

    public function moveSftpToFolder($file, $folder)
    {
        if (!$this->sftp) {
            $this->loginSftp();
        }

        $newFile = sprintf('%s/%s', $folder, basename($file));

        // it may take too long to process the file - if it fails, try logging in again
        if (!$this->sftp->rename($file, $newFile)) {
            $this->loginSftp();
            $this->sftp->delete($newFile, false);
            if (!$this->sftp->rename($file, $newFile)) {
                throw new \Exception(sprintf(
                    'Failed to move %s to %s. (Login Failed?) Msg: %s',
                    $file,
                    $newFile,
                    $this->sftp->getLastSFTPError()
                ));
            }
        }

        $hasSubfolder = basename($file) != $file;
        if ($hasSubfolder) {
            $this->sftp->rmdir(pathinfo($file, PATHINFO_DIRNAME));
        }
    }

    public function generateTempFile()
    {
        $tempFile = tempnam(sys_get_temp_dir(), "sftp-");

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
}
