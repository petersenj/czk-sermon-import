<?php
// Scans uploads/sermons/PredigtDerWoche/<YEAR>/<MONTH> from start-at through
// the current month. Call with ?key=SECRET&start-at=YYYY-MM.
// Add &dryrun=1 to preview without creating or changing sermons.

$uploads_subdir = 'sermons/PredigtDerWoche';
$post_type = 'wpfc_sermon';
$default_post_status = 'draft';
$default_author = 1;
$post_time = '10:30:00';
$minimum_start_at = '2020-01';
$dry_run_value = isset($_GET['dryrun']) && is_scalar($_GET['dryrun'])
    ? strtolower((string) $_GET['dryrun'])
    : '';
$dry_run = in_array($dry_run_value, array('1', 'true', 'yes'), true);
$debug_value = isset($_GET['debug']) && is_scalar($_GET['debug'])
    ? strtolower((string) $_GET['debug'])
    : '';
$debug = in_array($debug_value, array('1', 'true', 'yes'), true);

header('Content-Type: text/plain; charset=utf-8');

$start_at = isset($_GET['start-at']) ? trim((string) $_GET['start-at']) : '';
if (!preg_match('/^\d{4}-\d{2}$/', $start_at)) {
    http_response_code(400);
    echo 'Missing or invalid start-at parameter. Provide ?start-at=YYYY-MM (e.g. '. $minimum_start_at . ').';
    exit;
}

$start_dt = DateTime::createFromFormat('!Y-m', $start_at);
$date_errors = DateTime::getLastErrors();
if (
    !$start_dt ||
    ($date_errors !== false && ($date_errors['warning_count'] || $date_errors['error_count'])) ||
    $start_dt->format('Y-m') !== $start_at
) {
    http_response_code(400);
    echo 'Invalid start-at month.';
    exit;
}

$min_dt = DateTime::createFromFormat('!Y-m', $minimum_start_at);
if ($start_dt < $min_dt) {
    http_response_code(400);
    echo 'Invalid start-at: date must be ' . $minimum_start_at . ' or later.';
    exit;
}

// Locate and load WordPress.
$wp_root = __DIR__;
while (!file_exists($wp_root . '/wp-load.php')) {
    $parent = dirname($wp_root);
    if ($parent === $wp_root) {
        http_response_code(500);
        echo 'Cannot locate WordPress bootstrap (wp-load.php). Place this file inside the WordPress tree.';
        exit;
    }
    $wp_root = $parent;
}
require_once $wp_root . '/wp-load.php';

// Read the secret after WordPress has loaded wp-config.php.
$secret_key = defined('SERMON_IMPORT_SECRET')
    ? SERMON_IMPORT_SECRET
    : getenv('SERMON_IMPORT_SECRET');
if (!is_string($secret_key) || $secret_key === '') {
    http_response_code(500);
    echo 'Sermon importer is not configured.';
    exit;
}

