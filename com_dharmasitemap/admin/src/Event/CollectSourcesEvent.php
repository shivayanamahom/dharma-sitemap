<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaSitemap\Administrator\Event;

use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Event\Result\ResultAware;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Event\Result\ResultTypeArrayAware;

\defined('_JEXEC') or die;

final class CollectSourcesEvent extends AbstractEvent implements ResultAwareInterface
{
    use ResultAware;
    use ResultTypeArrayAware;
}
