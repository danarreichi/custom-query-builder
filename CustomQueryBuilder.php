<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * CustomQueryBuilder loader
 *
 * This file is the entry point CodeIgniter's library autoloader resolves
 * (filename must match the `CustomQueryBuilder` class name). The actual
 * implementation lives in CustomQueryBuilder/*.php, split by trait/class
 * for easier debugging. Load order matters: traits must be required before
 * the classes that `use` them.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */

require_once BASEPATH . 'database/DB_query_builder.php';

require_once __DIR__ . '/CustomQueryBuilder/QueryValidationTrait.php';
require_once __DIR__ . '/CustomQueryBuilder/CustomQueryBuilderResult.php';
require_once __DIR__ . '/CustomQueryBuilder/RelationAggregateTrait.php';
require_once __DIR__ . '/CustomQueryBuilder/NestedQueryBuilder.php';
require_once __DIR__ . '/CustomQueryBuilder/CustomQueryBuilder.php';
