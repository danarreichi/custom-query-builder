<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * CustomQueryBuilder loader
 *
 * This file is manually required from system/database/DB.php (see the
 * installation guide). The actual implementation lives in libs/*.php,
 * split by trait/class for easier debugging. Load order matters: traits
 * must be required before the classes that `use` them.
 *
 * @package CustomQueryBuilder
 * @author  Danar Ardiwinanto
 * @version 1.0.0
 */

require_once BASEPATH . 'database/DB_query_builder.php';

require_once __DIR__ . '/libs/QueryValidationTrait.php';
require_once __DIR__ . '/libs/CustomQueryBuilderResult.php';
require_once __DIR__ . '/libs/RelationAggregateTrait.php';
require_once __DIR__ . '/libs/NestedQueryBuilder.php';
require_once __DIR__ . '/libs/CustomQueryBuilder.php';
