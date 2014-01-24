<?php
/**
 * Configuration File
 *
 * This is a PHP file that sets up variables specific for a template.
 * It can also be used to run PHP code for a template.
 *
 * @version 2.6
 *
 */

// The name of the template, as it will appear in the control panel
$GLOBALS['TPL_CFG']['Name']	= 'Chocolate';

// The version of the template, as it will appear in the control panel
$GLOBALS['TPL_CFG']['Version'] = '1.9';

// The recommended width of a logo when it's displayed in the header of this template
$GLOBALS['TPL_CFG']['LogoWidth'] = 425;

// The recommended height of a logo when it's displayed in the header of this template
$GLOBALS['TPL_CFG']['LogoHeight'] = 100;

// The maximum width an image uploaded in DevEdit can be before it's resized.
// This is used to make sure product images uploaded in DevEdit don't stretch
// out past the main content area of the template
$GLOBALS['TPL_CFG']['MaxImageWidth'] = 580;

// The maximum number of products that can be compared side-by-side without
// messing up the layout of the template
$GLOBALS['TPL_CFG']['MaxComparisonProducts'] = 4;

// The "Powered by" line that this template should use.
// These lines can be adjusted in /includes/whitelabel.php
$GLOBALS['TPL_CFG']['PoweredBy'] = 0;

