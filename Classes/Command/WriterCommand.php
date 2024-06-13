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
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class WriterCommand extends Command
{
    public function __construct(protected readonly ExtensionConfiguration $extensionConfiguration)
    {
        parent::__construct('andersundsehr:redirects:writer');
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extConf = $this->extensionConfiguration->get('redirects_expoter');
        $directory = Environment::getProjectPath() . trim($extConf['directory'] . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);
        $directoryNew = Environment::getProjectPath() . trim($extConf['directoryNew'] . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);
        $directoryOld = Environment::getProjectPath() . trim($extConf['directoryOld'] . DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);

        $redirects = GeneralUtility::makeInstance(RedirectRepository::class)->findForExport();

        GeneralUtility::mkdir_deep($directory);
        GeneralUtility::mkdir_deep($directoryNew);
        array_map('unlink', array_filter((array)glob($directoryNew . '*')));
        foreach ($redirects as $redirect) {
            if ($redirect['tx_ausredirects_exporter_resolved']) {
                $stuff = json_decode($redirect['tx_ausredirects_exporter_resolved'], true, 512, JSON_THROW_ON_ERROR);
                if ($stuff['updatedon'] !== $redirect['updatedon']) {
                    continue;
                }

                $filename = $redirect['source_host'] === '*' ? 'wildcard' : $redirect['source_host'];
                file_put_contents($directoryNew . $filename . '.conf', $stuff['line'], FILE_APPEND);
            }
        }
        $output->writeln('exported', OutputInterface::VERBOSITY_VERBOSE);
        $output->writeln('try reload nginx...', OutputInterface::VERBOSITY_VERBOSE);
        rename($directory, $directoryOld);
        rename($directoryNew, $directory);
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
            GeneralUtility::rmdir($directoryOld, true);
            return Command::SUCCESS;
        }
        $output->write('rollback configuration...');
        rename($directory, $directoryNew);
        rename($directoryOld, $directory);
        $output->write('rollback done');
        return Command::FAILURE;
    }
}