$provided_key = isset($_GET['key']) ? (string) $_GET['key'] : '';
if (!hash_equals($secret_key, $provided_key)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$upload_dir = wp_get_upload_dir();
$target_dir_base = rtrim($upload_dir['basedir'], '/\\') . '/' . trim($uploads_subdir, '/\\');
$target_url_base = rtrim($upload_dir['baseurl'], '/') . '/' . trim($uploads_subdir, '/');

if (!is_dir($target_dir_base)) {
    http_response_code(404);
    echo 'Target directory not found: ' . $target_dir_base;
    exit;
}

$now_dt = new DateTime('first day of this month');
$month_dirs = array();
$iter = clone $start_dt;
while ($iter <= $now_dt) {
    $year = $iter->format('Y');
    $month = $iter->format('m');
    $candidate_dir = $target_dir_base . '/' . $year . '/' . $month;
    if (is_dir($candidate_dir)) {
        $month_dirs[] = array(
            'dir' => $candidate_dir,
            'url' => $target_url_base . '/' . $year . '/' . $month,
        );
    }
    $iter->modify('+1 month');
}

function sermon_import_normalize_name($name) {
    $name = str_replace(array('-', '_'), ' ', $name);
    $name = preg_replace('/\s+/', ' ', trim($name));
    return function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
}

function sermon_import_find_preacher_id($preacher_name) {
    $terms = get_terms(array(
        'taxonomy' => 'wpfc_preacher',
        'hide_empty' => false,
    ));
    if (is_wp_error($terms)) {
        return $terms;
    }

    $wanted_name = sermon_import_normalize_name($preacher_name);
    $partial_matches = array();
    foreach ($terms as $term) {
        $term_name = sermon_import_normalize_name($term->name);
        if ($term_name === $wanted_name) {
            return array(
                'term_id' => (int) $term->term_id,
                'name' => $term->name,
                'match' => 'exact',
                'candidates' => array($term->name),
            );
        }
        if ($wanted_name !== '' && strpos($term_name, $wanted_name) !== false) {
            $partial_matches[] = $term;
        }
    }

    if (count($partial_matches) === 1) {
        return array(
            'term_id' => (int) $partial_matches[0]->term_id,
            'name' => $partial_matches[0]->name,
            'match' => 'partial',
            'candidates' => array($partial_matches[0]->name),
        );
    }

    $candidates = array();
    foreach ($partial_matches as $term) {
        $candidates[] = $term->name;
    }
    return array(
        'term_id' => 0,
        'name' => '',
        'match' => count($partial_matches) > 1 ? 'ambiguous' : 'not_found',
        'candidates' => $candidates,
    );
}

function sermon_import_normalize_audio_path($url, $uploads_subdir) {
    if (!is_scalar($url)) {
        return '';
    }
    $path = parse_url((string) $url, PHP_URL_PATH);
    if (!is_string($path)) {
        return '';
    }

    for ($i = 0; $i < 3; $i++) {
        $decoded_path = rawurldecode($path);
        if ($decoded_path === $path) {
            break;
        }
        $path = $decoded_path;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    $path = rtrim($path, '/');

    $subdir = '/' . trim(str_replace('\\', '/', $uploads_subdir), '/');
    $subdir_position = strrpos(strtolower($path), strtolower($subdir));
    if ($subdir_position !== false) {
        $after_subdir = substr($path, $subdir_position + strlen($subdir));
        if ($after_subdir === '' || strpos($after_subdir, '/') === 0) {
            return strtolower($subdir) . $after_subdir;
        }
    }

    return $path;
}

function sermon_import_parse_sermon_date($value) {
    if (!is_scalar($value)) {
        return false;
    }
    $value = trim((string) $value);

    if (preg_match('/^\d{10}$/', $value)) {
        $date = new DateTime('@' . $value);
        $date->setTimezone(wp_timezone());
        return $date;
    }

    $formats = array('!m/d/Y', '!Y-m-d', '!Ymd');
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();
        if (
            $date &&
            ($errors === false || (!$errors['warning_count'] && !$errors['error_count'])) &&
            $date->format(substr($format, 1)) === $value
        ) {
            return $date;
        }
    }
    return false;
}

function sermon_import_get_audio_value($post_id) {
    $audio_value = get_post_meta($post_id, 'sermon_audio', true);
    if (is_scalar($audio_value) && trim((string) $audio_value) !== '') {
        return (string) $audio_value;
    }

    $enclosure = get_post_meta($post_id, 'enclosure', true);
    if (is_array($enclosure)) {
        $audio_value = isset($enclosure[0]) ? $enclosure[0] : '';
    } elseif (is_scalar($enclosure)) {
        $enclosure_parts = preg_split('/[\r\n]+/', trim((string) $enclosure));
        $audio_value = isset($enclosure_parts[0]) ? $enclosure_parts[0] : '';
    } else {
        $audio_value = '';
    }

    return is_scalar($audio_value) ? trim((string) $audio_value) : '';
}

function sermon_import_load_existing_sermons($requested_post_type, $start_dt, $now_dt, $uploads_subdir, &$query_diagnostics) {
    $range_start = $start_dt->format('Y-m');
    $range_end = $now_dt->format('Y-m');
    global $wpdb;

    $post_type = is_string($requested_post_type) && $requested_post_type !== ''
        ? $requested_post_type
        : 'wpfc_sermon';
    $query_diagnostics = array(
        'received_post_type' => $requested_post_type,
        'effective_post_type' => $post_type,
        'registered_post_type' => post_type_exists($post_type),
        'registered_post_type_object' => get_post_type_object($post_type),
    );
    $type_counts = $wpdb->get_results(
        "SELECT post_type, COUNT(*) AS post_count FROM {$wpdb->posts} GROUP BY post_type ORDER BY post_type",
        ARRAY_A
    );
    $query_diagnostics['database_post_type_counts'] = is_array($type_counts) ? $type_counts : array();

    $post_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash', 'auto-draft', 'inherit') ORDER BY ID ASC",
            $post_type
        )
    );
    if (!is_array($post_ids) || $wpdb->last_error) {
        return new WP_Error(
            'sermon_query_failed',
            'Could not load existing sermons directly from the posts table: ' . $wpdb->last_error
        );
    }
    $query_diagnostics['database_matching_count'] = count($post_ids);

    $posts = array();
    foreach ($post_ids as $post_id) {
        $post = get_post((int) $post_id);
        if ($post instanceof WP_Post) {
            $posts[] = $post;
        }
    }

    $sermons = array();
    foreach ($posts as $post) {
        $sermon_date_value = get_post_meta($post->ID, 'sermon_date', true);
        $sermon_date_value = is_scalar($sermon_date_value) ? trim((string) $sermon_date_value) : wp_json_encode($sermon_date_value);
        $sermon_date = sermon_import_parse_sermon_date($sermon_date_value);
        $sermon_month = $sermon_date ? $sermon_date->format('Y-m') : '';
        $sermon_date_in_range = $sermon_month !== ''
            && $sermon_month >= $range_start
            && $sermon_month <= $range_end;
        if (!$sermon_date_in_range) {
            continue;
        }

        $audio_url = sermon_import_get_audio_value($post->ID);
        $audio_related_meta = array();
        foreach (get_post_meta($post->ID) as $meta_key => $meta_values) {
            if (stripos($meta_key, 'audio') !== false) {
                $audio_related_meta[$meta_key] = $meta_values;
            }
        }
        $taxonomy_terms = array();
        foreach (get_object_taxonomies($post_type) as $taxonomy) {
            $terms = wp_get_object_terms($post->ID, $taxonomy, array('fields' => 'all'));
            if (!is_wp_error($terms)) {
                $taxonomy_terms[$taxonomy] = $terms;
            }
        }
        $sermons[] = array(
            'id' => (int) $post->ID,
            'title' => $post->post_title,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_date' => $post->post_date,
            'sermon_date' => $sermon_date_value,
            'parsed_sermon_date' => $sermon_date ? $sermon_date->format('Y-m-d') : '',
            'sermon_date_in_range' => $sermon_date_in_range,
            'audio_url' => $audio_url,
            'audio_related_meta' => $audio_related_meta,
            'normalized_audio_path' => sermon_import_normalize_audio_path($audio_url, $uploads_subdir),
            'post_object' => get_post($post->ID),
            'all_post_meta' => get_post_meta($post->ID),
            'taxonomy_terms' => $taxonomy_terms,
        );
    }
    $query_diagnostics['database_matching_ids_in_date_range'] = array_map(
        function ($sermon) {
            return $sermon['id'];
        },
        $sermons
    );
    return $sermons;
}

