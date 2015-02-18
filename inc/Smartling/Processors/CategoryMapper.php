<?php

namespace Smartling\Processors;

/**
 * Class CategoryMapper
 *
 * @package Smartling\Processors
 */
class CategoryMapper extends MapperAbstract {

	/**
	 * Constructor
	 */
	function __construct () {
		$this->setFields(
			array (
				'name',
				'description'
			)
		);
	}
}