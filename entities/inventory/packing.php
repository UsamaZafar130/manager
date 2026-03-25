<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db_connection.php';

// --- AJAX LIVE REFRESH LOGIC ---
if (isset($_GET['ajax_refresh'])) {
    // Safety check: Ensure order_ids exists and isn't empty
    if (empty($_GET['order_ids'])) {
        echo ""; 
        exit;
    }

    $order_ids = array_filter(array_map('intval', explode(',', $_GET['order_ids'])));
    if (!$order_ids) {
        echo "";
        exit;
    }

    $in = str_repeat('?,', count($order_ids) - 1) . '?';
    $stmt = $pdo->prepare("SELECT oi.item_id, i.name AS item_name, i.category_id, c.name AS category_name, oi.pack_size, SUM(oi.qty) as total_qty
        FROM order_items oi
        JOIN items i ON oi.item_id = i.id
        LEFT JOIN categories c ON i.category_id = c.id
        WHERE oi.order_id IN ($in)
        GROUP BY oi.item_id, oi.pack_size
        ORDER BY i.name, oi.pack_size");
    $stmt->execute($order_ids);
    $req_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $packed = [];
    $st2 = $pdo->query("SELECT item_id, pack_size, SUM(packs_packed) AS packs_packed FROM packing_log GROUP BY item_id, pack_size");
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = intval($row['item_id']) . '_' . intval($row['pack_size']);
        $packed[$key] = floatval($row['packs_packed']);
    }

    function format_pack_val($val) {
        return (abs($val - round($val)) < 0.000001) ? (int)round($val) : round($val, 2);
    }

    $serial = 1;
    foreach ($req_items as $it) {
        $item_id = intval($it['item_id']);
        $pack_size = floatval($it['pack_size']);
        $total_qty = floatval($it['total_qty']);
        $packs_required = ($pack_size <= 0) ? 0 : ($total_qty / $pack_size);
        $key = $item_id . '_' . intval($pack_size);
        $packs_packed = $packed[$key] ?? 0;
        $surplus = $packs_packed - $packs_required;

        $badge = $surplus >= 0 ? ($surplus > 0 ? 'badge-surplus' : 'badge-settled') : 'badge-outstanding';
        $display_req = format_pack_val($packs_required);
        $display_packed = format_pack_val($packs_packed);
        $display_surplus = format_pack_val($surplus);
        $badge_text = ($display_surplus == 0) ? '0' : (($display_surplus > 0 ? '+' : '') . $display_surplus);

        echo "<tr>
                <td>".$serial++."</td>
                <td>
                    <strong>".htmlspecialchars($it['item_name'])." ".format_pack_val($pack_size)."</strong>
                    <div class='text-muted' style='font-size: 11px;'>".htmlspecialchars($it['category_name'] ?? 'Uncategorized')."</div>
                </td>
                <td>$display_req</td>
                <td><span class='packs-packed-val fw-bold'>$display_packed</span></td>
                <td><span class='badge $badge'>$badge_text</span></td>
                <td>
                    <input type='number' class='form-control packs-input' style='width:90px;display:inline-block;' step='0.1'>
                    <input type='hidden' class='row-item-id' value='$item_id'>
                    <input type='hidden' class='row-pack-size' value='$pack_size'>
                    <button type='button' class='btn btn-primary btn-update-pack'><i class='fa fa-check-circle'></i></button>
                </td>
            </tr>";
    }
    exit; 
}
// --- END AJAX REFRESH LOGIC ---

$pageTitle = "Packing Log";
require_once '../../includes/header.php';

$order_ids = [];
if (!empty($_REQUEST['order_ids'])) {
    $order_ids = array_filter(array_map('intval', explode(',', $_REQUEST['order_ids'])));
}
if (!$order_ids) {
    echo "<div class='container mt-5'><div class='alert alert-danger'>No orders selected!</div></div>";
    require_once '../../includes/footer.php';
    exit;
}
$order_ids_str = implode(',', $order_ids);