function sermon_import_process_directory($dirpath, $dirurl, &$imported, &$skipped, &$errors, &$debug_log, $existing_sermons, $uploads_subdir, $post_type, $post_status, $author, $post_time, $dry_run, $debug) {
    $sermon_post_type = 'wpfc_sermon';
    $mp3_files = glob(rtrim($dirpath, '/\\') . '/*.mp3');
    if ($mp3_files === false) {
        $errors[] = array('file' => basename($dirpath), 'reason' => 'failed_to_list_directory');
        return 0;
    }

    foreach ($mp3_files as $file_path) {
        $filename = basename($file_path);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})_([^_]+)_(.+)\.mp3$/i', $filename, $matches)) {
            $skipped[] = array('file' => $filename, 'reason' => 'filename_format_mismatch');
            continue;
        }

        $sermon_date = DateTime::createFromFormat('!Y-m-d', $matches[1]);
        $date_errors = DateTime::getLastErrors();
        if (
            !$sermon_date ||
            ($date_errors !== false && ($date_errors['warning_count'] || $date_errors['error_count'])) ||
            $sermon_date->format('Y-m-d') !== $matches[1]
        ) {
            $skipped[] = array('file' => $filename, 'reason' => 'invalid_sermon_date');
            continue;
        }

        $preacher_name = str_replace('-', ' ', $matches[2]);
        $title = preg_replace('/[-_]+/', ' ', $matches[3]);
        $title = trim(preg_replace('/\s+/', ' ', $title));
        $file_url = rtrim($dirurl, '/') . '/' . rawurlencode($filename);

        $existing_post_id = 0;
        $expected_audio_path = sermon_import_normalize_audio_path($file_url, $uploads_subdir);
        $debug_candidates = array();
        foreach ($existing_sermons as $candidate) {
            $is_path_match = $candidate['normalized_audio_path'] !== ''
                && strcasecmp($candidate['normalized_audio_path'], $expected_audio_path) === 0;
            $same_filename = $candidate['normalized_audio_path'] !== ''
                && strcasecmp(basename($candidate['normalized_audio_path']), $filename) === 0;
            if ($debug && ($is_path_match || $same_filename)) {
                $debug_candidates[] = array(
                    'post_id' => $candidate['id'],
                    'title' => $candidate['title'],
                    'post_date' => $candidate['post_date'],
                    'sermon_date' => $candidate['sermon_date'],
                    'stored_audio' => $candidate['audio_url'],
                    'normalized_path' => $candidate['normalized_audio_path'],
                    'same_filename' => $same_filename,
                    'path_match' => $is_path_match,
                );
            }
            if ($is_path_match) {
                $existing_post_id = $candidate['id'];
            }
        }
        if ($debug) {
            $debug_log[] = array(
                'file' => $filename,
                'expected_url' => $file_url,
                'expected_normalized_path' => $expected_audio_path,
                'existing_sermons_checked' => count($existing_sermons),
                'candidate_count' => count($debug_candidates),
                'candidates' => $debug_candidates,
                'duplicate_post_id' => $existing_post_id,
            );
        }
        if ($existing_post_id) {
            $skipped[] = array(
                'file' => $filename,
                'reason' => 'already_has_sermon',
                'post_id' => $existing_post_id,
            );
            continue;
        }

        $post_date = $sermon_date->format('Y-m-d') . ' ' . $post_time;
        $preacher_match = sermon_import_find_preacher_id($preacher_name);
        if (is_wp_error($preacher_match)) {
            $errors[] = array('file' => $filename, 'reason' => 'failed_to_load_preacher_terms');
            continue;
        }
        $preacher_id = $preacher_match['term_id'];

        if ($dry_run) {
            $imported[] = array(
                'file' => $filename,
                'title' => $title,
                'sermon_date' => $sermon_date->format('m/d/Y'),
                'preacher' => $preacher_name,
                'preacher_match' => $preacher_match['match'],
                'matched_preacher' => $preacher_match['name'],
                'preacher_candidates' => $preacher_match['candidates'],
                'audio_url' => $file_url,
                'status' => $post_status,
                'post_type' => $sermon_post_type,
            );
            continue;
        }

        if (!post_type_exists($sermon_post_type)) {
            $errors[] = array(
                'file' => $filename,
                'reason' => 'sermon_post_type_not_registered: ' . $sermon_post_type,
            );
            continue;
        }

        $post_id = wp_insert_post(array(
            'post_title' => $title,
            'post_content' => '',
            'post_status' => $post_status,
            'post_author' => $author,
            'post_type' => $sermon_post_type,
            'post_date' => $post_date,
            'post_date_gmt' => get_gmt_from_date($post_date),
        ), true);
        if (is_wp_error($post_id) || !$post_id) {
            $errors[] = array(
                'file' => $filename,
                'reason' => is_wp_error($post_id)
                    ? 'failed_to_insert_sermon: ' . $post_id->get_error_message()
                    : 'failed_to_insert_sermon',
            );
            continue;
        }

        if (get_post_type($post_id) !== $sermon_post_type) {
            $type_update = wp_update_post(array(
                'ID' => $post_id,
                'post_type' => $sermon_post_type,
            ), true);
            if (is_wp_error($type_update) || get_post_type($post_id) !== $sermon_post_type) {
                $errors[] = array(
                    'file' => $filename,
                    'reason' => 'created_post_has_wrong_type_' . get_post_type($post_id)
                        . '; expected_' . $sermon_post_type
                        . (is_wp_error($type_update) ? ': ' . $type_update->get_error_message() : ''),
                    'post_id' => (int) $post_id,
                );
                continue;
            }
        }

        $metadata = array(
            'sermon_audio' => $file_url,
            'sermon_date' => $sermon_date->getTimestamp(),
            'sermon_video_url' => '',
            'sermon_description' => '',
            'bible_passage' => '',
        );
        foreach ($metadata as $meta_key => $meta_value) {
            update_post_meta($post_id, $meta_key, $meta_value);
            $saved_value = get_post_meta($post_id, $meta_key, true);
            if ((string) $saved_value !== (string) $meta_value) {
                $errors[] = array(
                    'file' => $filename,
                    'reason' => 'failed_to_save_meta_' . $meta_key,
                );
            }
        }

        $service_result = wp_set_object_terms($post_id, array(594), 'wpfc_service_type');
        if (is_wp_error($service_result)) {
            $errors[] = array('file' => $filename, 'reason' => 'failed_to_assign_service_type: ' . $service_result->get_error_message());
        }
        if ($preacher_id) {
            $preacher_result = wp_set_object_terms($post_id, array($preacher_id), 'wpfc_preacher');
            if (is_wp_error($preacher_result)) {
                $errors[] = array('file' => $filename, 'reason' => 'failed_to_assign_preacher: ' . $preacher_result->get_error_message());
            }
        } elseif ($preacher_match['match'] === 'ambiguous') {
            $errors[] = array(
                'file' => $filename,
                'reason' => 'ambiguous_preacher_match: ' . implode(', ', $preacher_match['candidates']),
            );
        }

        $imported[] = array(
            'file' => $filename,
            'post_id' => (int) $post_id,
            'post_type' => get_post_type($post_id),
            'preacher' => $preacher_name,
            'preacher_match' => $preacher_match['match'],
            'matched_preacher' => $preacher_match['name'],
            'preacher_candidates' => $preacher_match['candidates'],
        );
    }

    return count($mp3_files);
}

