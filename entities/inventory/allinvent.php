<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db_connection.php';

// Helper function for clean number formatting
if (!function_exists('format_inv_val')) {
    function format_inv_val($val) {
        return (abs($val - round($val)) < 0.000001) ? (int)round($val) : round($val, 2);
    }
}

try {
    // 1. Get Requirements for active orders - JOINING customers table to get the name
    $stmt_req = $pdo->query("SELECT oi.item_id, oi.pack_size, oi.qty, so.id as order_id, c.name as customer_name 
                             FROM order_items oi 
                             JOIN sales_orders so ON oi.order_id = so.id 
                             JOIN customers c ON so.customer_id = c.id
                             WHERE so.delivered = 0 AND so.cancelled = 0");
    
    $active_reqs = [];
    $allocation_details = [];

    while ($r = $stmt_req->fetch(PDO::FETCH_ASSOC)) {
        $pSize = (float)$r['pack_size'] > 0 ? (float)$r['pack_size'] : 1;
        $key = $r['item_id'] . '_' . intval($pSize);
        $packs_needed = $r['qty'] / $pSize;
        
        $active_reqs[$key] = ($active_reqs[$key] ?? 0) + $packs_needed;
        // Store strings for the printable list
        $allocation_details[$key][] = "Order #" . $r['order_id'] . " (" . $r['customer_name'] . "): " . format_inv_val($packs_needed) . " packs";
    }

    // 2. Fetch all packed inventory currently on shelves
    $sql = "SELECT pl.item_id, pl.pack_size, i.name, SUM(pl.packs_packed) as total_on_hand
            FROM packing_log pl
            JOIN items i ON pl.item_id = i.id
            GROUP BY pl.item_id, pl.pack_size
            HAVING total_on_hand != 0
            ORDER BY i.name ASC";

    $stmt = $pdo->query($sql);
    $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
    exit;
}
?>

<div class="mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white border-end-0"><i class="fa fa-search text-muted"></i></span>
                <input type="text" id="inv-search-input" class="form-control border-start-0 ps-0" placeholder="Search items...">
            </div>
        </div>
        <div class="col-md-7 text-end">
            <button class="btn btn-outline-success btn-sm me-1" id="btn-copy-extras" title="Copy extras for WhatsApp">
                <i class="fab fa-whatsapp"></i> Copy Extras
            </button>
            <button class="btn btn-outline-dark btn-sm me-1" id="btn-print-inventory">
                <i class="fa fa-print"></i> Print List
            </button>
            <small class="text-muted ms-2">Lines: <strong id="visible-count"><?= count($inventory) ?></strong></small>
        </div>
    </div>
</div>

<?php if (empty($inventory)): ?>
    <div class="alert alert-info text-center">No packed inventory found on shelves.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover border" id="all-inventory-sort-table">
            <thead class="table-info">
                <tr>
                    <th class="sortable" style="cursor:pointer">Item & Pack Size <i class="fa fa-sort ms-1"></i></th>
                    <th class="text-center sortable" style="cursor:pointer">Total on Shelf <i class="fa fa-sort ms-1"></i></th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody id="inventory-table-body">
                <?php foreach ($inventory as $row): 
                    $cleanName = htmlspecialchars($row['name']);
                    $pSize = (float)$row['pack_size'];
                    $displayName = $cleanName . " " . format_inv_val($pSize) . "'s";
                    $onHand = (float)$row['total_on_hand'];
                    
                    $key = $row['item_id'] . '_' . intval($pSize);
                    $required = $active_reqs[$key] ?? 0;
                    $extra = $onHand - $required;
                    $allocStr = isset($allocation_details[$key]) ? implode('; ', $allocation_details[$key]) : 'No pending orders';
                ?>
                    <tr class="inv-row" 
                        data-item="<?= $cleanName ?>" 
                        data-size="<?= format_inv_val($pSize) ?>"
                        data-qty="<?= format_inv_val($onHand) ?>" 
                        data-extra="<?= $extra > 0 ? format_inv_val($extra) : 0 ?>"
                        data-alloc="<?= htmlspecialchars($allocStr) ?>">
                        <td class="align-middle inv-item-name">
                            <strong><?= $displayName ?></strong>
                            <?php if ($extra > 0): ?>
                                <div class="text-warning x-small fw-bold">
                                    <i class="fa fa-exclamation-circle"></i> <?= format_inv_val($extra) ?> extra
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center align-middle">
                            <span class="fs-5 fw-bold <?= $onHand > 0 ? 'text-success' : 'text-danger' ?>">
                                <?= format_inv_val($onHand) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-danger btn-trigger-break" data-item-id="<?= $row['item_id'] ?>" data-pack-size="<?= $row['pack_size'] ?>" data-item-name="<?= $displayName ?>" data-max="<?= $onHand ?>"><i class="fa fa-box-open"></i></button>
                                <button class="btn btn-warning btn-trigger-repack" data-item-id="<?= $row['item_id'] ?>" data-pack-size="<?= $row['pack_size'] ?>" data-item-name="<?= $displayName ?>" data-max="<?= $onHand ?>"><i class="fa fa-sync-alt"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="modal fade" id="break-qty-modal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content shadow-lg border-danger">
            <div class="modal-header bg-danger text-white p-2">
                <h6 class="modal-title">Break Packs</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="break-item-name" class="small fw-bold mb-2 text-danger"></p>
                <label class="small">How many to break? (Max: <span id="break-max-val"></span>)</label>
                <input type="number" id="break-qty-input" class="form-control form-control-lg text-center" value="1">
                <button type="button" class="btn btn-danger w-100 mt-3" id="btn-confirm-break">Confirm Break</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let activeData = {};

    // 1. WhatsApp Copy Feature (Extras Only)
    $('#btn-copy-extras').on('click', function() {
        let text = "*Ready In-Stock (Extra Packs):*\n\n";
        let found = false;
        $('.inv-row').each(function() {
            let extra = parseFloat($(this).data('extra'));
            if (extra > 0) {
                text += "• " + $(this).data('item') + " " + $(this).data('size') + "'s x " + extra + " Packs\n";
                found = true;
            }
        });
        if (!found) {
            alert("No extra packs currently available.");
            return;
        }
        text += "\n_Message us to claim these!_";
        navigator.clipboard.writeText(text).then(() => {
            let btn = $(this);
            btn.html('<i class="fa fa-check"></i> Copied!').addClass('btn-success text-white').removeClass('btn-outline-success');
            setTimeout(() => btn.html('<i class="fab fa-whatsapp"></i> Copy Extras').addClass('btn-outline-success').removeClass('btn-success text-white'), 2000);
        });
    });

    // 2. Print Printable List (New Tab)
    $('#btn-print-inventory').on('click', function() {
        let printWindow = window.open('', '_blank');
        let content = `<html><head><title>Inventory List</title><style>
            body { font-family: sans-serif; padding: 20px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
            th { background: #f4f4f4; }
            .alloc { font-size: 0.8em; color: #666; font-style: italic; }
        </style></head><body>
        <h2>Shelved Inventory Audit - ${new Date().toLocaleDateString()}</h2>
        <table><thead><tr><th>Item & Size</th><th>Total</th><th>Allocations / Comments</th></tr></thead><tbody>`;

        $('.inv-row').each(function() {
            content += `<tr>
                <td><strong>${$(this).data('item')} ${$(this).data('size')}'s</strong></td>
                <td>${$(this).data('qty')}</td>
                <td><div class="alloc">${$(this).data('alloc')}</div></td>
            </tr>`;
        });

        content += '</tbody></table><script>window.print();<\/script></body></html>';
        printWindow.document.write(content);
        printWindow.document.close();
    });

    // 3. Search Logic
    $('#inv-search-input').on('keyup', function() {
        let val = $(this).val().toLowerCase();
        let count = 0;
        $('.inv-row').each(function() {
            let text = $(this).text().toLowerCase();
            let match = text.indexOf(val) > -1;
            $(this).toggle(match);
            if(match) count++;
        });
        $('#visible-count').text(count);
    });

    // 4. Break Action
    $('.btn-trigger-break').on('click', function() {
        activeData = $(this).data();
        $('#break-item-name').text(activeData.itemName);
        $('#break-max-val').text(activeData.max);
        $('#break-qty-input').val(1);
        $('#break-qty-modal').modal('show');
    });

    $('#btn-confirm-break').on('click', function() {
        const qty = parseFloat($('#break-qty-input').val());
        if (qty <= 0 || qty > activeData.max) return alert("Invalid Quantity");
        $.post('actions.php', {
            action: 'add_packed_packs',
            item_id: activeData.itemId,
            pack_size: activeData.packSize,
            pack_count: (qty * -1),
            comment: 'Broken from All Inventory View'
        }, function(resp) {
            if (resp.success) $('#break-qty-modal').modal('hide');
            else alert(resp.error || 'Update failed');
        }, 'json');
    });

    // Refresh main view when modals close
    $('#break-qty-modal').on('hidden.bs.modal', function () {
        $('#all-inv-body').load('allinvent.php');
    });
});
</script>