<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

function columnExists(PDO $pdo, string $table, string $column): bool {
    // Parameter markers are not supported in SHOW statements; build a safe literal
    // Table name here is a constant in our script; still wrap with backticks defensively
    $tableSafe = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
    $columnLiteral = $pdo->quote($column);
    $sql = "SHOW COLUMNS FROM `{$tableSafe}` LIKE {$columnLiteral}";
    $stmt = $pdo->query($sql);
    return $stmt && $stmt->rowCount() > 0;
}

try {
    if (!columnExists($dcmt_pdo, 'dcmt_patients', 'dcmt_first_name')) {
        $dcmt_pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_first_name VARCHAR(100) NULL AFTER dcmt_id");
        echo "Added dcmt_first_name column.\n";
    } else {
        echo "dcmt_first_name already exists.\n";
    }

    if (!columnExists($dcmt_pdo, 'dcmt_patients', 'dcmt_fathers_last_name')) {
        $dcmt_pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_fathers_last_name VARCHAR(100) NULL AFTER dcmt_first_name");
        echo "Added dcmt_fathers_last_name column.\n";
    } else {
        echo "dcmt_fathers_last_name already exists.\n";
    }

    if (!columnExists($dcmt_pdo, 'dcmt_patients', 'dcmt_mothers_last_name')) {
        $dcmt_pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_mothers_last_name VARCHAR(100) NULL AFTER dcmt_fathers_last_name");
        echo "Added dcmt_mothers_last_name column.\n";
    } else {
        echo "dcmt_mothers_last_name already exists.\n";
    }

    if (columnExists($dcmt_pdo, 'dcmt_patients', 'dcmt_last_name')) {
        $dcmt_pdo->exec("
            UPDATE dcmt_patients
            SET 
                dcmt_fathers_last_name = COALESCE(dcmt_fathers_last_name, TRIM(SUBSTRING_INDEX(COALESCE(dcmt_last_name, ''), ' ', 1))),
                dcmt_mothers_last_name = COALESCE(dcmt_mothers_last_name, NULLIF(TRIM(SUBSTRING(COALESCE(dcmt_last_name, ''), LENGTH(SUBSTRING_INDEX(COALESCE(dcmt_last_name, ''), ' ', 1)) + 2)), ''))
            WHERE dcmt_last_name IS NOT NULL
        ");
        $dcmt_pdo->exec("ALTER TABLE dcmt_patients DROP COLUMN dcmt_last_name");
        echo "Dropped dcmt_last_name column after backfill.\n";
    } else {
        echo "dcmt_last_name does not exist or already dropped.\n";
    }

    echo "Patients columns update completed.\n";
} catch (PDOException $e) {
    echo "Error updating patients columns: " . $e->getMessage() . "\n";
    exit(1);
}
?>
