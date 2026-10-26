<?php
/**
 * Plugin Name: Sermon Import Scheduler
 * Description: Runs the sermon importer weekly on Monday morning.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

define('SERMON_IMPORT_SCHEDULE_HOOK', 'sermon_import_scheduled_run');
define('SERMON_IMPORT_TEST_SCHEDULE_HOOK', 'sermon_import_test_run');
define('SERMON_IMPORT_TEST_OPTION', 'sermon_import_test_run_scheduled');

function sermon_import_add_weekly_schedule($schedules) {
    $schedules['sermon_import_weekly'] = array(
        'interval' => WEEK_IN_SECONDS,
        'display' => 'Once Weekly',
    );
    return $schedules;
}

add_filter('cron_schedules', 'sermon_import_add_weekly_schedule');

function sermon_import_schedule_next_run() {
    if (wp_next_scheduled(SERMON_IMPORT_SCHEDULE_HOOK)) {
        return;
    }

    $now = new DateTimeImmutable('now', wp_timezone());
    $next_run = $now->modify('monday this week')->setTime(3, 0, 0);
    if ($next_run <= $now) {
        $next_run = $next_run->modify('+1 week');
    }

    if (!wp_schedule_event($next_run->getTimestamp(), 'sermon_import_weekly', SERMON_IMPORT_SCHEDULE_HOOK)) {
        error_log('Sermon importer: could not schedule the weekly import.');
    }
}

register_activation_hook(__FILE__, 'sermon_import_schedule_next_run');

function sermon_import_clear_scheduled_run() {
    wp_clear_scheduled_hook(SERMON_IMPORT_SCHEDULE_HOOK);
    sermon_import_clear_test_run();
}

function sermon_import_clear_test_run() {
    wp_clear_scheduled_hook(SERMON_IMPORT_TEST_SCHEDULE_HOOK);
    delete_option(SERMON_IMPORT_TEST_OPTION);
}

register_deactivation_hook(__FILE__, 'sermon_import_clear_scheduled_run');
add_action('init', 'sermon_import_schedule_next_run');
add_action('init', 'sermon_import_clear_test_run');
add_action(SERMON_IMPORT_SCHEDULE_HOOK, 'sermon_import_run_scheduled_import');

function sermon_import_run_scheduled_import() {
    $secret_key = defined('SERMON_IMPORT_SECRET')
        ? SERMON_IMPORT_SECRET
        : getenv('SERMON_IMPORT_SECRET');
    if (!is_string($secret_key) || $secret_key === '') {
        error_log('Sermon importer: SERMON_IMPORT_SECRET is not configured; scheduled import was not run.');
        return;
    }

    $start_at = new DateTimeImmutable('first day of this month', wp_timezone());
    $start_at = $start_at->modify('-1 month')->format('Y-m');
    $url = add_query_arg(
        array(
            'start-at' => $start_at,
            'dryrun' => '0',
            'publish' => '1',
            'email' => '1',
        ),
        plugins_url('import-sermons.php', __FILE__)
    );

    $response = wp_remote_get($url, array(
        'headers' => array(
            'X-Sermon-Import-Key' => $secret_key,
        ),
        'timeout' => 300,
        'redirection' => 0,
    ));
    if (is_wp_error($response)) {
        error_log('Sermon importer: scheduled request failed (' . $response->get_error_code() . ').');
        return;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code < 200 || $status_code >= 300) {
        error_log('Sermon importer: scheduled request returned HTTP ' . $status_code . '.');
    }
}
