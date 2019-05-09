<?php
namespace AppBundle\Service;

use Doctrine\ODM\MongoDB\DocumentManager;
use Predis\Client;
use Psr\Log\LoggerInterface;
use AppBundle\Classes\SoSure;
use AppBundle\Document\Payment\Payment;
use AppBundle\Document\File\S3File;
use AppBundle\Document\File\JudoFile;
use AppBundle\Document\File\BarclaysFile;
use AppBundle\Document\File\BarclaysStatementFile;
use AppBundle\Document\File\LloydsFile;
use AppBundle\Document\File\ReconciliationFile;
use AppBundle\Document\File\SalvaPaymentFile;
use AppBundle\Document\File\CashflowsFile;
use AppBundle\Document\File\CheckoutFile;
use AppBundle\Document\Payment\JudoPayment;
use AppBundle\Document\Payment\CheckoutPayment;
use AppBundle\Document\Payment\BacsPayment;
use AppBundle\Repository\File\ReconcilationFileRepository;
use AppBundle\Repository\File\JudoFileRepository;
use AppBundle\Repository\File\CheckoutFileRepository;
use AppBundle\Repository\File\LloydsFileRepository;
use AppBundle\Repository\File\S3FileRepository;
use AppBundle\Repository\File\BarclaysFileRepository;
use AppBundle\Repository\File\CashflowsFileRepository;
use AppBundle\Repository\PaymentRepository;

class BankingService
{
    const CACHE_KEY_FORMAT = 'Banking:%s:%s:%s';
    const CACHE_TIME = 86400; // 1 day

    /** @var DocumentManager */
    protected $dm;

    /** @var LoggerInterface */
    protected $logger;

    /** @var string */
    protected $environment;

    /** @var Client */
    protected $redis;

    /**
     * @param DocumentManager $dm
     * @param LoggerInterface $logger
     * @param string          $environment
     * @param Client          $redis
     */
    public function __construct(
        DocumentManager $dm,
        LoggerInterface $logger,
        $environment,
        Client $redis
    ) {
        $this->dm = $dm;
        $this->logger = $logger;
        $this->environment = $environment;
        $this->redis = $redis;
    }

    public function getCashflowsBanking(\DateTime $date, $year, $month, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'CashflowsBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );

        /** @var CashflowsFileRepository $cashflowsFileRepo */
        $cashflowsFileRepo = $this->dm->getRepository(CashflowsFile::class);
        $monthlyCashflowFiles = $cashflowsFileRepo->getMonthlyFiles($date);

        if ($useCache === true && $this->redis->exists($redisKey)) {
            $cashflows = unserialize($this->redis->get($redisKey));
            $cashflows['files'] = $monthlyCashflowFiles;
            return $cashflows;
        }

        $monthlyPerDayCashflowsTransaction = CashflowsFile::combineDailyTransactions($monthlyCashflowFiles);
        $monthlyPerDayCashflowsProcessing = CashflowsFile::combineDailyProcessing($monthlyCashflowFiles);

        $yearlyCashflowFiles = $cashflowsFileRepo->getYearlyFilesToDate($date);
        $yearlyCashflowsTransaction = CashflowsFile::combineDailyTransactions($yearlyCashflowFiles);
        $yearlyCashflowsProcessing = CashflowsFile::combineDailyProcessing($yearlyCashflowFiles);

        $allCashflowsFiles = $cashflowsFileRepo->getAllFilesToDate($date);
        $allCashflowsTransaction = CashflowsFile::combineDailyTransactions($allCashflowsFiles);
        $allCashflowsProcessing = CashflowsFile::combineDailyProcessing($allCashflowsFiles);

        $cashflows = [
            'dailyTransaction' => $monthlyPerDayCashflowsTransaction,
            'dailyProcessed' => $monthlyPerDayCashflowsProcessing,
            'monthlyTransaction' =>
                CashflowsFile::totalCombinedFiles($monthlyPerDayCashflowsTransaction, $year, $month),
            'monthlyProcessed' => CashflowsFile::totalCombinedFiles($monthlyPerDayCashflowsProcessing, $year, $month),
            'yearlyTransaction' => CashflowsFile::totalCombinedFiles($yearlyCashflowsTransaction),
            'yearlyProcessed' => CashflowsFile::totalCombinedFiles($yearlyCashflowsProcessing),
            'allTransaction' => CashflowsFile::totalCombinedFiles($allCashflowsTransaction),
            'allProcessed' => CashflowsFile::totalCombinedFiles($allCashflowsProcessing),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($cashflows));

        $cashflows['files'] = $monthlyCashflowFiles;

        return $cashflows;
    }

