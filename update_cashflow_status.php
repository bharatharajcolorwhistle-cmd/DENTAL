<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    // Add dcmt_status column if it doesn't exist
    $dcmt_pdo->exec("
        ALTER TABLE dcmt_cashflows 
        ADD COLUMN dcmt_status ENUM('open', 'closed') NOT NULL DEFAULT 'open' 
        AFTER dcmt_difference
    ");
    echo "Added dcmt_status column.\n";

    // Migrate existing data
    // If ending amount > 0 or denominations exist or notes exist or withdraw exists -> closed
    // This is a heuristic.
    $stmt = $dcmt_pdo->query("SELECT dcmt_id FROM dcmt_cashflows");
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $id) {
        $isClosed = false;
        
        // Check if end denominations exist
        $denomStmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_cashflow_denominations WHERE dcmt_cashflow_id = ? AND dcmt_denomination_type = 'end'");
        $denomStmt->execute([$id]);
        if ($denomStmt->fetchColumn() > 0) {
            $isClosed = true;
        }

        if (!$isClosed) {
            // Check other fields
            $rowStmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_cashflows WHERE dcmt_id = ?");
            $rowStmt->execute([$id]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                if (
                    (float)$row['dcmt_ending_amount'] > 0 ||
                    (float)$row['dcmt_owner_withdraw_amount'] > 0 ||
                    !empty($row['dcmt_owner_withdraw_name']) ||
                    !empty($row['dcmt_notes']) ||
                    (float)$row['dcmt_difference'] != 0
                ) {
                    $isClosed = true;
                }
            }
        }

        if ($isClosed) {
            $updateStmt = $dcmt_pdo->prepare("UPDATE dcmt_cashflows SET dcmt_status = 'closed' WHERE dcmt_id = ?");
            $updateStmt->execute([$id]);
        }
    }
    echo "Migrated existing records.\n";

} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column dcmt_status already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
