<?php
declare(strict_types=1);

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Uri\Uri;

\defined('_JEXEC') or die;

$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$params = ComponentHelper::getParams('com_dharmasitemap');
$base = rtrim((string) $params->get('sitemap_base_url', '') ?: Uri::root(), '/');
$sitemapUrl = $base . '/sitemap.xml';
$endpoint = Route::link('site', 'index.php?option=com_dharmasitemap&view=sitemap&format=xml', false, Route::TLS_FORCE, true);
$schedulerUrl = Route::_('index.php?option=com_scheduler&view=tasks');
$pluginsUrl = Route::_('index.php?option=com_plugins&view=plugins');
$identity = Factory::getApplication()->getIdentity();
$canEditSettings = $identity->authorise('core.options', 'com_dharmasitemap') || $identity->authorise('core.admin', 'com_dharmasitemap');
$canManagePermissions = $identity->authorise('core.admin', 'com_dharmasitemap');
?>
<div class="container-fluid">
    <h1><?php echo Text::_('COM_DHARMASITEMAP_TITLE'); ?></h1>
    <p class="text-muted"><?php echo Text::_('COM_DHARMASITEMAP_INTRO'); ?></p>

    <?php echo HTMLHelper::_('uitab.startTabSet', 'dharmaSitemapTabs', ['active' => 'overview', 'recall' => true]); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'dharmaSitemapTabs', 'overview', Text::_('COM_DHARMASITEMAP_TAB_OVERVIEW')); ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4"><?php echo Text::_('COM_DHARMASITEMAP_GENERATION'); ?></h2>
                    <p><?php echo Text::_('COM_DHARMASITEMAP_GENERATION_DESC'); ?></p>
                    <form action="<?php echo Route::_('index.php?option=com_dharmasitemap&task=generate.build'); ?>" method="post">
                        <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_DHARMASITEMAP_BUILD_NOW'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4"><?php echo Text::_('COM_DHARMASITEMAP_LAST_BUILD'); ?></h2>
                    <?php if ($this->activeBuild) : ?>
                        <dl class="row mb-0">
                            <dt class="col-sm-3"><?php echo Text::_('COM_DHARMASITEMAP_FIELD_STATUS'); ?></dt><dd class="col-sm-9"><?php echo $escape(Text::_('COM_DHARMASITEMAP_STATUS_READY')); ?></dd>
                            <dt class="col-sm-3"><?php echo Text::_('COM_DHARMASITEMAP_FIELD_FINISHED'); ?></dt><dd class="col-sm-9"><?php echo $escape($this->activeBuild->finished_at); ?> UTC</dd>
                            <dt class="col-sm-3"><?php echo Text::_('COM_DHARMASITEMAP_FIELD_URL_COUNT'); ?></dt><dd class="col-sm-9"><?php echo (int) $this->activeBuild->url_count; ?></dd>
                            <dt class="col-sm-3"><?php echo Text::_('COM_DHARMASITEMAP_FIELD_DUPLICATES'); ?></dt><dd class="col-sm-9"><?php echo (int) $this->activeBuild->duplicate_count; ?></dd>
                            <dt class="col-sm-3"><?php echo Text::_('COM_DHARMASITEMAP_FIELD_ENDPOINT'); ?></dt><dd class="col-sm-9"><a href="<?php echo $escape($sitemapUrl); ?>" target="_blank" rel="noopener"><?php echo $escape($sitemapUrl); ?></a></dd>
                        </dl>
                        <?php if (!empty($this->report['excluded']['noindex'])) : ?>
                            <div class="alert alert-warning mt-3 mb-0"><?php echo $escape(Text::sprintf('COM_DHARMASITEMAP_NOINDEX_WARNING', (int) $this->report['excluded']['noindex'])); ?></div>
                        <?php endif; ?>
                        <p class="text-muted small mt-3 mb-0"><?php echo $escape(Text::sprintf('COM_DHARMASITEMAP_ROBOTS_TXT_HINT', $sitemapUrl)); ?></p>
                    <?php else : ?>
                        <p class="mb-0"><?php echo Text::_('COM_DHARMASITEMAP_NO_BUILD'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php if ($this->report['sources']) : ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h2 class="h4"><?php echo Text::_('COM_DHARMASITEMAP_REPORT'); ?></h2>
                    <table class="table table-sm mb-0">
                        <thead><tr>
                            <th><?php echo Text::_('COM_DHARMASITEMAP_REPORT_SOURCE'); ?></th>
                            <th class="text-end"><?php echo Text::_('COM_DHARMASITEMAP_REPORT_COUNT'); ?></th>
                            <th><?php echo Text::_('COM_DHARMASITEMAP_REPORT_EXCLUDED'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($this->report['sources'] as $row) : ?>
                            <?php
                            $notes = [];
                            foreach ((array) ($row['excluded'] ?? []) as $reason => $number) {
                                $notes[] = Text::_('COM_DHARMASITEMAP_REASON_' . strtoupper((string) $reason)) . ': ' . (int) $number;
                            }
                            ?>
                            <tr>
                                <td><?php echo $escape($row['source'] ?? ''); ?></td>
                                <td class="text-end"><?php echo (int) ($row['count'] ?? 0); ?></td>
                                <td class="text-muted"><?php echo ($row['status'] ?? '') === 'unavailable' ? $escape(Text::_('COM_DHARMASITEMAP_REPORT_UNAVAILABLE')) : $escape(implode(', ', $notes)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-12">
            <div class="card">
                <div class="card-body d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-primary" href="<?php echo $escape($schedulerUrl); ?>"><?php echo Text::_('COM_DHARMASITEMAP_SCHEDULER'); ?></a>
                    <a class="btn btn-link" href="<?php echo $escape($endpoint); ?>" target="_blank" rel="noopener"><?php echo Text::_('COM_DHARMASITEMAP_XML_ENDPOINT'); ?></a>
                </div>
                <div class="card-footer text-muted small"><?php echo Text::_('COM_DHARMASITEMAP_STATIC_FILE_NOTE'); ?></div>
            </div>
        </div>
    </div>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.addTab', 'dharmaSitemapTabs', 'settings', Text::_('COM_DHARMASITEMAP_SETTINGS')); ?>
    <?php if ($canEditSettings) : ?>
    <form action="<?php echo Route::_('index.php?option=com_config'); ?>" id="dharma-sitemap-settings" method="post" class="form-validate">
        <?php echo HTMLHelper::_('uitab.startTabSet', 'dharmaSitemapSettingsTabs', ['active' => 'general', 'recall' => true]); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'dharmaSitemapSettingsTabs', 'general', Text::_('COM_DHARMASITEMAP_CONFIG_GENERAL')); ?>
        <div class="card"><div class="card-body">
            <p class="text-muted"><?php echo Text::_('COM_DHARMASITEMAP_SETTINGS_GENERAL_DESC'); ?></p>
            <?php echo $this->form->renderFieldset('general'); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'dharmaSitemapSettingsTabs', 'sources', Text::_('COM_DHARMASITEMAP_CONFIG_SOURCES')); ?>
        <div class="card"><div class="card-body">
            <p class="text-muted"><?php echo Text::_('COM_DHARMASITEMAP_SETTINGS_SOURCES_DESC'); ?></p>
            <?php echo $this->form->renderFieldset('sources'); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'dharmaSitemapSettingsTabs', 'exclusions', Text::_('COM_DHARMASITEMAP_CONFIG_EXCLUSIONS')); ?>
        <div class="card"><div class="card-body">
            <p class="text-muted"><?php echo Text::_('COM_DHARMASITEMAP_SETTINGS_EXCLUSIONS_DESC'); ?></p>
            <?php echo $this->form->renderFieldset('exclusions'); ?>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php echo HTMLHelper::_('uitab.addTab', 'dharmaSitemapSettingsTabs', 'automation', Text::_('COM_DHARMASITEMAP_TAB_AUTOMATION')); ?>
        <div class="card"><div class="card-body">
            <h2 class="h4"><?php echo Text::_('COM_DHARMASITEMAP_TAB_AUTOMATION'); ?></h2>
            <p><?php echo Text::_('COM_DHARMASITEMAP_AUTOMATION_DESC'); ?></p>
            <p><a class="btn btn-outline-primary" href="<?php echo $escape($schedulerUrl); ?>"><?php echo Text::_('COM_DHARMASITEMAP_SCHEDULER'); ?></a></p>
            <h3 class="h5"><?php echo Text::_('COM_DHARMASITEMAP_CLI_TITLE'); ?></h3>
            <p><?php echo Text::_('COM_DHARMASITEMAP_CLI_DESC'); ?></p>
            <pre><code>php cli/joomla.php dharmasitemap:build</code></pre>
            <p><a class="btn btn-outline-secondary" href="<?php echo $escape($pluginsUrl); ?>"><?php echo Text::_('COM_DHARMASITEMAP_PLUGINS'); ?></a></p>
        </div></div>
        <?php echo HTMLHelper::_('uitab.endTab'); ?>

        <?php if ($canManagePermissions) : ?>
            <?php echo HTMLHelper::_('uitab.addTab', 'dharmaSitemapSettingsTabs', 'permissions', Text::_('COM_DHARMASITEMAP_TAB_PERMISSIONS')); ?>
            <div class="card"><div class="card-body">
                <p class="text-muted"><?php echo Text::_('COM_DHARMASITEMAP_PERMISSIONS_DESC'); ?></p>
                <?php echo $this->form->renderFieldset('permissions', ['hiddenLabel' => true, 'class' => 'revert-controls']); ?>
            </div></div>
            <?php echo HTMLHelper::_('uitab.endTab'); ?>
        <?php endif; ?>

        <?php echo HTMLHelper::_('uitab.endTabSet'); ?>
        <div class="mt-3 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><?php echo Text::_('JSAVE'); ?></button>
        </div>
        <?php echo $this->form->renderControlFields(); ?>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
    <?php else : ?>
        <div class="alert alert-warning"><?php echo Text::_('COM_DHARMASITEMAP_SETTINGS_ACCESS_DENIED'); ?></div>
    <?php endif; ?>
    <?php echo HTMLHelper::_('uitab.endTab'); ?>

    <?php echo HTMLHelper::_('uitab.endTabSet'); ?>
</div>
