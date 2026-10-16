<?php
declare(strict_types=1);

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
$settingsUrl = Route::_('index.php?option=com_config&view=component&component=com_dharmasitemap');
$schedulerUrl = Route::_('index.php?option=com_scheduler&view=tasks');
?>
<div class="container-fluid">
    <h1><?php echo Text::_('COM_DHARMASITEMAP_TITLE'); ?></h1>
    <p class="text-muted"><?php echo Text::_('COM_DHARMASITEMAP_INTRO'); ?></p>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h4"><?php echo Text::_('COM_DHARMASITEMAP_GENERATION'); ?></h2>
            <p><?php echo Text::_('COM_DHARMASITEMAP_GENERATION_DESC'); ?></p>
            <form action="<?php echo Route::_('index.php?option=com_dharmasitemap&task=generate.build'); ?>" method="post">
                <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_DHARMASITEMAP_BUILD_NOW'); ?></button>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        </div>
    </div>
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
            <?php else : ?>
                <p class="mb-0"><?php echo Text::_('COM_DHARMASITEMAP_NO_BUILD'); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="card mt-3">
        <div class="card-body d-flex flex-wrap gap-2">
            <a class="btn btn-outline-primary" href="<?php echo $escape($settingsUrl); ?>"><?php echo Text::_('COM_DHARMASITEMAP_SETTINGS'); ?></a>
            <a class="btn btn-outline-secondary" href="<?php echo $escape($schedulerUrl); ?>"><?php echo Text::_('COM_DHARMASITEMAP_SCHEDULER'); ?></a>
            <a class="btn btn-link" href="<?php echo $escape($endpoint); ?>" target="_blank" rel="noopener"><?php echo Text::_('COM_DHARMASITEMAP_XML_ENDPOINT'); ?></a>
        </div>
        <div class="card-footer text-muted small"><?php echo Text::_('COM_DHARMASITEMAP_STATIC_FILE_NOTE'); ?></div>
    </div>
</div>
