<?php

/**
 * @defgroup plugins_importexport_portico
 */
 
/**
 * @file plugins/importexport/portico/index.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University Library
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_importexport_portico
 * @brief Wrapper for portico XML export plugin.
 *
 */

require_once('PorticoExportPlugin.inc.php');

return new PorticoExportPlugin();
