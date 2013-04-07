<?php
/**
 * @package AkeebaBackup
 * @copyright Copyright (c)2009-2013 Nicholas K. Dionysopoulos
 * @license GNU General Public License version 3, or later
 * @since 1.3
 */

// Protect from unauthorized access
defined('_JEXEC') or die();

/**
 * The Control Panel model
 *
 */
class DocimportModelCpanels extends FOFModel
{
	/** @var string The root of the database installation files */
	private $dbFilesRoot = '/components/com_docimport/sql/';

	/** @var array If any of these tables is missing we run the install SQL file and ignore the $dbChecks array */
	private $dbBaseCheck = array(
		'tables' => array(
			'docimport_articles', 'docimport_categories',
			'docimport_urls',
		),
		'file' => 'install/mysql/install.sql'
	);

	/** @var array Database update checks */
	private $dbChecks = array(
		/**
		array(
			'table' => 'ak_something',
			'field' => 'some_field',
			'files' =>array(
				'updates/mysql/x.y.z-2013-01-01.sql',
			)
		),
		**/
	);

	/**
	 * Update the cached live site's URL for the front-end backup feature (altbackup.php)
	 * and the detected Joomla! libraries path
	 */
	public function updateMagicParameters()
	{
		// Fetch component parameters
		$component = JComponentHelper::getComponent( 'com_docimport' );
		if(is_object($component->params) && ($component->params instanceof JRegistry)) {
			$params = $component->params;
		} else {
			$params = new JParameter($component->params);
		}

		// Update magic parameters
		$params->set( 'siteurl', str_replace('/administrator','',JURI::base()) );

		// Save parameters
		$db = JFactory::getDBO();
		$data = $params->toString();
		$sql = $db->getQuery(true)
			->update($db->qn('#__extensions'))
			->set($db->qn('params').' = '.$db->q($data))
			->where($db->qn('element').' = '.$db->q('com_docimport'))
			->where($db->qn('type').' = '.$db->q('component'));
		$db->setQuery($sql);
		$db->execute();

		return $this;
	}

	/**
	 * Checks the database for missing / outdated tables using the $dbChecks
	 * data and runs the appropriate SQL scripts if necessary.
	 *
	 * @return AkeebasubsModelCpanels
	 */
	public function checkAndFixDatabase()
	{
		$db = $this->getDbo();

		// Initialise
		$tableFields = array();
		$sqlFiles = array();

		// Get a listing of database tables known to Joomla!
		$allTables = $db->getTableList();
		$dbprefix = JFactory::getConfig()->get('dbprefix', '');

		// Perform the base check. If any of these tables is missing we have to run the installation SQL file
		if(!empty($this->dbBaseCheck)) {
			foreach($this->dbBaseCheck['tables'] as $table)
			{
				$tableName = $dbprefix . $table;
				$check = in_array($tableName, $allTables);
				if (!$check) break;
			}

			if (!$check)
			{
				$sqlFiles[] = JPATH_ADMINISTRATOR . $this->dbFilesRoot . $this->dbBaseCheck['file'];
			}
		}

		// If the base check was successful and we have further database checks run them
		if (empty($sqlFiles) && !empty($this->dbChecks)) foreach($this->dbChecks as $dbCheck)
		{
			// Always check that the table exists
			$tableName = $dbprefix . $dbCheck['table'];
			$check = in_array($tableName, $allTables);

			// If the table exists and we have a field, check that the field exists too
			if (!empty($dbCheck['field']) && $check)
			{
				if (!array_key_exists($tableName, $tableFields))
				{
					$tableFields[$tableName] = $db->getTableColumns('#__' . $dbCheck['table'], true);
				}

				if (is_array($tableFields[$tableName]))
				{
					$check = array_key_exists($dbCheck['field'], $tableFields[$tableName]);
				}
				else
				{
					$check = false;
				}
			}

			// Something's missing. Add the file to the list of SQL files to run
			if (!$check)
			{
				foreach ($dbCheck['files'] as $file)
				{
					$sqlFiles[] = JPATH_ADMINISTRATOR . $this->dbFilesRoot . $file;
				}
			}
		}

		// If we have SQL files to run, well, RUN THEM!
		if (!empty($sqlFiles))
		{
			JLoader::import('joomla.filesystem.file');
			foreach($sqlFiles as $file)
			{
				$sql = JFile::read($file);
				if($sql) {
					$commands = explode(';', $sql);
					foreach($commands as $query) {
						$db->setQuery($query);
						$db->execute();
					}
				}
			}
		}

		return $this;
	}
}