<?php
// Start output buffering to prevent accidental whitespace/headers breaking the JSON
ob_start();

require_once '../../includes/auth_check.php';
require_once '../../includes/db_connection.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * Helper to calculate required packs for live badge updates
 */
function get_packs_required_live($pdo, $item_id, $pack_size) {
    $stmt = $pdo->prepare("SELECT SUM(oi.qty) FROM order_items oi 
                           JOIN sales_orders so ON oi.order_id = so.id 
                           WHERE oi.item_id = ? AND oi.pack_size = ? 
                           AND so.delivered = 0 AND so.cancelled = 0");
    $stmt->execute([$item_id, $pack_size]);
    $total_qty = (float)$stmt->fetchColumn();
    return $pack_size > 0 ? ($total_qty / $pack_size) : 0;
}

/**
 * Helper to get total net stock for an item
 */
function get_total_stock_live($pdo, $item_id) {
    $stmt = $pdo->prepare("SELECT SUM(qty) FROM inventory_ledger WHERE item_id = ?");
    $stmt->execute([$item_id]);
    return (float)$stmt->fetchColumn();
}

// ==========================================
// ACTIONS
// ==========================================

if ($action === 'add_stock') {
    $item_id = intval($_POST['item_id'] ?? 0);
    $qty = floatval($_POST['qty'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    if ($item_id && $qty != 0) {
        $change_type = $qty > 0 ? 'manufacture' : 'reconcile';
        $stmt = $pdo->prepare("INSERT INTO inventory_ledger (item_id, change_type, qty, ref_type, comment, created_by) VALUES (?, ?, ?, 'manual', ?, ?)");
        $stmt->execute([$item_id, $change_type, $qty, $comment ?: 'Manual stock added', $_SESSION['user_id']]);
        
        ob_clean();
        echo json_encode(['success'=>true]);
    } else {
        ob_clean();
        echo json_encode(['success'=>false, 'error'=>'Invalid input.']);
    }
    exit;
}

if ($action === 'update_manufactured') {
    $item_id = intval($_POST['item_id'] ?? 0);
    $qty = floatval($_POST['qty'] ?? 0);
    if ($item_id && $qty > 0) {
        $stmt = $pdo->prepare("INSERT INTO inventory_ledger (item_id, change_type, qty, ref_type, comment, created_by) VALUES (?, 'manufacture', ?, 'manual', 'Manual stock added (inline)', ?)");
        $stmt->execute([$item_id, $qty, $_SESSION['user_id']]);

        // FETCH NEW TOTAL FOR LIVE UPDATE
        $new_total = get_total_stock_live($pdo, $item_id);

        ob_clean();
        echo json_encode([
            'success' => true,
            'new_total' => $new_total,
            'item_id' => $item_id
        ]);
    } else {
        ob_clean();
        echo json_encode(['success'=>false, 'error'=>'Please enter a positive quantity.']);
    }
    exit;
}

if ($action === 'add_packed_packs') {
    $item_id = intval($_POST['item_id'] ?? 0);
    $pack_size = intval($_POST['pack_size'] ?? 0);
    $pack_count = intval($_POST['pack_count'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $barcode = isset($_POST['barcode']) ? trim($_POST['barcode']) : null;

    if ($item_id && $pack_size > 0 && $pack_count != 0) {
        if ($pack_count < 0 && $comment === '') {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Comment is required for negative entries.']);
            exit;
        }
        $log_comment = ($pack_count > 0 && $comment === '') ? 'manual entry' : $comment;

        $stmt = $pdo->prepare("INSERT INTO packing_log (item_id, pack_size, packs_packed, barcode, packed_by, comment) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$item_id, $pack_size, $pack_count, $barcode, $_SESSION['user_id'], $log_comment]);

        $stmt_new = $pdo->prepare("SELECT SUM(packs_packed) FROM packing_log WHERE item_id = ? AND pack_size = ?");
        $stmt_new->execute([$item_id, $pack_size]);
        $new_packed = (float)$stmt_new->fetchColumn();

        $required_packs = get_packs_required_live($pdo, $item_id, $pack_size);
        $new_surplus = $new_packed - $required_packs;
        $badge_class = $new_surplus >= 0 ? ($new_surplus > 0 ? 'badge-surplus' : 'badge-settled') : 'badge-outstanding';

        ob_clean();
        echo json_encode([
            'success' => true,
            'item_id' => $item_id,
            'pack_size' => $pack_size,
            'new_packed' => $new_packed,
            'new_surplus' => round($new_surplus, 2),
            'badge_class' => $badge_class
        ]);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid entry.']);
    }
    exit;
}

if ($action === 'scan_pack_barcode') {
    $barcode = trim($_POST['barcode'] ?? '');
    $stmt = $pdo->prepare("SELECT item_id, pack_size FROM item_pack_codes WHERE barcode = ?");
    $stmt->execute([$barcode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stmt_log = $pdo->prepare("INSERT INTO packing_log (item_id, pack_size, barcode, packs_packed, packed_by, comment) VALUES (?, ?, ?, 1, ?, 'scanned')");
        $stmt_log->execute([$row['item_id'], $row['pack_size'], $barcode, $_SESSION['user_id']]);
        ob_clean();
        echo json_encode(['success' => true]);
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Barcode not found.']);
    }
    exit;
}

if ($action === 'get_item_stock_info') {
    $item_id = intval($_POST['item_id'] ?? 0);
    $order_ids = array_filter(array_map('intval', explode(',', $_POST['order_ids'] ?? '')));
    if ($item_id && $order_ids) {
        $in  = str_repeat('?,', count($order_ids) - 1) . '?';
        $stmt = $pdo->prepare("SELECT SUM(oi.qty) as total_qty FROM order_items oi WHERE oi.item_id = ? AND oi.order_id IN ($in)");
        $stmt->execute(array_merge([$item_id], $order_ids));
        $total_required = floatval($stmt->fetchColumn() ?: 0);

        $manufactured = get_total_stock_live($pdo, $item_id);

        ob_clean();
        echo json_encode([
            'success' => true,
            'total_required' => $total_required,
            'manufactured' => $manufactured,
            'diff' => $total_required - $manufactured
        ]);
    } else {
        ob_clean();
        echo json_encode(['success'=>false, 'error'=>'Invalid input']);
    }
    exit;
}

if ($action === 'get_excess_stock') {
    $orders = $pdo->query("SELECT id FROM sales_orders WHERE delivered=0 AND cancelled=0")->fetchAll(PDO::FETCH_COLUMN);
    $order_ids = $orders ?: [];
    $required_by_item = [];

    $items_data = $pdo->query("SELECT i.id AS item_id, i.name, i.price_per_unit, c.name AS category FROM items i LEFT JOIN categories c ON i.category_id = c.id WHERE i.deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);

    if ($order_ids) {
        $in  = str_repeat('?,', count($order_ids) - 1) . '?';
        $stmt_req = $pdo->prepare("SELECT oi.item_id, SUM(oi.qty) AS required FROM order_items oi WHERE oi.order_id IN ($in) GROUP BY oi.item_id");
        $stmt_req->execute($order_ids);
        foreach ($stmt_req->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $required_by_item[$row['item_id']] = floatval($row['required']);
        }
    }

    $stmt_manuf = $pdo->query("SELECT item_id, SUM(qty) AS manufactured FROM inventory_ledger GROUP BY item_id");
    $manufactured_by_item = [];
    foreach ($stmt_manuf->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $manufactured_by_item[$row['item_id']] = floatval($row['manufactured']);
    }

    $result = [];
    foreach ($items_data as $it) {
        $mid = $it['item_id'];
        $manuf = $manufactured_by_item[$mid] ?? 0;
        $required = $required_by_item[$mid] ?? 0;
        $excess = $manuf - $required;
        if ($excess > 0) {
            $result[] = [
                'name' => $it['name'],
                'category' => $it['category'] ?? '',
                'manufactured' => $manuf,
                'required' => $required,
                'excess' => $excess,
                'price_per_unit' => $it['price_per_unit'] ?? 0,
                'excess_value' => $excess * (float)($it['price_per_unit'] ?? 0)
            ];
        }
    }
    ob_clean();
    echo json_encode(['success'=>true, 'data'=>$result]);
    exit;
}

ob_end_flush();
echo json_encode(['success'=>false, 'error'=>'Invalid action']);