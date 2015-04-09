<?php
/**
 * @name      OpenImporter
 * @copyright OpenImporter contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 2.0 Alpha
 *
 * This file contains code based on:
 *
 * Simple Machines Forum (SMF)
 * copyright:	2011 Simple Machines (http://www.simplemachines.org)
 * license:	BSD, See included LICENSE.TXT for terms and conditions.
 */

namespace OpenImporter\Core;

use OpenImporter\Core\Strings;

/**
 * Object Importer creates the main XML object.
 * It detects and initializes the script to run.
 *
 */
class XmlProcessor
{
	/**
	 * This is the database object of the destination system.
	 * @var object
	 */
	protected $db;

	/**
	 * This is the database object of the source system.
	 * @var object
	 */
	protected $source_db;

	/**
	 * Contains any kind of configuration.
	 * @var object
	 */
	public $config;

	/**
	 * The template
	 * @var object
	 */
	public $template;

	/**
	 * The xml object containing the settings.
	 * Required (for now) to convert IPs (v4/6)
	 * @var object
	 */
	public $xml;

	/**
	 * The step running in this very moment.
	 * @var object
	 */
	public $current_step;

	/**
	 * Holds all the methods required to perform the conversion.
	 * @var object
	 */
	public $step1_importer;

	/**
	 * The object defining the intermediate array between source and destination.
	 * @var object
	 */
	public $skeleton;

	/**
	 * initialize the main Importer object
	 */
	public function __construct($db, $source_db, $config, $template, $xml)
	{
		$this->db = $db;
		$this->source_db = $source_db;
		$this->config = $config;
		$this->template = $template;
		$this->xml = $xml;
	}

	public function setImporter($step1_importer)
	{
		$this->step1_importer = $step1_importer;
	}

	public function setSkeleton($skeleton)
	{
		$this->skeleton = $skeleton;
	}

	public function processSource($step, &$substep, &$do_steps)
	{
		$this->current_step = $step;
		$id = ucFirst($this->current_step['id']);

		// @todo do detection on destination side (e.g. friendly urls)
		$table_test = $this->updateStatus($substep, $do_steps);

		// do we need to skip this step?
		if ($table_test === false || !in_array($substep, $do_steps))
			return;

		// pre sql queries first!!
		$this->doPresqlStep($id, $substep);

		$special_table = $this->getStepTable($id);
		$from_code = $this->doCode();

		// Codeblock? Then no query.
		if (!empty($from_code))
		{
			// @todo consider delegate the complete definition to some code in the source importer
			$this->completed = true;
			return $from_code;
		}
		else
		{
			// sql block?
			// @todo $_GET
			if ($substep >= $_GET['substep'] && isset($this->current_step->query))
			{
				return $this->doSql($substep, $special_table);
			}
		}
	}

	public function getStepTable($id)
	{
		return strtr(trim((string) $this->step1_importer->callMethod('table' . $id)), array('{$to_prefix}' => $this->config->to_prefix));
	}

	public function processDestination($id, &$substep, $rows)
	{
		return $this->step1_importer->callMethod('preparse' . $id, $rows);
	}

	protected function doSql($substep, $special_table)
	{
		$current_data = rtrim(trim($this->fixParams((string) $this->current_step->query)), ';');
		$id = ucFirst($this->current_step['id']);

		$special_limit = isset($this->current_step->options->limit) ? $this->current_step->options->limit : 500;

		$special_result = $this->prepareSpecialResult($current_data, $special_limit);

		$newrow = array();

		if (isset($this->current_step->detect))
			$_SESSION['import_progress'] += $special_limit;

		while ($row = $this->source_db->fetch_assoc($special_result))
		{
			$newrow[] = $row;
		}

		$rows = $this->config->source->callMethod('preparse' . $id, $newrow);

		// @todo $_REQUEST
		$_REQUEST['start'] += $special_limit;

		$this->completed = $this->source_db->num_rows($special_result) > $special_limit;

		$this->source_db->free_result($special_result);

		return $rows;
	}

	public function stillRunning()
	{
		return !empty($this->completed);
	}

	protected function getPreparsecode()
	{
		if (!empty($this->current_step->preparsecode))
			return $this->fixParams((string) $this->current_step->preparsecode);
		else
			return null;
	}

	/**
	 * used to replace {$from_prefix} and {$to_prefix} with its real values.
	 *
	 * @param string string in which parameters are replaced
	 * @return string
	 */
	protected function fixParams($string)
	{
		if (isset($_SESSION['import_parameters']))
		{
			foreach ($_SESSION['import_parameters'] as $param)
			{
				foreach ($param as $key => $value)
					$string = strtr($string, array('{$' . $key . '}' => $value));
			}
		}
		$string = strtr($string, array('{$from_prefix}' => $this->config->from_prefix, '{$to_prefix}' => $this->config->to_prefix));

		return $string;
	}

