<?php

declare(strict_types=1);

namespace AUS\AusRedirectsExporter\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectService;

class ClearCommand extends Command
{
    protected RedirectService $redirectService;

    public function __construct(RedirectService $redirectService)
    {
        parent::__construct('andersundsehr:redirects:clear');
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
        $columnName = 'tx_ausredirects_exporter_resolved';
        $qb->update('sys_redirect')->set($columnName, null)->execute();

        return Command::SUCCESS;
    }
}