$imported = array();
$skipped = array();
$errors = array();
$debug_log = array();
$total_scanned = 0;
$query_diagnostics = array();
$existing_sermons = sermon_import_load_existing_sermons($post_type, $start_dt, $now_dt, $uploads_subdir, $query_diagnostics);
if (is_wp_error($existing_sermons)) {
    http_response_code(500);
    echo sermon_import_escape($existing_sermons->get_error_message());
    exit;
}
foreach ($month_dirs as $month_dir) {
    $total_scanned += sermon_import_process_directory(
        $month_dir['dir'],
        $month_dir['url'],
        $imported,
        $skipped,
        $errors,
        $debug_log,
        $existing_sermons,
        $uploads_subdir,
        $post_type,
        $default_post_status,
        $default_author,
        $post_time,
        $dry_run,
        $debug
    );
}

header('Content-Type: text/html; charset=utf-8');
function sermon_import_escape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Sermon Import Summary</title>
<style>
body { font: 16px/1.5 sans-serif; margin: 2rem; color: #222; }
.summary { padding: 1rem; border-left: 5px solid #2271b1; background: #f0f6fc; }
.summary-warning { border-color: #996800; background: #fcf9e8; color: #6d4c00; }
.summary-error { border-color: #b32d2e; background: #fcf0f1; color: #8a2424; }
.status { display: inline-block; padding: .1rem .45rem; border-radius: 3px; font-weight: 600; }
.status-success { color: #14532d; background: #dcfce7; }
.status-warning { color: #713f12; background: #fef3c7; }
.status-error { color: #7f1d1d; background: #fee2e2; }
.error-list { border-left: 4px solid #b32d2e; padding: .75rem 1rem .75rem 2rem; background: #fcf0f1; }
.debug-output { margin-top: 1.5rem; padding: .75rem; border: 1px solid #8c8f94; }
.debug-output > summary { cursor: pointer; font-weight: 700; }
table { width: 100%; border-collapse: collapse; margin: 1rem 0 1.5rem; }
th, td { border: 1px solid #c3c4c7; padding: .55rem .7rem; text-align: left; vertical-align: top; }
th { background: #f0f0f1; }
tbody tr:nth-child(even) { background: #f6f7f7; }
.table-wrap { overflow-x: auto; }
pre { overflow: auto; padding: 1rem; background: #f6f7f7; }
</style>
</head>
<body>
<h3>Sermon Import Summary</h3>
<?php if ($dry_run) : ?>
<p class="summary summary-warning"><strong>DRY RUN:</strong> No sermons or metadata were created or changed.</p>
<?php endif; ?>
<p><strong>Start at:</strong> <?php echo sermon_import_escape($start_at); ?></p>
<p><strong>Month folders scanned:</strong> <?php echo count($month_dirs); ?></p>
<p><strong>MP3 files scanned:</strong> <?php echo (int) $total_scanned; ?></p>
<p><strong>Existing sermons loaded:</strong> <?php echo count($existing_sermons); ?></p>
<div class="summary<?php echo $errors ? ' summary-error' : ($skipped ? ' summary-warning' : ''); ?>">
    <strong><?php echo $errors ? 'Completed with errors' : ($skipped ? 'Completed with skipped files' : 'Completed successfully'); ?></strong>
    &mdash; <?php echo $dry_run ? 'Would import' : 'Imported'; ?>: <?php echo count($imported); ?>,
    skipped: <?php echo count($skipped); ?>,
    errors: <?php echo count($errors); ?>.
</div>
<h4><?php echo $dry_run ? 'Would import' : 'Imported'; ?> (<?php echo count($imported); ?>)</h4>
<div class="table-wrap">
<table>
<thead><tr>
    <th>File</th>
    <th>Title</th>
    <th>Sermon date</th>
    <th>Preacher</th>
    <?php if ($dry_run) : ?>
        <th>Status</th><th>Post type</th><th>Audio URL</th>
    <?php else : ?>
        <th>Result</th><th>Post ID</th><th>Post type</th>
    <?php endif; ?>
</tr></thead>
<tbody>
<?php if (!$imported) : ?>
    <tr><td colspan="7">No sermons to display.</td></tr>
<?php endif; ?>
<?php foreach ($imported as $item) : ?>
    <tr>
        <td><?php echo sermon_import_escape($item['file']); ?></td>
        <?php if ($dry_run) : ?>
            <td><?php echo sermon_import_escape($item['title']); ?></td>
            <td><?php echo sermon_import_escape($item['sermon_date']); ?></td>
            <td>
                <?php echo sermon_import_escape($item['preacher']); ?>
        <?php if (isset($item['preacher_match']) && $item['preacher_match'] === 'not_found') : ?>
            <span class="status status-warning">Preacher not found</span>
        <?php elseif (isset($item['preacher_match']) && $item['preacher_match'] === 'ambiguous') : ?>
            <span class="status status-error">Preacher match ambiguous</span>
        <?php elseif (isset($item['preacher_match'])) : ?>
            <span class="status status-success"><?php echo sermon_import_escape($item['preacher_match']); ?></span>
        <?php endif; ?>
                <?php if ($item['matched_preacher'] !== '') : ?>
                    <br>Matched as <?php echo sermon_import_escape($item['matched_preacher']); ?>
                <?php elseif (!empty($item['preacher_candidates'])) : ?>
                    <br>Candidates: <?php echo sermon_import_escape(implode(', ', $item['preacher_candidates'])); ?>
                <?php endif; ?>
            </td>
            <td><?php echo sermon_import_escape($item['status']); ?></td>
            <td><?php echo sermon_import_escape($item['post_type']); ?></td>
            <td><?php echo sermon_import_escape($item['audio_url']); ?></td>
        <?php else : ?>
            <td><?php echo sermon_import_escape($item['title']); ?></td>
            <td><?php echo sermon_import_escape($item['sermon_date']); ?></td>
            <td>
                <?php echo sermon_import_escape(isset($item['preacher']) ? $item['preacher'] : ''); ?>
                <?php if (isset($item['preacher_match']) && $item['preacher_match'] === 'not_found') : ?>
                    <span class="status status-warning">Preacher not found</span>
                <?php elseif (isset($item['preacher_match']) && $item['preacher_match'] === 'ambiguous') : ?>
                    <span class="status status-error">Preacher match ambiguous</span>
                <?php elseif (isset($item['preacher_match'])) : ?>
                    <span class="status status-success"><?php echo sermon_import_escape($item['preacher_match']); ?></span>
                <?php endif; ?>
                <?php if (!empty($item['matched_preacher'])) : ?>
                    <br>Matched as <?php echo sermon_import_escape($item['matched_preacher']); ?>
                <?php elseif (!empty($item['preacher_candidates'])) : ?>
                    <br>Candidates: <?php echo sermon_import_escape(implode(', ', $item['preacher_candidates'])); ?>
                <?php endif; ?>
            </td>
            <td><span class="status status-success">Imported</span></td>
            <td><?php echo (int) $item['post_id']; ?></td>
            <td><?php echo sermon_import_escape($item['post_type']); ?></td>
        <?php endif; ?>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<h4>Skipped (<?php echo count($skipped); ?>)</h4>
<div class="table-wrap">
<table>
<thead><tr><th>File</th><th>Reason</th><th>Existing post ID</th></tr></thead>
<tbody>
<?php if (!$skipped) : ?>
    <tr><td colspan="3">No skipped files.</td></tr>
<?php endif; ?>
<?php foreach ($skipped as $item) : ?>
    <tr>
        <td><?php echo sermon_import_escape($item['file']); ?></td>
        <td><?php echo sermon_import_escape($item['reason']); ?></td>
        <td><?php echo isset($item['post_id']) ? (int) $item['post_id'] : '&mdash;'; ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php if ($debug) : ?>
<details class="debug-output">
<summary>Debug output (expand for diagnostics and sermon data)</summary>
<h4>Post type query diagnostics</h4>
<pre><?php
$query_diagnostic_dump = array(
    'received_post_type' => $query_diagnostics['received_post_type'],
    'effective_post_type' => $query_diagnostics['effective_post_type'],
    'registered_post_type' => $query_diagnostics['registered_post_type'],
    'registered_post_type_object' => $query_diagnostics['registered_post_type_object'],
    'database_matching_count' => $query_diagnostics['database_matching_count'],
    'database_matching_ids_in_date_range' => $query_diagnostics['database_matching_ids_in_date_range'],
    'database_post_type_counts' => $query_diagnostics['database_post_type_counts'],
);
ob_start();
var_dump($query_diagnostic_dump);
$query_dump_output = ob_get_clean();
echo sermon_import_escape($query_dump_output);
?></pre>
<h4>Duplicate-check debug</h4>
<?php foreach ($debug_log as $entry) : ?>
    <details>
        <summary>
            <?php echo sermon_import_escape($entry['file']); ?>:
            <?php echo $entry['duplicate_post_id'] ? 'duplicate found (post ' . (int) $entry['duplicate_post_id'] . ')' : 'no duplicate found'; ?>;
            <?php echo (int) $entry['candidate_count']; ?> candidate(s)
        </summary>
        <p><strong>Expected URL:</strong> <?php echo sermon_import_escape($entry['expected_url']); ?></p>
        <p><strong>Normalized expected path:</strong> <?php echo sermon_import_escape($entry['expected_normalized_path']); ?></p>
        <p><strong>Existing sermons checked by audio path:</strong> <?php echo (int) $entry['existing_sermons_checked']; ?></p>
        <?php if (empty($entry['candidates'])) : ?>
            <p>No existing sermon had the same filename.</p>
        <?php else : ?>
            <div class="table-wrap">
            <table>
            <thead><tr><th>Post</th><th>Post date</th><th>Sermon date</th><th>Stored audio</th><th>Normalized path</th><th>Same filename</th><th>Path match</th></tr></thead>
            <tbody>
            <?php foreach ($entry['candidates'] as $candidate) : ?>
                <tr>
                    <td><?php echo (int) $candidate['post_id']; ?> &mdash; <?php echo sermon_import_escape($candidate['title']); ?></td>
                    <td><?php echo sermon_import_escape($candidate['post_date']); ?></td>
                    <td><?php echo sermon_import_escape($candidate['sermon_date']); ?></td>
                    <td><?php echo sermon_import_escape($candidate['stored_audio']); ?></td>
                    <td><?php echo sermon_import_escape($candidate['normalized_path']); ?></td>
                    <td><?php echo $candidate['same_filename'] ? 'Yes' : 'No'; ?></td>
                    <td><?php echo $candidate['path_match'] ? 'Yes' : 'No'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            </table>
            </div>
        <?php endif; ?>
    </details>
<?php endforeach; ?>
    <h4>Existing sermons (filtered by sermon_date from start-at through current month)</h4>
    <div class="table-wrap">
    <table>
    <thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Post date</th><th>Sermon date</th><th>Audio path</th><th>Normalized path</th><th>Full data</th></tr></thead>
    <tbody>
    <?php if (!$existing_sermons) : ?>
        <tr><td colspan="8">No existing sermons in this sermon-date range.</td></tr>
    <?php endif; ?>
    <?php foreach ($existing_sermons as $sermon) : ?>
        <tr>
            <td><?php echo (int) $sermon['id']; ?></td>
            <td><?php echo sermon_import_escape($sermon['title']); ?></td>
            <td><?php echo sermon_import_escape($sermon['post_status']); ?></td>
            <td><?php echo sermon_import_escape($sermon['post_date']); ?></td>
            <td><?php echo sermon_import_escape($sermon['sermon_date']); ?><?php if ($sermon['parsed_sermon_date'] !== '') : ?><br><?php echo sermon_import_escape($sermon['parsed_sermon_date']); ?><?php endif; ?></td>
            <td><?php echo sermon_import_escape($sermon['audio_url']); ?></td>
            <td><?php echo sermon_import_escape($sermon['normalized_audio_path']); ?></td>
            <td>
                <?php ob_start(); var_dump(array(
                    'post_object' => $sermon['post_object'],
                    'all_post_meta' => $sermon['all_post_meta'],
                    'taxonomy_terms' => $sermon['taxonomy_terms'],
                )); $sermon_dump = ob_get_clean(); ?>
                <details><summary>View</summary><pre><?php echo sermon_import_escape($sermon_dump); ?></pre></details>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    </details>
<?php endif; ?>
<h4>Errors (<?php echo count($errors); ?>)</h4>
<?php if ($errors) : ?>
<div class="table-wrap">
<table class="error-list">
<thead><tr><th>File</th><th>Error</th><th>Post ID</th></tr></thead>
<tbody>
<?php foreach ($errors as $item) : ?>
    <tr>
        <td><?php echo sermon_import_escape($item['file']); ?></td>
        <td><?php echo sermon_import_escape($item['reason']); ?></td>
        <td><?php echo isset($item['post_id']) ? (int) $item['post_id'] : '&mdash;'; ?></td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else : ?>
<p>No errors.</p>
<?php endif; ?>
</body>
</html>
