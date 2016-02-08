<?php
/**
 * @name      OpenImporter
 * @copyright OpenImporter contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0 Alpha
 */

namespace OpenImporter;

/**
 * Just a way to collect a bunch of stuff to be used to build a form.
 *
 * @property string title
 * @property string description
 * @property array submit
 *
 * @package OpenImporter
 */
class Form
{
	protected $data = array();

	protected $lng = null;

	/**
	 * The bare minimum required to have a form: an url to post to.
	 */
	public $action_url = '';

	/**
	 * Form constructor.
	 *
	 * @param $lng
	 */
	public function __construct($lng)
	{
		$this->lng = $lng;
	}

	/**
	 * Set a key => value pair
	 *
	 * @param mixed $key
	 * @param mixed $val
	 *
	 * @throws FormException
	 */
	public function __set($key, $val)
	{
		if ($key === 'options')
		{
			throw new FormException('Use Form::addOptions or Form::addField to set new fields');
		}

		$this->data[$key] = $val;
	}

	/**
	 * Fetch a value for a given key
	 *
	 * @param mixed $key
	 *
	 * @return null
	 */
	public function __get($key)
	{
		if (isset($this->data[$key]))
		{
			return $this->data[$key];
		}
		else
		{
			return null;
		}
	}

	/**
	 * Add an option to the form, like text input, or password input, etc
	 *
	 * @param array $field
	 */
	public function addOption($field)
	{
		switch ($field['type'])
		{
			case 'text':
			{
				$this->data['options'][] = array(
					'id' => $field['id'],
					'label' => $this->lng->get($field['label']),
					'value' => isset($field['default']) ? $field['default'] : '',
					'correct' => isset($field['correct']) ? $this->lng->get($field['correct']) : '',
					'validate' => !empty($field['validate']),
					'type' => 'text',
				);
				break;
			}
			case 'password':
			{
				$this->data['options'][] = array(
					'id' => $field['id'],
					'label' => $this->lng->get($field['label']),
					'correct' => $this->lng->get($field['correct']),
					'type' => 'password',
				);
				break;
			}
			case 'steps':
			{
				$this->data['options'][] = array(
					'id' => $field['id'],
					'label' => $this->lng->get($field['label']),
					'value' => $field['default'],
					'type' => 'steps',
				);
				break;
			}
			default:
			{
				$this->data['options'][] = array(
					'id' => $field['id'],
					'label' => $this->lng->get($field['label']),
					'value' => 1,
					'attributes' => $field['checked'] == 'checked' ? ' checked="checked"' : '',
					'type' => 'checkbox',
				);
			}
		}
	}

	/**
	 * Split up form areas with a break
	 */
	public function addSeparator()
	{
		$this->data['options'][] = array();
	}

	/**
	 * Add a new field to the form
	 *
	 * @param array|object $field
	 */
	public function addField($field)
	{
		if (is_object($field))
		{
			$this->addField($this->makeFieldArray($field));
		}
		else
		{
			$field['id'] = 'field' . $field['id'];

			$this->addOption($field);
		}
	}

	/**
	 * Convert a field object to an array
	 *
	 * @param object $field
	 *
	 * @return array
	 */
	public function makeFieldArray($field)
	{
		if ($field->attributes()->{'type'} == 'text')
		{
			return array(
				'id' => $field->attributes()->{'id'},
				'label' => $field->attributes()->{'label'},
				'default' => isset($field->attributes()->{'default'}) ? $field->attributes()->{'default'} : '',
				'type' => 'text',
			);
		}
		else
		{
			return array(
				'id' => $field->attributes()->{'id'},
				'label' => $field->attributes()->{'label'},
				'checked' => $field->attributes()->{'checked'},
				'type' => 'checkbox',
			);
		}
	}
}