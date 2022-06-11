<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  (C) 2008 Open Source Matters, Inc. <https://www.joomla.org>
 * @license        GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Updater\Adapter;

\defined('JPATH_PLATFORM') or die;

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\CMS\Updater\UpdateAdapter;
use Joomla\CMS\Updater\Updater;
use Joomla\CMS\Version;
use Joomla\CMS\TUF\TufValidation;
use Joomla\Database\ParameterType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Extension class for updater
 *
 * @since  1.7.0
 */
class TufAdapter extends UpdateAdapter
{
	private $clientId = [
		'site'          => 0,
		'administrator' => 1,
		'installation'  => 2,
		'api'           => 3,
		'cli'           => 4
	];

	/**
	 * Finds an update.
	 *
	 * @param   array  $options  Update options.
	 *
	 * @return  array|boolean  Array containing the array of update sites and array of updates. False on failure
	 *
	 * @since   1.7.0
	 */
	public function findUpdate($options)
	{
		$updates = [];
		$targets = $this->getUpdateTargets($options);

		foreach ($targets as $target)
		{
			$updateTable = Table::getInstance('update');
			$updateTable->set('update_site_id', $options['update_site_id']);

			$updateTable->bind($target);

			$updates[] = $updateTable;
		}

		return array('update_sites' => array(), 'updates' => $updates);
	}

	public function getUpdateTargets($options)
	{
		$versions = array();
		$resolver = new OptionsResolver;

		try
		{
			$this->configureUpdateOptions($resolver);
			$keys = $resolver->getDefinedOptions();
		}
		catch (\Exception $e)
		{
		}

		// Get extension_id for TufValidation
		$db = $this->parent->getDbo();

		$query = $db->getQuery(true)
			->select($db->quoteName('extension_id'))
			->from($db->quoteName('#__update_sites_extensions'))
			->where($db->quoteName('update_site_id') . ' = :id')
			->bind(':id', $options['update_site_id'], ParameterType::INTEGER);
		$db->setQuery($query);

		try
		{
			$extension_id = $db->loadResult();
		}
		catch (\RuntimeException $e)
		{
			// Do nothing
		}

		$params = [
			'url_prefix'    => 'https://raw.githubusercontent.com',
			'metadata_path' => '/joomla/updates/test/repository/',
			'targets_path'  => '/targets/',
			'mirrors'       => []
		];

		$TufValidation = new TufValidation($extension_id, $params);
		$metaData      = $TufValidation->getValidUpdate();

		$metaData = json_decode($metaData);

		if (isset($metaData->signed->targets))
		{
			foreach ($metaData->signed->targets as $filename => $target)
			{
				$values = [];

				foreach ($keys as $key)
				{
					if (isset($target->custom->$key))
					{
						$values[$key] = $target->custom->$key;
					}
				}


				if (isset($values['client']) && is_string($values['client'])
					&& key_exists(strtolower($values['client']), $this->clientId))
				{
					$values['client'] = $this->clientId[strtolower($values['client'])];
				}

				if (isset($values['infourl']) && isset($values['infourl']->url))
				{
					$values['infourl'] = $values['infourl']->url;
				}

				try
				{
					$values = $resolver->resolve($values);
				}
				catch (\Exception $e)
				{
					continue;
				}

				$versions[$values['version']] = $values;
			}

			usort($versions, function ($a, $b) {
				return version_compare($a['version'], $b['version']);
			});

			// TODO ConstraintsCheck
		}

		return $versions;
	}

	/**
	 * Configures default values or pass arguments to params
	 *
	 * @param   OptionsResolver  $resolver  The OptionsResolver for the params
	 *
	 * @return void
	 */
	protected function configureUpdateOptions(OptionsResolver $resolver)
	{
		$resolver->setDefaults(
			[
				'version'             => "1",
				'name'                => null,
				'client'              => 1,
				'description'           => '',
				'element'             => '',
				'detailsurl'          => '',
				'data'          => '',
				'infourl'             => '',
				'type'                => null,
				'tags'                => new \StdClass,
				'targetplatform'      => new \StdClass,
				'supported_databases' => new \StdClass,
				'downloads'           => [],
				'php_minimum'         => null
			]
		)
			->setAllowedTypes('version', 'string')
			->setAllowedTypes('name', 'string')
			->setAllowedTypes('element', 'string')
			->setAllowedTypes('data', 'string')
			->setAllowedTypes('description', 'string')
			->setAllowedTypes('type', 'string')
			->setAllowedTypes('detailsurl', 'string')
			->setAllowedTypes('infourl', 'string')
			->setAllowedTypes('client', 'int')
			->setAllowedTypes('php_minimum', 'string')
			->setAllowedTypes('downloads', 'array')
			->setAllowedTypes('tags', 'object')
			->setAllowedTypes('targetplatform', 'object')
			->setAllowedTypes('supported_databases', 'object')
			->setAllowedTypes('targetplatform', 'object')
			->setRequired(['version']);
	}
}
