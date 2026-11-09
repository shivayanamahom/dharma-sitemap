<?php
declare(strict_types=1);

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Установочный скрипт пакета: включает плагины пакета. Joomla ставит плагины выключенными, без них карта не собирается
 * (нет поставщиков адресов, публичного адреса, задачи и команды), поэтому при первой установке включаются все, а при
 * обновлении — только те, которых на сайте ещё не было (выбор администратора по прежним плагинам не меняется).
 */
return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            InstallerScriptInterface::class,
            new class () implements InstallerScriptInterface {
                private const PLUGINS = [['dharmasitemap', 'core'], ['dharmasitemap', 'dharmashop'], ['system', 'dharmasitemap'], ['task', 'dharmasitemap'], ['console', 'dharmasitemap']];

                /** @var array<int, string> плагины, уже стоявшие до установки («группа:имя») */
                private array $present = [];

                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $db   = Factory::getContainer()->get(DatabaseInterface::class);
                    $rows = $db->setQuery(
                        $db->createQuery()->select([$db->quoteName('folder'), $db->quoteName('element')])
                            ->from($db->quoteName('#__extensions'))->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    )->loadObjectList();

                    foreach ($rows as $row) {
                        $this->present[] = $row->folder . ':' . $row->element;
                    }

                    return true;
                }

                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    if ($type === 'uninstall') {
                        return true;
                    }

                    $db = Factory::getContainer()->get(DatabaseInterface::class);

                    foreach (self::PLUGINS as [$group, $element]) {
                        if ($type !== 'install' && \in_array($group . ':' . $element, $this->present, true)) {
                            continue;
                        }

                        $query = $db->createQuery()
                            ->update($db->quoteName('#__extensions'))
                            ->set($db->quoteName('enabled') . ' = 1')
                            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                            ->where($db->quoteName('folder') . ' = :folder')
                            ->where($db->quoteName('element') . ' = :element')
                            ->bind(':folder', $group)
                            ->bind(':element', $element);
                        $db->setQuery($query)->execute();
                    }

                    return true;
                }
            }
        );
    }
};
