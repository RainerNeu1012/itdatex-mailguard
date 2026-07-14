<?php
/**
 * Plugin Name:       itdatex MailGuard
 * Plugin URI:        https://wp.itdatex.support/itdatex-mailguard/
 * Description:       Multi-Tenant Phishing-/Spam-/Newsletter-Schutz fuer die Endkunden des Site-Owners. Endkunden verbinden Outlook/Gmail per OAuth oder eigenes IMAP per Auto-Discovery, eingehende Mails werden automatisch gegen die antiphish-API (lokales LLM) gescannt, Newsletter pro Sender abmeldbar.
 * Version:           0.26.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            !tdatex
 * Author URI:        https://wp.itdatex.support/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       itdatex-mailguard
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'ITDATEX_MAILGUARD_VERSION', '0.26.0' );
define( 'ITDATEX_MAILGUARD_FILE',    __FILE__ );
define( 'ITDATEX_MAILGUARD_DIR',     plugin_dir_path( __FILE__ ) );
define( 'ITDATEX_MAILGUARD_URL',     plugin_dir_url( __FILE__ ) );

require_once ITDATEX_MAILGUARD_DIR . 'vendor/autoload.php';

\Itdatex\Mailguard\Plugin::boot();

register_activation_hook(   __FILE__, [ \Itdatex\Mailguard\Installer::class, 'activate'   ] );
register_deactivation_hook( __FILE__, [ \Itdatex\Mailguard\Installer::class, 'deactivate' ] );