    public function getSoSureBanking(\DateTime $date, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'SoSureBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );
        if ($useCache === true && $this->redis->exists($redisKey)) {
            return unserialize($this->redis->get($redisKey));
        }

        /** @var PaymentRepository $paymentRepo */
        $paymentRepo = $this->dm->getRepository(Payment::class);

        $payments = $paymentRepo->getAllPaymentsForExport($date);
        $extraPayments = $paymentRepo->getAllPaymentsForExport($date, true);
        $extraCreditPayments = array_filter($extraPayments->toArray(), function ($v) {
            return $v->getAmount() >= 0.0;
        });
        $extraDebitPayments = array_filter($extraPayments->toArray(), function ($v) {
            return $v->getAmount() < 0.0;
        });
        $isProd = $this->environment === "prod";
        $tz = SoSure::getSoSureTimezone();
        $sosure = [
            'dailyTransaction' => Payment::dailyPayments($payments, $isProd),
            'monthlyTransaction' => Payment::sumPayments($payments, $isProd),
            'dailyShiftedTransaction' => Payment::dailyPayments($payments, $isProd, null, $tz),
            'dailyJudoTransaction' => Payment::dailyPayments($payments, $isProd, JudoPayment::class),
            'dailyCheckoutTransaction' => Payment::dailyPayments($payments, $isProd, CheckoutPayment::class),
            'monthlyJudoTransaction' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'monthlyCheckoutTransaction' => Payment::sumPayments($payments, $isProd, CheckoutPayment::class),
            'dailyJudoShiftedTransaction' => Payment::dailyPayments($payments, $isProd, JudoPayment::class, $tz),
            'dailyCheckoutShiftedTransaction' =>
                Payment::dailyPayments($payments, $isProd, CheckoutPayment::class, $tz),
            'monthlyJudoShiftedTransaction' => Payment::sumPayments($payments, $isProd, JudoPayment::class),
            'monthlyCheckoutShiftedTransaction' => Payment::sumPayments($payments, $isProd, CheckoutPayment::class),
            'dailyCreditBacsTransaction' => Payment::dailyPayments(
                $extraCreditPayments,
                $isProd,
                BacsPayment::class,
                null,
                'getBacsCreditDate'
            ),
            'dailyDebitBacsTransaction' => Payment::dailyPayments(
                $extraDebitPayments,
                $isProd,
                BacsPayment::class
            ),
            'dailyBacsTransaction' => Payment::dailyPayments(
                $payments,
                $isProd,
                BacsPayment::class
            ),
            'monthlyBacsTransaction' => Payment::sumPayments($payments, $isProd, BacsPayment::class),
            'payments' => json_decode(json_encode($payments), true),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($sosure));

        return $sosure;
    }

    public function getReconcilationBanking(\DateTime $date, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'ReconcilationBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );

        /** @var ReconcilationFileRepository $reconcilationFileRepo */
        $reconcilationFileRepo = $this->dm->getRepository(ReconciliationFile::class);
        $monthlyReconcilationFiles = $reconcilationFileRepo->getMonthlyFiles($date);

        if ($useCache === true && $this->redis->exists($redisKey)) {
            $reconciliation = unserialize($this->redis->get($redisKey));
            $reconciliation['files'] = $monthlyReconcilationFiles;
            return $reconciliation;
        }

        $yearlyReconcilationFiles = $reconcilationFileRepo->getYearlyFilesToDate($date);
        $allReconcilationFiles = $reconcilationFileRepo->getAllFilesToDate($date);

        $reconciliation = [
            'monthlyTransaction' => ReconciliationFile::combineMonthlyTotal($monthlyReconcilationFiles),
            'yearlyTransaction' => ReconciliationFile::combineMonthlyTotal($yearlyReconcilationFiles),
            'allTransaction' => ReconciliationFile::combineMonthlyTotal($allReconcilationFiles),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($reconciliation));

        $reconciliation['files'] = $monthlyReconcilationFiles;

        return $reconciliation;
    }