$in  = str_repeat('?,', count($order_ids) - 1) . '?';
$stmt = $pdo->prepare("SELECT oi.item_id, i.name AS item_name, i.category_id, c.name AS category_name, oi.pack_size, SUM(oi.qty) as total_qty
    FROM order_items oi
    JOIN items i ON oi.item_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE oi.order_id IN ($in)
    GROUP BY oi.item_id, oi.pack_size
    ORDER BY i.name, oi.pack_size");
$stmt->execute($order_ids);
$req_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$packed = [];
if ($req_items) {
    $sql = "SELECT item_id, pack_size, SUM(packs_packed) AS packs_packed
            FROM packing_log
            GROUP BY item_id, pack_size";
    $st2 = $pdo->prepare($sql);
    $st2->execute();
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = intval($row['item_id']) . '_' . intval($row['pack_size']);
        $packed[$key] = floatval($row['packs_packed']);
    }
}

if (!function_exists('format_pack_val')) {
    function format_pack_val($val) {
        return (abs($val - round($val)) < 0.000001) ? (int)round($val) : round($val, 2);
    }
}
?>

<div class="orders-content">
    <div class="container mt-3">
        <div class="row mb-2">
            <div class="col-md-6">
                <h2 class="text-primary"><i class="fa fa-box me-2"></i> Packing Log</h2>
            </div>
            <div class="col-md-6 text-end">
                <button class="btn btn-success btn-3d me-2" id="btn-scan-pack">
                    <i class="fa fa-qrcode me-1"></i> Scan Packs
                </button>
                <button class="btn btn-primary btn-3d" id="btn-add-pack">
                    <i class="fa fa-plus me-1"></i> Add Packed Packs
                </button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12 text-end">
                <button class="btn btn-outline-warning btn-sm me-2" id="btn-orphaned-inv">
                    <i class="fa fa-ghost me-1"></i> Orphaned Inventory
                </button>
                <button class="btn btn-outline-info btn-sm" id="btn-all-inv">
                    <i class="fa fa-warehouse me-1"></i> All Inventory
                </button>
            </div>
        </div>

        <div class="alert alert-info max-width-700 mb-4">
            <strong>Track packing progress.</strong> Use <strong>Orphaned Inventory</strong> to find packs from cancelled orders and <strong>Break</strong> them back into loose stock.
        </div>

        <div class="table-responsive" style="position: relative; min-height: 200px;">
            <div id="packing-table-loader" style="display:none; position:absolute; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.7); z-index:10; text-align:center; padding-top:50px;">
                <i class="fa fa-spinner fa-spin fa-2x text-primary"></i>
                <div class="mt-2 fw-bold text-primary">Updating Table...</div>
            </div>
            
            <table class="entity-table table table-striped table-hover table-consistent" id="packing-log-table">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Item &amp; Pack Size</th>
                        <th>Packs Required</th>
                        <th>Packs Packed</th>
                        <th>Surplus/Shortfall</th>
                        <th>Add/Update</th>
                    </tr>
                </thead>
                <tbody id="packing-table-body">
                <?php $serial = 1; foreach ($req_items as $it):
                    $item_id = intval($it['item_id']);
                    $pack_size = floatval($it['pack_size']);
                    $total_qty = floatval($it['total_qty']);
                    $packs_required = ($pack_size <= 0) ? 0 : ($total_qty / $pack_size);
                    $key = $item_id . '_' . intval($pack_size);
                    $packs_packed = $packed[$key] ?? 0;
                    $surplus = $packs_packed - $packs_required;
                    $badge = $surplus >= 0 ? ($surplus > 0 ? 'badge-surplus' : 'badge-settled') : 'badge-outstanding';
                    
                    $display_req = format_pack_val($packs_required);
                    $display_packed = format_pack_val($packs_packed);
                    $display_surplus = format_pack_val($surplus);
                    $badge_text = ($display_surplus == 0) ? '0' : (($display_surplus > 0 ? '+' : '') . $display_surplus);
                ?>
                    <tr>
                        <td><?= $serial++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($it['item_name']) . ' ' . format_pack_val($pack_size) ?></strong>
                            <div class="text-muted" style="font-size: 11px;">
                                <?= htmlspecialchars($it['category_name'] ?? 'Uncategorized') ?>
                            </div>
                        </td>
                        <td><?= $display_req ?></td>
                        <td><span class="packs-packed-val fw-bold"><?= $display_packed ?></span></td>
                        <td><span class="badge <?= $badge ?>"><?= $badge_text ?></span></td>
                        <td>
                            <input type="number" class="form-control packs-input" min="-100" max="100" step="0.1" style="width:90px;display:inline-block;">
                            <input type="hidden" class="row-item-id" value="<?= $item_id ?>">
                            <input type="hidden" class="row-pack-size" value="<?= $pack_size ?>">
                            <button type="button" class="btn btn-primary btn-update-pack"><i class="fa fa-check-circle"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="add-pack-modal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-plus"></i> Add Packed Packs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="add-pack-form" class="entity-form">
                    <div class="form-row mb-3">
                        <label>Item & Pack Size</label>
                        <select id="pack-item-select" name="item_pack" class="form-control tom-select"></select>
                    </div>
                    <div class="form-row mb-3">
                        <label>Number of Packs Packed</label>
                        <input type="number" id="pack-count" name="pack_count" class="form-control" step="0.1" required>
                    </div>
                    <div class="form-row mb-3">
                        <label>Comment</label>
                        <input type="text" id="pack-comment" name="comment" class="form-control" maxlength="250">
                    </div>
                    <div class="form-actions text-end">
                        <button type="submit" class="btn btn-primary">Add Packs</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="orphaned-inv-modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title"><i class="fa fa-ghost"></i> Orphaned Inventory</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="orphaned-inv-body">
                <div class="text-center p-4"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="all-inv-modal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fa fa-warehouse"></i> All Shelved Inventory</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="all-inv-body">
                <div class="text-center p-4"><i class="fa fa-spinner fa-spin fa-2x"></i></div>
            </div>
        </div>
    </div>
