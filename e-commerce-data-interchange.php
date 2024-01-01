<?php
/**
 * Plugin Name:          E-Commerce Data Interchange
 * Plugin URI:           https://edi.byteperfect.dev/
 * Description:          The plugin provides data interchange between the WooCommerce plugin and 1С.
 * Version:              2.0.0
 * Author:               Aleksandr Levashov <me@webcodist.com>
 * Author URI:           https://webcodist.com/
 * Requires at least:    5.7
 * Requires PHP:         7.4
 * WC requires at least: 3.6.0
 * WC tested up to:      7.3.0
 * License:              GPLv3
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          edi
 * Domain Path:          /languages/
 *
 * @package BytePerfect\EDI
 */

/**
 * "EDI - Data interchange between WooCommerce and 1С" is free software:
 * you can redistribute it and/or modify it under the terms of the
 * GNU General Public License as published by the Free Software Foundation,
 * version 3.
 *
 * "EDI - Data interchange between WooCommerce and 1С" is distributed in the hope
 * that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with "EDI - Data interchange between WooCommerce and 1С".
 * If not, see https://www.gnu.org/licenses/gpl-3.0.html.
 */

declare( strict_types=1 );

namespace BytePerfect\EDI;

defined( 'ABSPATH' ) || exit;

define( 'EDI_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

$GLOBALS['EDI'] = new EDI();