    public function getJudoBanking(\DateTime $date, $year, $month, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'JudoBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );

        /** @var JudoFileRepository $judoFileRepo */
        $judoFileRepo = $this->dm->getRepository(JudoFile::class);
        $monthlyJudoFiles = $judoFileRepo->getMonthlyFiles($date);

        if ($useCache === true && $this->redis->exists($redisKey)) {
            $judo = unserialize($this->redis->get($redisKey));
            $judo['files'] = $monthlyJudoFiles;
            return $judo;
        }

        $monthlyPerDayJudoTransaction = JudoFile::combineDailyTransactions($monthlyJudoFiles);

        $yearlyJudoFiles = $judoFileRepo->getYearlyFilesToDate($date);
        $yearlyPerDayJudoTransaction = JudoFile::combineDailyTransactions($yearlyJudoFiles);

        $allJudoFiles = $judoFileRepo->getAllFilesToDate($date);
        $allJudoTransaction = JudoFile::combineDailyTransactions($allJudoFiles);

        $judo = [
            'dailyTransaction' => $monthlyPerDayJudoTransaction,
            'monthlyTransaction' => JudoFile::totalCombinedFiles($monthlyPerDayJudoTransaction, $year, $month),
            'yearlyTransaction' => JudoFile::totalCombinedFiles($yearlyPerDayJudoTransaction),
            'allTransaction' => JudoFile::totalCombinedFiles($allJudoTransaction),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($judo));

        $judo['files'] = $monthlyJudoFiles;

        return $judo;
    }

    public function getCheckoutBanking(\DateTime $date, $year, $month, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'CheckoutBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );

        /** @var CheckoutFileRepository $checkoutFileRepo */
        $checkoutFileRepo = $this->dm->getRepository(CheckoutFile::class);
        $monthlyCheckoutFiles = $checkoutFileRepo->getMonthlyFiles($date);

        if ($useCache === true && $this->redis->exists($redisKey)) {
            $checkout = unserialize($this->redis->get($redisKey));
            $checkout['files'] = $monthlyCheckoutFiles;
            return $checkout;
        }

        $monthlyPerDayCheckoutTransaction = CheckoutFile::combineDailyTransactions($monthlyCheckoutFiles);

        $yearlyCheckoutFiles = $checkoutFileRepo->getYearlyFilesToDate($date);
        $yearlyPerDayCheckoutTransaction = CheckoutFile::combineDailyTransactions($yearlyCheckoutFiles);

        $allCheckoutFiles = $checkoutFileRepo->getAllFilesToDate($date);
        $allCheckoutTransaction = CheckoutFile::combineDailyTransactions($allCheckoutFiles);

        $checkout = [
            'dailyTransaction' => $monthlyPerDayCheckoutTransaction,
            'monthlyTransaction' => CheckoutFile::totalCombinedFiles($monthlyPerDayCheckoutTransaction, $year, $month),
            'yearlyTransaction' => CheckoutFile::totalCombinedFiles($yearlyPerDayCheckoutTransaction),
            'allTransaction' => CheckoutFile::totalCombinedFiles($allCheckoutTransaction),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($checkout));

        $checkout['files'] = $monthlyCheckoutFiles;

        return $checkout;
    }

    public function getSalvaBanking(\DateTime $date, $year, $month, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'SalvaBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );
        if ($useCache === true && $this->redis->exists($redisKey)) {
            return unserialize($this->redis->get($redisKey));
        }

        /** @var S3FileRepository $salvaFileRepo */
        $salvaFileRepo = $this->dm->getRepository(SalvaPaymentFile::class);

        $monthlySalvaFiles = $salvaFileRepo->getMonthlyFiles($date);
        $monthlyPerDaySalvaTransaction = SalvaPaymentFile::combineDailyTransactions($monthlySalvaFiles);

        $salva = [
            'dailyTransaction' => $monthlyPerDaySalvaTransaction,
            'monthlyTransaction' => SalvaPaymentFile::totalCombinedFiles(
                $monthlyPerDaySalvaTransaction,
                $year,
                $month
            ),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($salva));

        return $salva;
    }

    public function getBarclaysBanking(\DateTime $date, $year, $month, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'BarclaysBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );

        /** @var BarclaysFileRepository $barclaysFileRepo */
        $barclaysFileRepo = $this->dm->getRepository(BarclaysFile::class);
        $monthlyBarclaysFiles = $barclaysFileRepo->getMonthlyFiles($date);

        if ($useCache === true && $this->redis->exists($redisKey)) {
            $barclays = unserialize($this->redis->get($redisKey));
            $barclays['files'] = $monthlyBarclaysFiles;
            return $barclays;
        }

        $monthlyPerDayBarclaysTransaction = BarclaysFile::combineDailyTransactions($monthlyBarclaysFiles);
        $monthlyPerDayBarclaysProcessing = BarclaysFile::combineDailyProcessing($monthlyBarclaysFiles);

        $yearlyBarclaysFiles = $barclaysFileRepo->getYearlyFilesToDate($date);
        $yearlyBarclaysTransaction = BarclaysFile::combineDailyTransactions($yearlyBarclaysFiles);
        $yearlyBarclaysProcessing = BarclaysFile::combineDailyProcessing($yearlyBarclaysFiles);

        $allBarclaysFiles = $barclaysFileRepo->getAllFilesToDate($date);
        $allBarclaysTransaction = BarclaysFile::combineDailyTransactions($allBarclaysFiles);
        $allBarclaysProcessing = BarclaysFile::combineDailyProcessing($allBarclaysFiles);

        $barclays = [
            'dailyTransaction' => $monthlyPerDayBarclaysTransaction,
            'dailyProcessed' => $monthlyPerDayBarclaysProcessing,
            'monthlyTransaction' => BarclaysFile::totalCombinedFiles($monthlyPerDayBarclaysTransaction, $year, $month),
            'monthlyProcessed' => BarclaysFile::totalCombinedFiles($monthlyPerDayBarclaysProcessing, $year, $month),
            'yearlyTransaction' => BarclaysFile::totalCombinedFiles($yearlyBarclaysTransaction),
            'yearlyProcessed' => BarclaysFile::totalCombinedFiles($yearlyBarclaysProcessing),
            'allTransaction' => BarclaysFile::totalCombinedFiles($allBarclaysTransaction),
            'allProcessed' => BarclaysFile::totalCombinedFiles($allBarclaysProcessing),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($barclays));

        $barclays['files'] = $monthlyBarclaysFiles;

        return $barclays;
    }

