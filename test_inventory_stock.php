<?php
/**
 * Test Inventory Stock Update
 * This script tests the inventory stock update functionality
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

echo "<h2>Inventory Stock Update Test</h2>";

try {
    // Test 1: Check current inventory stock
    echo "<h3>Test 1: Current Inventory Stock</h3>";
    $stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_name, dcmt_quantity, dcmt_price FROM dcmt_inventory WHERE dcmt_status = 'active' LIMIT 5");
    $inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Name</th><th>Current Stock</th><th>Price</th></tr>";
    foreach ($inventory_items as $item) {
        echo "<tr>";
        echo "<td>{$item['dcmt_id']}</td>";
        echo "<td>{$item['dcmt_name']}</td>";
        echo "<td>{$item['dcmt_quantity']}</td>";
        echo "<td>" . dcmt_format_currency($item['dcmt_price']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Test 2: Check recent income records with product items
    echo "<h3>Test 2: Recent Income Records with Product Items</h3>";
    $stmt = $dcmt_pdo->query("
        SELECT i.dcmt_id, i.dcmt_patient_name, i.dcmt_type, i.dcmt_amount, i.dcmt_transaction_date,
               ib.dcmt_inventory_id, ib.dcmt_quantity, inv.dcmt_name as product_name
        FROM dcmt_income i
        LEFT JOIN dcmt_income_breakdown ib ON i.dcmt_id = ib.dcmt_id AND ib.dcmt_line_type = 'product'
        LEFT JOIN dcmt_inventory inv ON ib.dcmt_inventory_id = inv.dcmt_id
        WHERE i.dcmt_type IN ('consultation', 'product_sale') 
        AND ib.dcmt_inventory_id IS NOT NULL
        ORDER BY i.dcmt_created_at DESC
        LIMIT 10
    ");
    $income_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($income_items)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Income ID</th><th>Patient</th><th>Type</th><th>Amount</th><th>Date</th><th>Product</th><th>Qty Used</th></tr>";
        foreach ($income_items as $item) {
            echo "<tr>";
            echo "<td>{$item['dcmt_id']}</td>";
            echo "<td>{$item['dcmt_patient_name']}</td>";
            echo "<td>{$item['dcmt_type']}</td>";
            echo "<td>" . dcmt_format_currency($item['dcmt_amount']) . "</td>";
            echo "<td>{$item['dcmt_transaction_date']}</td>";
            echo "<td>{$item['product_name']}</td>";
            echo "<td>{$item['dcmt_quantity']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No income records with product items found.</p>";
    }
    
    // Test 3: Verify stock calculation
    echo "<h3>Test 3: Stock Calculation Verification</h3>";
    $stmt = $dcmt_pdo->query("
        SELECT 
            inv.dcmt_id,
            inv.dcmt_name,
            inv.dcmt_quantity as current_stock,
            COALESCE(SUM(ib.dcmt_quantity), 0) as used_quantity,
            (inv.dcmt_quantity + COALESCE(SUM(ib.dcmt_quantity), 0)) as original_stock
        FROM dcmt_inventory inv
        LEFT JOIN dcmt_income_breakdown ib ON inv.dcmt_id = ib.dcmt_inventory_id AND ib.dcmt_line_type = 'product'
        LEFT JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
        WHERE inv.dcmt_status = 'active'
        GROUP BY inv.dcmt_id, inv.dcmt_name, inv.dcmt_quantity
        HAVING used_quantity > 0
        ORDER BY used_quantity DESC
        LIMIT 10
    ");
    $stock_calculations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($stock_calculations)) {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr><th>Product</th><th>Current Stock</th><th>Used Qty</th><th>Original Stock</th><th>Status</th></tr>";
        foreach ($stock_calculations as $calc) {
            $status = $calc['current_stock'] == $calc['original_stock'] ? '✅ Correct' : '❌ Mismatch';
            echo "<tr>";
            echo "<td>{$calc['dcmt_name']}</td>";
            echo "<td>{$calc['current_stock']}</td>";
            echo "<td>{$calc['used_quantity']}</td>";
            echo "<td>{$calc['original_stock']}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No products with usage found.</p>";
    }
    
    echo "<h3>Test Complete</h3>";
    echo "<p>If you see any '❌ Mismatch' status above, there might be an issue with inventory stock updates.</p>";
    echo "<p>All calculations should show '✅ Correct' status for proper inventory management.</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
