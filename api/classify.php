<?php
/**
 * AJAX endpoint: rule-based classification preview for the waste entry form.
 * Requires an authenticated staff or admin session.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/classification.php';

if (!is_logged_in()) {
    json_out(['ok' => false, 'error' => 'Not authenticated.'], 401);
}
if (!has_role('staff', 'admin')) {
    json_out(['ok' => false, 'error' => 'Forbidden.'], 403);
}

$category  = get_category(post_int('category_id'));
$container = get_container(post_int('container_id'));

if (!$category) {
    json_out(['ok' => false, 'error' => 'Select a valid waste category.', 'errors' => [], 'warnings' => [], 'guidance' => '']);
}

$result = classify_waste(
    [
        'quantity'  => post_float('quantity'),
        'unit'      => post_str('unit', 'kg'),
        'priority'  => post_str('priority', 'normal'),
    ],
    $category,
    $container
);

json_out([
    'ok'                  => true,
    'errors'              => $result['errors'],
    'warnings'            => $result['warnings'],
    'guidance'            => $result['guidance'],
    'priority_suggestion' => $result['priority_suggestion'],
]);
