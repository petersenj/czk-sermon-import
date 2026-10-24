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
    $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#222;line-height:1.5;">';
    $html .= '<h2>Sermon import summary</h2><table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;">';
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
        $html .= '<tr><th align="left">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
    }
    $html .= '</table>';

    $html .= '<h3>' . esc_html($action) . ' sermons</h3>';
    if ($summary['imported']) {
        $html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;">';
        $html .= '<thead><tr><th align="left">File</th><th align="left">Title</th><th align="left">Date</th><th align="left">Status</th><th align="left">Issues</th></tr></thead><tbody>';
        foreach ($summary['imported'] as $item) {
            $html .= '<tr><td>' . esc_html($item['file']) . '</td>';
            $html .= '<td>' . esc_html(isset($item['title']) ? $item['title'] : '') . '</td>';
            $html .= '<td>' . esc_html(isset($item['sermon_date']) ? $item['sermon_date'] : '') . '</td>';
            $html .= '<td>' . esc_html(isset($item['status']) ? $item['status'] : $summary['post_status']) . '</td>';
            $html .= '<td>' . esc_html(!empty($item['issues']) ? implode(', ', $item['issues']) : 'None') . '</td></tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p>None.</p>';
    }

    $html .= '<h3>Skipped files requiring attention</h3>';
    if ($summary['skipped']) {
        $html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;">';
        $html .= '<thead><tr><th align="left">File</th><th align="left">Reason</th></tr></thead><tbody>';
        foreach ($summary['skipped'] as $item) {
            $html .= '<tr><td>' . esc_html($item['file']) . '</td><td>' . esc_html($item['reason']) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p>None.</p>';
    }

    $html .= '<h3>Errors</h3>';
    if ($summary['errors']) {
        $html .= '<table cellpadding="6" cellspacing="0" border="1" style="border-collapse:collapse;width:100%;">';
        $html .= '<thead><tr><th align="left">File</th><th align="left">Error</th></tr></thead><tbody>';
        foreach ($summary['errors'] as $item) {
            $html .= '<tr><td>' . esc_html($item['file']) . '</td><td>' . esc_html($item['reason']) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    } else {
        $html .= '<p>None.</p>';
    }

    if ($summary['dry_run']) {
        $html .= '<p><strong>Dry run:</strong> No sermons or metadata were created or changed.</p>';
    }
    $html .= '</body></html>';

    $headers = array('Content-Type: text/html; charset=UTF-8');
    if (!wp_mail($recipient, $subject, $html, $headers)) {
        return new WP_Error(
            'sermon_import_email_failed',
            'WordPress could not send the summary email.'
        );
    }

    return $recipient;
}