	/**
	 * Counts the records in a table of the source database
	 *
	 * @param string the table name
	 * @return int the number of records in the table
	 */
	public function getCurrent($table)
	{
		$count = $this->fixParams($table);
		$request = $this->source_db->query("
			SELECT COUNT(*)
			FROM $count", true);

		$current = 0;
		if (!empty($request))
		{
			list ($current) = $this->source_db->fetch_row($request);
			$this->source_db->free_result($request);
		}

		return $current;
	}

	/**
	 * @todo extract the detection step
	 */
	protected function updateStatus(&$substep, &$do_steps)
	{
		$table_test = true;

		// Increase the substep slightly...
		$this->config->progress->pastTime(++$substep);

		$_SESSION['import_steps'][$substep]['title'] = (string) $this->current_step->title;
		if (!isset($_SESSION['import_steps'][$substep]['status']))
			$_SESSION['import_steps'][$substep]['status'] = 0;

		if ($_SESSION['import_steps'][$substep]['status'] == 0)
		{
			if (!in_array($substep, $do_steps))
			{
				$_SESSION['import_steps'][$substep]['status'] = 2;
				$_SESSION['import_steps'][$substep]['presql'] = true;
			}
			// Detect the table, then count rows..
			elseif ($this->current_step->detect)
			{
				$table_test = $this->detect((string) $this->current_step->detect);

				if ($table_test === false)
				{
					$_SESSION['import_steps'][$substep]['status'] = 3;
					$_SESSION['import_steps'][$substep]['presql'] = true;
				}
			}
		}
		else
			$table_test = false;

		$this->template->status($substep, $_SESSION['import_steps'][$substep]['status'], $_SESSION['import_steps'][$substep]['title']);

		return $table_test;
	}

	protected function doPresqlStep($id, $substep)
	{
		if (isset($_SESSION['import_steps'][$substep]['presql']))
			return;

		$this->step1_importer->callMethod('before' . ucFirst($id));
		$this->config->source->callMethod('before' . ucFirst($id));

		// don't do this twice..
		$_SESSION['import_steps'][$substep]['presql'] = true;
	}

	protected function doCode()
	{
		$id = ucFirst($this->current_step['id']);

		$rows = $this->config->source->callMethod('code' . $id);

		if (!empty($rows))
		{
			return $rows;
		}

		return false;
	}

	public function detect($table)
	{
		$table = $this->fixParams($table);
		$table = preg_replace('/^`[\w\d]*`\./i', '', $this->fixParams($table));

		$db_name_str = $this->config->source->getDbName();

		$result = $this->db->query("
			SHOW TABLES
			FROM `{$db_name_str}`
			LIKE '{$table}'");

		if (!($result instanceof \Doctrine\DBAL\Driver\Statement) || $this->db->num_rows($result) == 0)
			return false;
		else
			return true;
	}

	public function insertRows($rows, $special_table)
	{
		if (empty($rows) || empty($special_table))
			return;

		$insert_statement = $this->insertStatement($this->current_step->options);
		$ignore_slashes = $this->ignoreSlashes($this->current_step->options);

		foreach ($rows as $row)
		{
			if (empty($ignore_slashes))
				$row = Strings::addslashes_recursive($row);

			$this->db->insert($special_table, $row, $insert_statement);
		}
	}

	protected function shouldIgnore($options)
	{
		if (isset($options->ignore) && (bool) $options->ignore === false)
			return false;

		return isset($options->ignore) && !isset($options->replace);
	}

	protected function shouldReplace($options)
	{
		return isset($options->replace) && (bool) $options->replace === true;
	}

	protected function shoudNotAdd($options)
	{
		return isset($options->no_add) && (bool) $options->no_add === true;
	}

	protected function ignoreSlashes($options)
	{
		return isset($options->ignore_slashes) && (bool) $options->ignore_slashes === true;
	}

	protected function insertStatement($options)
	{
		if ($this->shouldIgnore($options))
			return 'ignore';
		elseif ($this->shouldReplace($options))
			return 'replace';
		else
			return 'insert';
	}

	protected function prepareSpecialResult($current_data, $special_limit)
	{
		// @todo $_REQUEST
		if (strpos($current_data, '%d') !== false)
			return $this->source_db->query(sprintf($current_data, $_REQUEST['start'], $_REQUEST['start'] + $special_limit - 1) . "\n" . 'LIMIT ' . $special_limit);
		else
			return $this->source_db->query($current_data . "\n" . 'LIMIT ' . $_REQUEST['start'] . ', ' . $special_limit);

	}
}