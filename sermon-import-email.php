<?php

if (!defined('ABSPATH')) {
    exit;
}

function sermon_import_send_summary_email($summary) {
    $recipient = get_option('admin_email');
    if (!is_string($recipient) || !is_email($recipient)) {
        return new WP_Error(
            'sermon_import_invalid_email_recipient',
            'The WordPress admin email address is missing or invalid.'
        );
    }

    $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
    $subject = sprintf('[%s] Sermon import summary (%s)', $site_name, $summary['start_at']);
    $action = $summary['dry_run'] ? 'Would import' : 'Imported';
    $body = array(
        'SERMON IMPORT SUMMARY',
        '',
    );
    $summary_rows = array(
        'Start at' => $summary['start_at'],
        'Month folders scanned' => $summary['month_folders'],
        'MP3 files scanned' => $summary['total_scanned'],
        'Target post status' => $summary['post_status'],
        $action => count($summary['imported']),
        'Skipped files requiring attention' => count($summary['skipped']),
        'Already existed (intentionally skipped)' => $summary['intentionally_skipped_count'],
        'Errors' => count($summary['errors']),
    );
    foreach ($summary_rows as $label => $value) {
        $body[] = $label . ': ' . $value;
    }
    $body[] = '';
    $body[] = $action . ' sermons';

    if ($summary['imported']) {
        $item_number = 1;
        foreach ($summary['imported'] as $item) {
            $body[] = '';
            $body[] = $item_number . '. ' . $item['file'];
            $body[] = '   Title: ' . (isset($item['title']) ? $item['title'] : '');
            $body[] = '   Date: ' . (isset($item['sermon_date']) ? $item['sermon_date'] : '');
            $body[] = '   Status: ' . (isset($item['status']) ? $item['status'] : $summary['post_status']);
            $body[] = '   Issues: ' . (!empty($item['issues']) ? implode(', ', $item['issues']) : 'None');
            $item_number++;
        }
    } else {
        $body[] = 'None.';
    }

    $body[] = '';
    $body[] = 'SKIPPED FILES REQUIRING ATTENTION';
    if ($summary['skipped']) {
        $item_number = 1;
        foreach ($summary['skipped'] as $item) {
            $body[] = $item_number . '. ' . $item['file'];
            $body[] = '   Reason: ' . $item['reason'];
            $item_number++;
        }
    } else {
        $body[] = 'None.';
    }

    $body[] = '';
    $body[] = 'ERRORS';
    if ($summary['errors']) {
        $item_number = 1;
        foreach ($summary['errors'] as $item) {
            $body[] = $item_number . '. ' . $item['file'];
            $body[] = '   Error: ' . $item['reason'];
            $item_number++;
        }
    } else {
        $body[] = 'None.';
    }

    if ($summary['dry_run']) {
        $body[] = '';
        $body[] = 'Dry run: No sermons or metadata were created or changed.';
    }

    $headers = array('Content-Type: text/plain; charset=UTF-8');
    if (!wp_mail($recipient, $subject, implode("\n", $body), $headers)) {
        return new WP_Error(
            'sermon_import_email_failed',
            'WordPress could not send the summary email.'
        );
    }

    return $recipient;
}
