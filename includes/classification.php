<?php
/**
 * Rule-based waste classification and validation module.
 *
 * IMPORTANT: This is an explicit, configurable RULE ENGINE - it is NOT a trained
 * machine-learning model. It validates a waste entry against the facility's
 * segregation rules (container/category compatibility, sharps routing, hazardous
 * handling, capacity limits). It never invents a category from an RFID tag.
 *
 * All rules are driven by data in the waste_categories table so an administrator
 * can tune them (see admin/categories.php).
 */

declare(strict_types=1);

/**
 * Run the classification / validation rules.
 *
 * @param array      $input    Associative array: quantity, unit, priority, notes, department, location
 * @param array      $category Row from waste_categories
 * @param array|null $container Row from containers (or null when none selected)
 * @return array{errors:string[],warnings:string[],guidance:string,priority_suggestion:?string,valid:bool}
 */
function classify_waste(array $input, array $category, ?array $container): array
{
    $errors   = [];
    $warnings = [];
    $notes    = [];

    $quantity = isset($input['quantity']) ? (float)$input['quantity'] : null;
    $unit     = $input['unit'] ?? '';
    $catType  = $category['container_type'] ?? null;
    $isHaz    = (int)($category['is_hazardous'] ?? 0) === 1;

    if ($quantity !== null && $quantity <= 0) {
        $errors[] = 'Quantity must be greater than zero.';
    }

    // Rule 1: container/category compatibility (never mix incompatible streams).
    if ($container) {
        $containerCat = $container['category_id'] ?? null;
        if ($containerCat !== null && (int)$containerCat !== (int)$category['id']) {
            $errors[] = sprintf(
                'Segregation rule: "%s" waste must not be placed in container %s, which is assigned to a different waste category.',
                $category['name'],
                $container['container_code']
            );
        }
        if ((string)($container['status'] ?? 'active') === 'full') {
            $errors[] = 'Container ' . $container['container_code'] . ' is marked FULL. Use another container.';
        } elseif ((string)($container['status'] ?? 'active') === 'maintenance') {
            $errors[] = 'Container ' . $container['container_code'] . ' is under maintenance and cannot accept waste.';
        }
    } else {
        $warnings[] = 'No container selected. Assign a container so the waste can be tracked end-to-end.';
    }

    // Rule 2: sharps must go into a sharps-type container.
    if ($catType === 'sharps') {
        $containerType = null;
        if ($container && isset($container['category_container_type'])) {
            $containerType = $container['category_container_type'];
        }
        if ($container && $containerType !== null && $containerType !== 'sharps') {
            $errors[] = 'Sharps must only be placed in a puncture-proof sharps container.';
        }
        if ($unit !== '' && !in_array($unit, ['count', 'pieces', 'units', 'pcs'], true)) {
            $warnings[] = 'Sharps are normally counted in pieces/units rather than weight.';
        }
    }

    // Rule 3: hazardous streams must use labelled (yellow/red/chemical) containers.
    if ($isHaz && $container && isset($container['category_container_type'])) {
        $ctype = $container['category_container_type'];
        if (in_array($ctype, ['general', 'black', 'green'], true)) {
            $errors[] = 'Hazardous waste must not be placed in a general non-hazardous container.';
        }
    }

    // Rule 4: capacity / fill-level sanity.
    if ($container && $quantity !== null && isset($container['capacity'])) {
        $capacity = (float)$container['capacity'];
        $current  = (float)($container['fill_level'] ?? 0);
        if ($capacity > 0 && ($current + $quantity) > $capacity) {
            $warnings[] = sprintf(
                'Estimated fill level (%.2f %s) would exceed the container capacity (%.2f). Plan a collection.',
                $current + $quantity,
                $unit,
                $capacity
            );
        }
        if ($capacity > 0 && $current >= $capacity * 0.9) {
            $warnings[] = 'Container is close to full - schedule a collection soon.';
        }
    }

    // Rule 5: priority suggestion (advisory only, the user decides).
    $prioritySuggestion = null;
    if ($isHaz && $quantity !== null && $quantity >= 5) {
        $prioritySuggestion = 'high';
    } elseif ($isHaz) {
        $prioritySuggestion = 'normal';
    } elseif ($quantity !== null && $quantity >= 10) {
        $prioritySuggestion = 'normal';
    }

    $guidance = (string)($category['handling_guidance'] ?? '');

    return [
        'errors'              => $errors,
        'warnings'            => $warnings,
        'guidance'            => $guidance,
        'priority_suggestion' => $prioritySuggestion,
        'valid'               => count($errors) === 0,
    ];
}

/** Fetch a category row by id. */
function get_category(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM waste_categories WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

/** Fetch a container row (joined with its category's container type) by id. */
function get_container(?int $id): ?array
{
    if (!$id) {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT c.*, wc.container_type AS category_container_type
         FROM containers c LEFT JOIN waste_categories wc ON wc.id = c.category_id
         WHERE c.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}
