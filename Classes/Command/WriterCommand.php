<?php

declare(strict_types=1);

namespace AUS\AusRedirectsExporter\Command;

use AUS\AusRedirectsExporter\Domain\Repository\RedirectRepository;
use Doctrine\DBAL\Driver\Exception;
use JsonException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WriterCommand extends Command
{
    public function __construct()
    {
        parent::__construct('andersundsehr:redirects:writer');
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $redirects = GeneralUtility::makeInstance(RedirectRepository::class)->findForExport();

        $directory = Environment::getProjectPath() . '/compose/nginx-assets';
        GeneralUtility::mkdir_deep($directory . '/automated-redirects/');
        GeneralUtility::mkdir_deep($directory . '/automated-redirects-new/');
        array_map('unlink', array_filter((array)glob($directory . '/automated-redirects-new/*')));
        foreach ($redirects as $redirect) {
            if ($redirect['tx_ausredirects_exporter_resolved']) {
                $stuff = json_decode($redirect['tx_ausredirects_exporter_resolved'], true, 512, JSON_THROW_ON_ERROR);
                if ($stuff['updatedon'] !== $redirect['updatedon']) {
                    continue;
                }

                $filename = $redirect['source_host'] === '*' ? 'wildcard' : $redirect['source_host'];
                file_put_contents($directory . '/automated-redirects-new/' . $filename . '.conf', $stuff['line'], FILE_APPEND);
            }
        }
        $output->writeln('exported', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('try reload nginx...', OutputInterface::VERBOSITY_VERBOSE);
        rename($directory . '/automated-redirects/', $directory . '/automated-redirects-old/');
        rename($directory . '/automated-redirects-new/', $directory . '/automated-redirects/');
        $configTest = new Process(['sudo', 'nginx', '-t']);
        $resultCode = $configTest->run();
        $output->writeln(sprintf('config test %s', $resultCode ? 'Error' : 'Success'), OutputInterface::VERBOSITY_VERBOSE);
        $output->write($configTest->getOutput(), false, OutputInterface::VERBOSITY_VERBOSE);
        $output->write($configTest->getErrorOutput());

        if ($resultCode === 0) {
            $reloadCommand = new Process(['sudo', 'nginx', '-s', 'reload']);
            $reloadCommand->mustRun();
            $output->writeln('reloaded nginx', OutputInterface::VERBOSITY_VERBOSE);
            $output->write($reloadCommand->getOutput(), false, OutputInterface::VERBOSITY_VERBOSE);
            $output->write($reloadCommand->getErrorOutput());
            GeneralUtility::rmdir($directory . '/automated-redirects-old/', true);
            return Command::SUCCESS;
        }
        $output->write('rollback configuration...');
        rename($directory . '/automated-redirects/', $directory . '/automated-redirects-new/');
        rename($directory . '/automated-redirects-old/', $directory . '/automated-redirects/');
        $output->write('rollback done');
        return Command::FAILURE;
    }
}
