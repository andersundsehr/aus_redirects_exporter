<?php

declare(strict_types=1);

namespace AUS\AusRedirectsExporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectService;

class WriterCommand extends Command
{
    protected RedirectService $redirectService;

    public function __construct(RedirectService $redirectService)
    {
        parent::__construct('andersundsehr:redirects:writer');
        $this->redirectService = $redirectService;
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_redirect');
        $qb = $connection->createQueryBuilder();
        $res = $qb->select('*')->from('sys_redirect')->execute();

        $columnName = 'tx_ausredirects_exporter_resolved';
        while ($row = $res->fetchAssociative()) {
            if ($row[$columnName]) {
                $stuff = json_decode($row[$columnName], true, 512, JSON_THROW_ON_ERROR);
                if ($stuff) {
                    if ($stuff['updatedon'] !== $row['updatedon']) {
                        continue;
                    }

                    file_put_contents($row['source_host'] . '.txt', $stuff['line'], FILE_APPEND);
                }
            }
        }
        return Command::SUCCESS;
    }
}
