<?php

declare(strict_types=1);

namespace AUS\AusRedirectsExporter\Domain\Repository;

use Doctrine\DBAL\Driver\ResultStatement;
use Doctrine\DBAL\Driver\Statement;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class RedirectRepository
{
    private Connection $connection;

    public function __construct()
    {
        $this->connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_redirect');
    }

    /**
     * @throws \JsonException
     */
    public function setResolved(int $uid, array $resolved): int
    {
        return $this->connection->update(
            'sys_redirect',
            [
                'tx_ausredirects_exporter_resolved' => json_encode($resolved, JSON_THROW_ON_ERROR),
            ], [
                'uid' => $uid,
            ]
        );
    }

    /**
     * @return Statement|\Doctrine\DBAL\ForwardCompatibility\Result|ResultStatement
     * @throws \JsonException
     *
     * @throws \Doctrine\DBAL\Driver\Exception
     */
    public function findForExport()
    {
        $queryBuilder = $this->connection->createQueryBuilder();
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        return $queryBuilder->select('*')
            ->from('sys_redirect')
            ->andWhere($queryBuilder->expr()->eq('is_regexp', 0))
            ->andWhere($queryBuilder->expr()->eq('respect_query_parameters', 0))
            ->execute();
    }
}
