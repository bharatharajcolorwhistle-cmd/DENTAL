<?php
/**
 * Patient checklist helpers
 * Dental Clinic Management System
 */

if (!function_exists('dcmt_patient_checklist_can_delete')) {
    /**
     * Only built-in admin or owner doctor may delete checklist items.
     */
    function dcmt_patient_checklist_can_delete(?array $user = null): bool
    {
        return dcmt_is_admin();
    }
}

if (!function_exists('dcmt_patient_checklist_ensure_table')) {
    function dcmt_patient_checklist_ensure_table(PDO $pdo): void
    {
        global $dcmt_db;
        if ($dcmt_db instanceof Dcmt_Database && method_exists($dcmt_db, 'addPatientChecklistTable')) {
            $dcmt_db->addPatientChecklistTable();
            return;
        }
        $check = $pdo->query("SHOW TABLES LIKE 'dcmt_patient_checklist_items'");
        if ($check && $check->rowCount() > 0) {
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_patient_checklist_items (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_patient_id INT NOT NULL,
                dcmt_title VARCHAR(255) NOT NULL,
                dcmt_description TEXT NULL,
                dcmt_is_completed TINYINT(1) NOT NULL DEFAULT 0,
                dcmt_completed_at DATETIME NULL,
                dcmt_completed_by_user_id INT NULL,
                dcmt_sort_order INT NOT NULL DEFAULT 0,
                dcmt_created_by_user_id INT NULL,
                dcmt_created_by VARCHAR(50) NOT NULL,
                dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_checklist_patient (dcmt_patient_id),
                INDEX idx_checklist_patient_done (dcmt_patient_id, dcmt_is_completed)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

if (!function_exists('dcmt_patient_checklist_get')) {
    /**
     * @return array<string,mixed>|null
     */
    function dcmt_patient_checklist_get(PDO $pdo, int $item_id): ?array
    {
        if ($item_id <= 0) {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT ci.*, p.dcmt_patient_name, u.dcmt_full_name AS created_by_name
            FROM dcmt_patient_checklist_items ci
            LEFT JOIN dcmt_patients p ON ci.dcmt_patient_id = p.dcmt_id
            LEFT JOIN dcmt_users u ON ci.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
            WHERE ci.dcmt_id = ?
            LIMIT 1
        ");
        $stmt->execute([$item_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('dcmt_patient_checklist_list_patients')) {
    /**
     * Patients that have at least one checklist item.
     *
     * @param array{search?:string,limit?:int,offset?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    function dcmt_patient_checklist_list_patients(PDO $pdo, array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $where_clause = 'WHERE ' . implode(' AND ', $where);

        $count_stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT ci.dcmt_patient_id)
            FROM dcmt_patient_checklist_items ci
            INNER JOIN dcmt_patients p ON p.dcmt_id = ci.dcmt_patient_id
            {$where_clause}
        ");
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetchColumn();

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $sql = "
            SELECT
                p.dcmt_id AS patient_id,
                p.dcmt_patient_name,
                p.dcmt_phone,
                COUNT(ci.dcmt_id) AS total_items,
                SUM(CASE WHEN ci.dcmt_is_completed = 1 THEN 1 ELSE 0 END) AS completed_items,
                SUM(CASE WHEN ci.dcmt_is_completed = 0 THEN 1 ELSE 0 END) AS pending_items,
                MAX(ci.dcmt_created_at) AS last_updated_at
            FROM dcmt_patient_checklist_items ci
            INNER JOIN dcmt_patients p ON p.dcmt_id = ci.dcmt_patient_id
            {$where_clause}
            GROUP BY p.dcmt_id, p.dcmt_patient_name, p.dcmt_phone
            ORDER BY last_updated_at DESC
        ";

        $list_params = $params;
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $list_params[] = $limit;
            $list_params[] = $offset;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($list_params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['items' => $items, 'total' => $total];
    }
}

if (!function_exists('dcmt_patient_checklist_list')) {
    /**
     * @param array{patient_id?:int,search?:string,status?:string,limit?:int,offset?:int} $filters
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    function dcmt_patient_checklist_list(PDO $pdo, array $filters = []): array
    {
        $where = [];
        $params = [];

        $patient_id = (int) ($filters['patient_id'] ?? 0);
        if ($patient_id > 0) {
            $where[] = 'ci.dcmt_patient_id = ?';
            $params[] = $patient_id;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(ci.dcmt_title LIKE ? OR ci.dcmt_description LIKE ? OR p.dcmt_patient_name LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $status = (string) ($filters['status'] ?? '');
        if ($status === 'pending') {
            $where[] = 'ci.dcmt_is_completed = 0';
        } elseif ($status === 'completed') {
            $where[] = 'ci.dcmt_is_completed = 1';
        }

        $where_clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $count_stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM dcmt_patient_checklist_items ci
            LEFT JOIN dcmt_patients p ON ci.dcmt_patient_id = p.dcmt_id
            {$where_clause}
        ");
        $count_stmt->execute($params);
        $total = (int) $count_stmt->fetchColumn();

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : null;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $sql = "
            SELECT ci.*,
                   p.dcmt_patient_name,
                   p.dcmt_phone,
                   u.dcmt_full_name AS created_by_name
            FROM dcmt_patient_checklist_items ci
            LEFT JOIN dcmt_patients p ON ci.dcmt_patient_id = p.dcmt_id
            LEFT JOIN dcmt_users u ON ci.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
            {$where_clause}
            ORDER BY ci.dcmt_is_completed ASC, ci.dcmt_sort_order ASC, ci.dcmt_id ASC
        ";
        if ($limit !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return ['items' => $items, 'total' => $total];
    }
}

if (!function_exists('dcmt_patient_checklist_create')) {
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $user
     * @return array{success:bool,message:string,item?:array<string,mixed>}
     */
    function dcmt_patient_checklist_create(PDO $pdo, array $data, array $user): array
    {
        $patient_id = (int) ($data['patient_id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if ($patient_id <= 0) {
            return ['success' => false, 'message' => trans('patient_checklist', 'patient_required')];
        }
        if ($title === '') {
            return ['success' => false, 'message' => trans('patient_checklist', 'title_required')];
        }
        if (strlen($title) > 255) {
            return ['success' => false, 'message' => trans('patient_checklist', 'title_too_long')];
        }

        $pstmt = $pdo->prepare('SELECT dcmt_id FROM dcmt_patients WHERE dcmt_id = ?');
        $pstmt->execute([$patient_id]);
        if (!$pstmt->fetch()) {
            return ['success' => false, 'message' => trans('patient', 'not_found')];
        }

        $sort_stmt = $pdo->prepare('SELECT COALESCE(MAX(dcmt_sort_order), 0) + 1 FROM dcmt_patient_checklist_items WHERE dcmt_patient_id = ?');
        $sort_stmt->execute([$patient_id]);
        $sort_order = (int) $sort_stmt->fetchColumn();

        $created_by = (string) ($user['dcmt_username'] ?? 'system');
        $created_by_user_id = (int) ($user['dcmt_id'] ?? 0) ?: null;

        $stmt = $pdo->prepare("
            INSERT INTO dcmt_patient_checklist_items (
                dcmt_patient_id, dcmt_title, dcmt_description, dcmt_is_completed,
                dcmt_sort_order, dcmt_created_by_user_id, dcmt_created_by, dcmt_created_at
            ) VALUES (?, ?, ?, 0, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $patient_id,
            $title,
            $description !== '' ? $description : null,
            $sort_order,
            $created_by_user_id,
            $created_by,
        ]);

        $item_id = (int) $pdo->lastInsertId();
        $item = dcmt_patient_checklist_get($pdo, $item_id);

        return [
            'success' => true,
            'message' => trans('patient_checklist', 'add_success'),
            'item' => $item ?: ['dcmt_id' => $item_id],
        ];
    }
}

if (!function_exists('dcmt_patient_checklist_create_many')) {
    /**
     * Create multiple checklist items for one patient in a single transaction.
     *
     * @param int $patient_id
     * @param array<int,array{title?:string,description?:string}> $items
     * @param array<string,mixed> $user
     * @return array{success:bool,message:string,errors?:array<int,string>,created_count?:int}
     */
    function dcmt_patient_checklist_create_many(PDO $pdo, int $patient_id, array $items, array $user): array
    {
        if ($patient_id <= 0) {
            return ['success' => false, 'message' => trans('patient_checklist', 'patient_required')];
        }

        $pstmt = $pdo->prepare('SELECT dcmt_id FROM dcmt_patients WHERE dcmt_id = ?');
        $pstmt->execute([$patient_id]);
        if (!$pstmt->fetch()) {
            return ['success' => false, 'message' => trans('patient', 'not_found')];
        }

        $normalized = [];
        $errors = [];
        foreach ($items as $index => $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $line = $index + 1;

            if ($title === '' && $description === '') {
                continue;
            }
            if ($title === '') {
                $errors[] = trans('patient_checklist', 'title_required') . ' (#' . $line . ')';
                continue;
            }
            if (strlen($title) > 255) {
                $errors[] = trans('patient_checklist', 'title_too_long') . ' (#' . $line . ')';
                continue;
            }
            $normalized[] = [
                'title' => $title,
                'description' => $description,
            ];
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => trans('patient_checklist', 'items_invalid'),
                'errors' => $errors,
            ];
        }

        if (empty($normalized)) {
            return [
                'success' => false,
                'message' => trans('patient_checklist', 'at_least_one_item'),
                'errors' => [trans('patient_checklist', 'at_least_one_item')],
            ];
        }

        try {
            $pdo->beginTransaction();
            $created = 0;
            foreach ($normalized as $row) {
                $result = dcmt_patient_checklist_create($pdo, [
                    'patient_id' => $patient_id,
                    'title' => $row['title'],
                    'description' => $row['description'],
                ], $user);
                if (!$result['success']) {
                    throw new RuntimeException($result['message'] ?? trans('patient_checklist', 'database_error'));
                }
                $created++;
            }
            $pdo->commit();

            return [
                'success' => true,
                'message' => trans('patient_checklist', 'add_many_success'),
                'created_count' => $created,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Patient checklist create_many error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => trans('patient_checklist', 'database_error'),
                'errors' => [$e->getMessage()],
            ];
        }
    }
}

if (!function_exists('dcmt_patient_checklist_update')) {
    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed>|null $user
     * @return array{success:bool,message:string,item?:array<string,mixed>}
     */
    function dcmt_patient_checklist_update(PDO $pdo, int $item_id, array $data, ?array $user = null): array
    {
        if ($item_id <= 0) {
            return ['success' => false, 'message' => trans('patient_checklist', 'invalid_id')];
        }

        $existing = dcmt_patient_checklist_get($pdo, $item_id);
        if (!$existing) {
            return ['success' => false, 'message' => trans('patient_checklist', 'not_found')];
        }

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if ($title === '') {
            return ['success' => false, 'message' => trans('patient_checklist', 'title_required')];
        }
        if (strlen($title) > 255) {
            return ['success' => false, 'message' => trans('patient_checklist', 'title_too_long')];
        }

        $stmt = $pdo->prepare("
            UPDATE dcmt_patient_checklist_items
            SET dcmt_title = ?,
                dcmt_description = ?
            WHERE dcmt_id = ?
        ");
        $stmt->execute([
            $title,
            $description !== '' ? $description : null,
            $item_id,
        ]);

        $item = dcmt_patient_checklist_get($pdo, $item_id);
        return [
            'success' => true,
            'message' => trans('patient_checklist', 'update_success'),
            'item' => $item ?: $existing,
        ];
    }
}

if (!function_exists('dcmt_patient_checklist_toggle')) {
    /**
     * @param array<string,mixed> $user
     * @return array{success:bool,message:string,item?:array<string,mixed>}
     */
    function dcmt_patient_checklist_toggle(PDO $pdo, int $item_id, bool $completed, array $user): array
    {
        if ($item_id <= 0) {
            return ['success' => false, 'message' => trans('patient_checklist', 'invalid_id')];
        }

        $existing = dcmt_patient_checklist_get($pdo, $item_id);
        if (!$existing) {
            return ['success' => false, 'message' => trans('patient_checklist', 'not_found')];
        }

        $completed_by = $completed ? ((int) ($user['dcmt_id'] ?? 0) ?: null) : null;
        $completed_at = $completed ? date('Y-m-d H:i:s') : null;

        $stmt = $pdo->prepare("
            UPDATE dcmt_patient_checklist_items
            SET dcmt_is_completed = ?,
                dcmt_completed_at = ?,
                dcmt_completed_by_user_id = ?
            WHERE dcmt_id = ?
        ");
        $stmt->execute([(int) $completed, $completed_at, $completed_by, $item_id]);

        $item = dcmt_patient_checklist_get($pdo, $item_id);
        return [
            'success' => true,
            'message' => $completed
                ? trans('patient_checklist', 'mark_complete_success')
                : trans('patient_checklist', 'mark_incomplete_success'),
            'item' => $item ?: $existing,
        ];
    }
}

if (!function_exists('dcmt_patient_checklist_delete')) {
    /**
     * @param array<string,mixed>|null $user
     * @return array{success:bool,message:string}
     */
    function dcmt_patient_checklist_delete(PDO $pdo, int $item_id, ?array $user = null): array
    {
        if (!dcmt_patient_checklist_can_delete($user)) {
            return ['success' => false, 'message' => trans('patient_checklist', 'no_delete_permission')];
        }
        if ($item_id <= 0) {
            return ['success' => false, 'message' => trans('patient_checklist', 'invalid_id')];
        }

        $existing = dcmt_patient_checklist_get($pdo, $item_id);
        if (!$existing) {
            return ['success' => false, 'message' => trans('patient_checklist', 'not_found')];
        }

        $stmt = $pdo->prepare('DELETE FROM dcmt_patient_checklist_items WHERE dcmt_id = ?');
        $stmt->execute([$item_id]);

        return [
            'success' => true,
            'message' => trans('patient_checklist', 'delete_success'),
        ];
    }
}
