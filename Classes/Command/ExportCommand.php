<?php

declare(strict_types=1);

namespace AUS\AusRedirectsExporter\Command;

use Exception;
use GuzzleHttp\Psr7\Uri;
use Spatie\Async\Pool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Redirects\Service\RedirectService;

class ExportCommand extends Command
{
    protected RedirectService $redirectService;

    public function __construct(RedirectService $redirectService)
    {
        parent::__construct('andersundsehr:redirects:exporter');
        $this->redirectService = $redirectService;
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_redirect');
        $qb = $connection->createQueryBuilder();
        $res = $qb->select('*')->from('sys_redirect')->execute();


        $pool = Pool::create();
        $pool->concurrency(10);
        $pool->timeout(120);

        while ($row = $res->fetchAssociative()) {
            if ($row['tx_ausredirects_exporter_resolved']) {
                /** @noinspection JsonEncodingApiUsageInspection */
                $stuff = json_decode($row['tx_ausredirects_exporter_resolved'], true);
                if ($stuff && $stuff['updatedon'] === $row['updatedon']) {
                    $output->writeln($row['uid'] . ' already generated, skipping');
                    continue;
                }
            }

            if ($row['respect_query_parameters']) {
                $output->writeln($row['uid'] . ' Can not process sys_redirect with query parameters');
                continue;
            }

            if (strpos($row['source_path'], '/') === 0) {
                $slash = '';
            } else {
                $slash = '/';
            }

            $sourceUrl = 'https://' . $row['source_host'] . $slash . $row['source_path'];
            $requestUri = new Uri($sourceUrl);

            // If the matched redirect is found, resolve it, and check further
            $pool->add(
                function () use ($requestUri, $row) {
                    $context = stream_context_create(
                        [
                            'http' => [
                                'method' => 'HEAD',
                            ],
                            'ssl' => [
                                'verify_peer' => false,
                                'verify_peer_name' => false,
                                'allow_self_signed' => true,
                            ],
                        ]
                    );

                    $location = (string)$requestUri;
                    $locationCrumbs = [];
                    do {
                        if (strpos($location, '://') === false) {
                            $location = 'https://' . $row['source_host'] . $location;
                        }
                        $actualLocation = $location;
                        $location = '';
                        $headers = @get_headers($actualLocation, 1, $context);
                        if (!$headers) {
                            break;
                        }
                        if (isset($headers['Location'])) {
                            if (is_array($headers['Location'])) {
                                $location = array_pop($headers['Location']);
                            } else {
                                $location = $headers['Location'];
                            }
                        } elseif (isset($headers['location'])) {
                            if (is_array($headers['location'])) {
                                $location = array_pop($headers['location']);
                            } else {
                                $location = $headers['location'];
                            }
                        }

                        if ('' === $location) {
                            break;
                        }
                        if (in_array($location, $locationCrumbs, true)) {
                            file_put_contents('endless.txt', $location . PHP_EOL, FILE_APPEND);
                            throw new Exception($row['uid'] . ' seems to endless redirect (' . $location . ')');
                        }
                        $locationCrumbs[] = $location;
                    } while ($location);

                    if (!$locationCrumbs) {
                        file_put_contents('no_location_header.txt',  $requestUri  . PHP_EOL, FILE_APPEND);
                        throw new Exception($row['uid'] . ' no location in headers do nothing (' . $requestUri . ')');
                    }

                    $location = array_pop($locationCrumbs);

                    if ($requestUri->getPath() === $location) {
                        throw new Exception($row['uid'] . ' source equals target');
                    }
                    return $location;
                }
            )->then(
                function (string $location) use ($row, $requestUri, $connection, $output) {
                    $output->writeln('Generated a target: ' . $location);
                    // reappend target host. TODO t3:// links could point in an other pagetree which has an other base, so we have to work with Sites there
                    if (strpos($location, '://') === false) {
                        $location = 'https://' . $row['source_host'] . $location;
                    }


                    //http://nginx.org/en/docs/http/ngx_http_rewrite_module.html
                    $args = $row['keep_query_parameters'] ? '' : '?';
                    $line = 'rewrite (?i)^' . $requestUri->getPath(
                        ) . '$ ' . $this->quote($location . $args) . ' ' . ($row['target_statuscode'] === 302 ? 'redirect' : 'permanent') . ';' . PHP_EOL;

                    $result = [
                        'updatedon' => $row['updatedon'],
                        'location' => $location,
                        'line' => $line,
                    ];

                    /** @noinspection JsonEncodingApiUsageInspection */
                    $connection->executeQuery(
                        'UPDATE sys_redirect SET ' . 'tx_ausredirects_exporter_resolved' . '=' . $connection->quote(json_encode($result)) . ' WHERE uid=' . $row['uid']
                    );
                }
            )->catch(
                function (\Throwable $exception) use ($output) {
                    $output->writeln('Catch!');
                    if ($exception->getMessage()) {
                        $output->writeln($exception->getMessage());
                    }
                }
            )->timeout(
                function () use ($output) {
                    $output->writeln('Timeout!');
                }
            );

        }
        $pool->wait();
        return Command::SUCCESS;
    }

    protected function quote(string $line): string
    {
        $line = str_replace('"', '\"', $line);
        return '"' . $line . '"';
    }
}
