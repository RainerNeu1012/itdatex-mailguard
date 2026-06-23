<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Bewusst defensiv: weder Customer-Daten noch Settings beim Uninstall loeschen.
// Wer alles wegwerfen will, droppt 'wp_mg_customers' und 'itdatex_mailguard_*' manuell.