    public function getLloydsBanking(\DateTime $date, $year, $month, $useCache = true)
    {
        $redisKey = sprintf(
            self::CACHE_KEY_FORMAT,
            'LloydsBanking',
            $this->environment === "prod" ? 'prod' : 'non-prod',
            $date->format('Y-m-d')
        );

        /** @var LloydsFileRepository $lloydsFileRepo */
        $lloydsFileRepo = $this->dm->getRepository(LloydsFile::class);
        $monthlyLloydsFiles = $lloydsFileRepo->getMonthlyFiles($date);

        if ($useCache === true && $this->redis->exists($redisKey)) {
            $lloyds = unserialize($this->redis->get($redisKey));
            $lloyds['files'] = $monthlyLloydsFiles;
            return $lloyds;
        }

        $monthlyPerDayLloydsReceived = LloydsFile::combineDailyReceived($monthlyLloydsFiles);
        $monthlyPerDayLloydsProcessing = LloydsFile::combineDailyProcessing($monthlyLloydsFiles);
        $monthlyPerDayLloydsBacs = LloydsFile::combineDailyBacs($monthlyLloydsFiles);
        $monthlyPerDayLloydsCreditBacs = LloydsFile::combineDailyCreditBacs($monthlyLloydsFiles);
        $monthlyPerDayLloydsDebitBacs = LloydsFile::combineDailyDebitBacs($monthlyLloydsFiles);

        $yearlyLloydsFiles = $lloydsFileRepo->getYearlyFilesToDate($date);
        $yearlyPerDayLloydsReceived = LloydsFile::combineDailyReceived($yearlyLloydsFiles);
        $yearlyPerDayLloydsProcessing = LloydsFile::combineDailyProcessing($yearlyLloydsFiles);
        $yearlyPerDayLloydsBacs = LloydsFile::combineDailyBacs($monthlyLloydsFiles);

        $allLloydsFiles = $lloydsFileRepo->getAllFilesToDate($date);
        $allLloydsReceived = LloydsFile::combineDailyReceived($allLloydsFiles);
        $allLloydsProcessing = LloydsFile::combineDailyProcessing($allLloydsFiles);
        $allLloydsBacs = LloydsFile::combineDailyBacs($allLloydsFiles);

        $lloyds = [
            'dailyReceived' => $monthlyPerDayLloydsReceived,
            'dailyProcessed' => $monthlyPerDayLloydsProcessing,
            'dailyCreditBacs' => $monthlyPerDayLloydsCreditBacs,
            'dailyDebitBacs' => $monthlyPerDayLloydsDebitBacs,
            'dailyBacs' => $monthlyPerDayLloydsBacs,
            'monthlyReceived' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsReceived, $year, $month),
            'monthlyProcessed' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsProcessing, $year, $month),
            'monthlyBacs' => LloydsFile::totalCombinedFiles($monthlyPerDayLloydsBacs, $year, $month),
            'yearlyReceived' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsReceived),
            'yearlyProcessed' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsProcessing),
            'yearlyBacs' => LloydsFile::totalCombinedFiles($yearlyPerDayLloydsBacs),
            'allReceived' => LloydsFile::totalCombinedFiles($allLloydsReceived),
            'allProcessed' => LloydsFile::totalCombinedFiles($allLloydsProcessing),
            'allBacs' => LloydsFile::totalCombinedFiles($allLloydsBacs),
        ];

        $this->redis->setex($redisKey, self::CACHE_TIME, serialize($lloyds));

        $lloyds['files'] = $monthlyLloydsFiles;

        return $lloyds;
    }
}