</div>

<script>
window.packing_order_ids = "<?= htmlspecialchars($order_ids_str) ?>";

/**
 * Robust Table Refresh
 * Uses .ajax() with .always() to ensure loader is hidden even on failure.
 */
function refreshMainPackingTable() {
    const $loader = $('#packing-table-loader');
    const $tbody = $('#packing-table-body');
    
    if (!window.packing_order_ids || window.packing_order_ids === "") return;

    $loader.show();

    $.ajax({
        url: 'packing.php',
        type: 'GET',
        data: { 
            order_ids: window.packing_order_ids, 
            ajax_refresh: 1 
        },
        timeout: 8000
    })
    .done(function(html) {
        if (html.trim() !== "") {
            $tbody.html(html);
        }
    })
    .fail(function(xhr, status, error) {
        console.error("Refresh failed:", status, error);
    })
    .always(function() {
        $loader.hide();
    });
}

$(document).ready(function() {
    if ($('#packing-log-table').length && window.UnifiedTables) {
        UnifiedTables.init('#packing-log-table', 'packing');
    }

    // Modal Close Triggers
    $('#orphaned-inv-modal, #all-inv-modal').on('hidden.bs.modal', function() {
        refreshMainPackingTable();
    });

    $('#btn-orphaned-inv').on('click', function() {
        $('#orphaned-inv-modal').modal('show');
        $('#orphaned-inv-body').load('orinvent.php');
    });

    $('#btn-all-inv').on('click', function() {
        $('#all-inv-modal').modal('show');
        $('#all-inv-body').load('allinvent.php');
    });
});
</script>
<script src="/entities/inventory/js/packing.js"></script>
<?php require_once '../../includes/footer.php'; ?>