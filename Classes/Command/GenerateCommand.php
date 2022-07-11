<?php

declare(strict_types=1);

namespace AUS\AusRedirectsExporter\Command;

use AUS\AusRedirectsExporter\Domain\Repository\RedirectRepository;
use Exception;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Http\ServerRequestFactory;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Redirects\Service\RedirectService;

class GenerateCommand extends Command
{
    protected RedirectService $redirectService;

    public function __construct(RedirectService $redirectService)
    {
        parent::__construct('andersundsehr:redirects:generate');
        $this->redirectService = $redirectService;
    }

    public function configure(): void
    {
        $this->addOption('force-trailing-slash', null, InputOption::VALUE_REQUIRED, 'Forces trailing slash in path', false);
    }

    protected function isForceTrailingSlash(InputInterface $input): bool
    {
        return filter_var($input->getOption('force-trailing-slash'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @throws \Doctrine\DBAL\Driver\Exception
     * @throws \Doctrine\DBAL\Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $sites = $siteFinder->getAllSites();
        $redirectRepository = GeneralUtility::makeInstance(RedirectRepository::class);
        $redirects = $redirectRepository->findForExport();
        $io = $output;
        if ($output instanceof ConsoleOutputInterface) {
            $io = new SymfonyStyle($input, $output);
            $io->progressStart($redirects->rowCount());
        }
        foreach ($redirects as $row) {
            if ($io instanceof SymfonyStyle) {
                $io->progressAdvance();
            }
            if ($row['tx_ausredirects_exporter_resolved']) {
                /** @noinspection JsonEncodingApiUsageInspection */
                $stuff = json_decode($row['tx_ausredirects_exporter_resolved'], true);
                if ($stuff && $stuff['updatedon'] === $row['updatedon']) {
                    $io->writeln(sprintf("no update needed for %d", $row['uid']), OutputInterface::VERBOSITY_DEBUG);
                    continue;
                }
            }
            if ($row['respect_query_parameters']) {
                $io->writeln(
                    sprintf("redirect %d has respect_query_parameters enabled (not supported)", $row['uid']),
                    OutputInterface::OUTPUT_NORMAL
                );
                continue;
            }

            if (strpos($row['source_path'], '/') === 0) {
                $slash = '';
            } else {
                $slash = '/';
            }

            $sourceUrl = 'https://' . $row['source_host'] . $slash . $row['source_path'];
            $requestUri = new Uri($sourceUrl);

            $port = $requestUri->getPort();
            $matchedRedirect = $this->redirectService->matchRedirect(
                $requestUri->getHost() . ($port ? ':' . $port : ''),
                $requestUri->getPath(),
                $requestUri->getQuery() ?? ''
            );

            if (!$matchedRedirect) {
                $io->writeln(sprintf("cloud not match redirect %d", $row['uid']), OutputInterface::VERBOSITY_VERBOSE);
                continue;
            }

            if ($matchedRedirect['uid'] !== $row['uid']) {
                $io->writeln(
                    sprintf("matched redirect %d not the same as row %d", $matchedRedirect['uid'], $row['uid']),
                    OutputInterface::OUTPUT_NORMAL
                );
            }

            if ($matchedRedirect['is_regexp'] ?? false) {
                $io->writeln(sprintf("redirect is regexp %d (not supported)", $matchedRedirect['uid']), OutputInterface::OUTPUT_NORMAL);
                continue;
            }

            GeneralUtility::flushInternalRuntimeCaches();
            $_SERVER['HTTP_HOST'] = $requestUri->getHost();
            $GLOBALS['TYPO3_REQUEST'] = ServerRequestFactory::fromGlobals();

            $site = new NullSite();
            foreach ($sites as $siteCandidate) {
                if ($siteCandidate->getBase()->getHost() === $requestUri->getHost()) {
                    $site = $siteCandidate;
                    break;
                }
            }
            $frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
            $frontendUser->start();
            try {
                $redirectUri = $this->redirectService->getTargetUrl($matchedRedirect, [], $frontendUser, $requestUri, $site);
                if (null === $redirectUri) {
                    $io->writeln(
                        sprintf("cloud not getTargetUrl for redirect %d", $matchedRedirect['uid']),
                        OutputInterface::VERBOSITY_VERBOSE
                    );
                    continue;
                }
                if (!$redirectUri->getHost() && $requestUri->getHost() !== '*') {
                    $redirectUri = $redirectUri->withHost($requestUri->getHost())->withScheme($requestUri->getScheme())->withPort($requestUri->getPort());
                }
            } catch (Exception $exception) {
                $io->writeln($exception->getMessage());
                continue;
            }

            if (($redirectUri instanceof UriInterface) && $this->redirectUriWillRedirectToCurrentUri($requestUri, $redirectUri)) {
                $io->writeln(
                    sprintf("redirectUriWillRedirectToCurrentUri redirect %d", $matchedRedirect['uid']),
                    OutputInterface::VERBOSITY_VERBOSE
                );
                continue;
            }

            if ($this->isForceTrailingSlash($input)) {
                $path = $redirectUri->getPath();
                if ($path !== '' && substr($path, -1) !== '/') {
                    $redirectUri = $redirectUri->withPath($path . '/');
                }
            }

            $location = (string)$redirectUri;
            $args = $row['keep_query_parameters'] ? '' : '?';
            $option = $row['target_statuscode'] === 302 ? 'redirect' : 'permanent';
            $destination = $this->quote($location . $args);
            $line = 'rewrite (?i)^' . $this->sanitizePathForLine($requestUri->getPath()) . '$ ' . $destination . ' ' . $option . ';'. PHP_EOL;
            $result = [
                'updatedon' => $row['updatedon'],
                'location' => $location,
                'line' => $line,
            ];

            $redirectRepository->setResolved((int)$row['uid'], $result);
        }
        if ($io instanceof SymfonyStyle) {
            $io->progressFinish();
        }
        return Command::SUCCESS;
    }

    /**
     * Checks if redirect uri matches current request uri.
     */
    protected function redirectUriWillRedirectToCurrentUri(UriInterface $requestUri, UriInterface $redirectUri): bool
    {
        $redirectIsAbsolute = $redirectUri->getHost() && $redirectUri->getScheme();
        $requestUri = $this->sanitizeUriForComparison($requestUri, !$redirectIsAbsolute);
        $redirectUri = $this->sanitizeUriForComparison($redirectUri, !$redirectIsAbsolute);
        return (string)$requestUri === (string)$redirectUri;
    }

    /**
     * Taken from RedirectHandler.php
     *
     * Strip down uri to be suitable to make valid comparison in 'redirectUriWillRedirectToCurrentUri()'
     * if uri is pointing to itself and redirect should be processed.
     */
    protected function sanitizeUriForComparison(UriInterface $uri, bool $relativeCheck): UriInterface
    {
        // Remove schema, host and port if we need to sanitize for relative check.
        if ($relativeCheck) {
            $uri = $uri->withScheme('')->withHost('')->withPort(null);
        }

        // Remove default port by schema, as they are superfluous and not meaningful enough, and even not
        // set in a request uri as this depends a lot on the used webserver setup and infrastructure.
        $portDefaultSchemaMap = [
            // we only need web ports here, as web request could not be done over another
            // schema at all, ex. ftp or mailto.
            80 => 'http',
            443 => 'https',
        ];
        if (
            !$relativeCheck
            && $uri->getScheme()
            && isset($portDefaultSchemaMap[$uri->getPort()])
            && $uri->getScheme() === $portDefaultSchemaMap[$uri->getPort()]
        ) {
            $uri = $uri->withPort(null);
        }

        // Remove userinfo, as request would not hold it and so comparing would lead to a false-positive result
        if ($uri->getUserInfo()) {
            $uri = $uri->withUserInfo('');
        }

        // Browser should and do not hand over the fragment part in a request as this is defined to be handled
        // by clients only in the protocol, thus we remove the fragment to be safe and do not end in redirect loop
        // for targets with fragments because we do not get it in the request. Still not optimal but the best we
        // can do in this case.
        if ($uri->getFragment()) {
            $uri = $uri->withFragment('');
        }

        // Query arguments do not have to be in the same order to be the same outcome, thus sorting them will
        // give us a valid comparison, and we can correctly determine if we would have a redirect to the same uri.
        // Arguments with empty values are kept, because removing them might lead to false-positives in some cases.
        if ($uri->getQuery()) {
            $parts = [];
            parse_str($uri->getQuery(), $parts);
            ksort($parts);
            $uri = $uri->withQuery(HttpUtility::buildQueryString($parts));
        }

        return $uri;
    }

    protected function quote(string $line): string
    {
        $line = str_replace('"', '\"', $line);
        return '"' . $line . '"';
    }

    protected function sanitizePathForLine(string $str): string
    {
        $str = preg_quote($str);
        $str = str_replace(';', '\;', $str);
        return $str;
    }
}
