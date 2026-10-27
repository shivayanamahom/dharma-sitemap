<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\View\Control;

use Dharma\Component\DharmaSitemap\Administrator\Service\SitemapBuilder;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Table\Asset;
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

        parent::display($tpl);
    }
}
