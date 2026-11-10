<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\View\Control;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Table\Asset;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

final class HtmlView extends BaseHtmlView
{
    public ?object $activeBuild = null;
    public ?Form $form = null;
    /** @var array{sources: list<array<string, mixed>>, excluded: array<string, int>, llms: array<string, mixed>} */
    public array $report = ['sources' => [], 'excluded' => [], 'llms' => []];

    public function display($tpl = null): void
    {
        $container = Factory::getContainer();
        $db = $container->get(DatabaseInterface::class);
        $this->activeBuild = (new SitemapBuilder($db))->getActiveBuild();
        $this->report = SitemapBuilder::readReport($this->activeBuild);

        Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_dharmasitemap');
        $this->form = Form::getInstance(
            'com_dharmasitemap.settings',
            'config',
            ['control' => 'jform', 'load_data' => false],
            false,
            '/config'
        );

        $data = ComponentHelper::getParams('com_dharmasitemap')->toArray();
        if (Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_dharmasitemap')) {
            $asset = new Asset($db);
            if ($asset->loadByName('com_dharmasitemap')) {
                $data['rules'] = $asset->rules;
            }
        }
        $this->form->bind($data);

        $component = ComponentHelper::getComponent('com_dharmasitemap');
        $this->form->addControlField('task', 'component.save');
        $this->form->addControlField('id', (string) $component->id);
        $this->form->addControlField('component', 'com_dharmasitemap');
        $this->form->addControlField('return', base64_encode('index.php?option=com_dharmasitemap#settings'));

        $this->addToolbar();

        parent::display($tpl);
    }

    /** Штатная шапка Joomla: заголовок с иконкой и кнопка «Параметры» (она сама скрывается без прав). */
    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_DHARMASITEMAP_TITLE'), 'sitemap');

        $user = Factory::getApplication()->getIdentity();

        // «Сохранить» — в штатной панели Joomla (как у всех компонентов), а не внизу формы. Форма настроек называется adminForm,
        // поэтому штатная кнопка отправляет именно её; на вкладке «Обзор» кнопка скрывается (там сохранять нечего).
        if ($user->authorise('core.options', 'com_dharmasitemap') || $user->authorise('core.admin', 'com_dharmasitemap')) {
            // Одна кнопка «Сохранить»: остаётся на этой странице (адрес возврата задан в форме). «Применить» здесь не нужно:
            // оно отправило бы на штатную страницу параметров com_config.
            Factory::getApplication()->getDocument()->getToolbar()->standardButton('save', 'JAPPLY', 'component.save')->icon('icon-save')->buttonClass('btn btn-success');
            Factory::getApplication()->getDocument()->getWebAssetManager()->addInlineScript(<<<'JS'
(() => {
    const sync = () => {
        const button = document.getElementById('toolbar-save');
        const active = document.querySelector('#dharmaSitemapTabs > joomla-tab-element[active]');
        if (button && active) button.hidden = active.id !== 'settings';
    };
    document.addEventListener('DOMContentLoaded', sync);
    document.addEventListener('joomla.tab.show', () => setTimeout(sync, 0));
    document.addEventListener('joomla.tab.shown', () => setTimeout(sync, 0));
    window.addEventListener('load', () => setTimeout(sync, 150));
})();
JS);
        }

        ToolbarHelper::preferences('com_dharmasitemap');
    }
}
