<?php
declare(strict_types=1);

namespace Dharma\Plugin\Console\DharmaSitemap\CliCommand;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Factory;
use Joomla\Console\Command\AbstractCommand;
use Joomla\Database\DatabaseInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

\defined('_JEXEC') or die;

final class BuildSitemapCommand extends AbstractCommand
{
    protected static $defaultName = 'dharmasitemap:build';

    protected function configure(): void
    {
        $this->setDescription('Собрать и опубликовать XML-карту сайта Dharma Sitemap.')
            ->setHelp("Собирает карту теми же источниками и правилами, что и кнопка компонента и задача Scheduler.\n\nПример: php cli/joomla.php dharmasitemap:build");
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $result = (new SitemapBuilder(Factory::getContainer()->get(DatabaseInterface::class)))->build();
            $io->success(sprintf(
                'Карта опубликована: сборка #%d, URL: %d, дублей: %d.',
                $result['build_id'],
                $result['url_count'],
                $result['duplicate_count']
            ));

            return 0;
        } catch (\Throwable $error) {
            $io->error('Сборка не опубликована: ' . $error->getMessage());

            return 1;
        }
    }
}
