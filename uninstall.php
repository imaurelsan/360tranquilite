<?php
/**
 * Désinstallation propre du plugin 360 Tranquillité.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once __DIR__ . '/includes/class-core.php';

TRQ_Core::uninstall();