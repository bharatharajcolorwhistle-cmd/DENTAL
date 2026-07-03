<?php
/**
 * Expense category helpers (parent / sub-category support)
 */

if (!function_exists('dcmt_expense_category_has_parent_column')) {
    function dcmt_expense_category_has_parent_column(PDO $pdo): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM dcmt_expense_categories LIKE 'dcmt_parent_category_id'");
            $cached = $stmt && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $cached = false;
        }
        return $cached;
    }
}

if (!function_exists('dcmt_fetch_expense_categories_for_select')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function dcmt_fetch_expense_categories_for_select(PDO $pdo): array
    {
        if (dcmt_expense_category_has_parent_column($pdo)) {
            $sql = "
                SELECT c.dcmt_id, c.dcmt_name, c.dcmt_parent_category_id, c.dcmt_created_by,
                       p.dcmt_name AS parent_name
                FROM dcmt_expense_categories c
                LEFT JOIN dcmt_expense_categories p ON c.dcmt_parent_category_id = p.dcmt_id
                WHERE c.dcmt_status = 'active'
                ORDER BY COALESCE(p.dcmt_name, c.dcmt_name), (c.dcmt_parent_category_id IS NULL) DESC, c.dcmt_name
            ";
        } else {
            $sql = "
                SELECT dcmt_id, dcmt_name, dcmt_created_by,
                       NULL AS dcmt_parent_category_id, NULL AS parent_name
                FROM dcmt_expense_categories
                WHERE dcmt_status = 'active'
                ORDER BY dcmt_name
            ";
        }

        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}

if (!function_exists('dcmt_expense_category_display_name')) {
    function dcmt_expense_category_display_name(array $category): string
    {
        $name = trim((string)($category['dcmt_name'] ?? ''));
        if (($category['dcmt_created_by'] ?? '') === 'system') {
            $translated = trans('expense_category', $name);
            if ($translated !== $name) {
                return $translated;
            }
        }
        return $name;
    }
}

if (!function_exists('dcmt_expense_category_child_option_label')) {
    function dcmt_expense_category_child_option_label(string $label): string
    {
        // Non-breaking spaces indent sub-categories under their parent in native <select> lists.
        return str_repeat("\xC2\xA0", 5) . $label;
    }
}

if (!function_exists('dcmt_render_expense_category_select_options')) {
    /**
     * Render hierarchical <option> markup for expense category dropdowns.
     * Parent categories appear once; sub-categories are listed underneath with a slight indent.
     *
     * @param array<int, array<string, mixed>> $categories
     * @param mixed $selected
     */
    function dcmt_render_expense_category_select_options(
        array $categories,
        $selected = null,
        bool $include_placeholder = true,
        string $placeholder = ''
    ): void {
        if ($include_placeholder && $placeholder !== '') {
            echo '<option value="">' . htmlspecialchars($placeholder) . '</option>';
        }

        $parents = [];
        $children_by_parent = [];
        $orphans = [];

        foreach ($categories as $cat) {
            $id = (int)($cat['dcmt_id'] ?? 0);
            $parent_id = (int)($cat['dcmt_parent_category_id'] ?? 0);
            if ($parent_id > 0) {
                $children_by_parent[$parent_id][] = $cat;
            } elseif ($id > 0) {
                $parents[$id] = $cat;
            }
        }

        foreach ($children_by_parent as $parent_id => $children) {
            if (!isset($parents[$parent_id])) {
                foreach ($children as $child) {
                    $orphans[] = $child;
                }
                unset($children_by_parent[$parent_id]);
            }
        }

        foreach ($parents as $parent_id => $parent) {
            $children = $children_by_parent[$parent_id] ?? [];
            $parent_label = dcmt_expense_category_display_name($parent);
            $parent_selected = ((string)$selected === (string)$parent_id) ? ' selected' : '';
            echo '<option value="' . $parent_id . '"' . $parent_selected . '>'
                . htmlspecialchars($parent_label) . '</option>';

            foreach ($children as $child) {
                $child_id = (int)($child['dcmt_id'] ?? 0);
                $child_label = dcmt_expense_category_display_name($child);
                $child_selected = ((string)$selected === (string)$child_id) ? ' selected' : '';
                echo '<option value="' . $child_id . '"' . $child_selected . '>'
                    . htmlspecialchars(dcmt_expense_category_child_option_label($child_label)) . '</option>';
            }
        }

        foreach ($orphans as $cat) {
            $id = (int)($cat['dcmt_id'] ?? 0);
            $label = dcmt_expense_category_display_name($cat);
            $is_selected = ((string)$selected === (string)$id) ? ' selected' : '';
            echo '<option value="' . $id . '"' . $is_selected . '>'
                . htmlspecialchars(dcmt_expense_category_child_option_label($label)) . '</option>';
        }
    }
}

if (!function_exists('dcmt_expense_category_child_count')) {
    function dcmt_expense_category_child_count(PDO $pdo, int $category_id): int
    {
        if ($category_id <= 0 || !dcmt_expense_category_has_parent_column($pdo)) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM dcmt_expense_categories WHERE dcmt_parent_category_id = ?');
        $stmt->execute([$category_id]);
        return (int)$stmt->fetchColumn();
    }
}
