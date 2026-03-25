<?php
require_once '../../includes/auth_check.php';
require_once '../../includes/db_connection.php';

// 1. Get Requirements for active orders
$stmt_req = $pdo->query("SELECT oi.item_id, oi.pack_size, SUM(oi.qty) as total_qty 
                         FROM order_items oi 
                         JOIN sales_orders so ON oi.order_id = so.id 
                         WHERE so.delivered = 0 AND so.cancelled = 0 
                         GROUP BY oi.item_id, oi.pack_size");
$active_reqs = [];
while ($r = $stmt_req->fetch(PDO::FETCH_ASSOC)) {
    $key = $r['item_id'] . '_' . intval($r['pack_size']);
    $active_reqs[$key] = $r['total_qty'] / ($r['pack_size'] ?: 1);
}

// 2. Fetch all packed inventory
$sql = "SELECT pl.item_id, pl.pack_size, i.name, SUM(pl.packs_packed) as total_on_hand
        FROM packing_log pl
        JOIN items i ON pl.item_id = i.id
        GROUP BY pl.item_id, pl.pack_size
        HAVING total_on_hand > 0
        ORDER BY i.name ASC";

$stmt = $pdo->query($sql);
$all_inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Filter for Orphans (Extra Stock)
$orphans = [];
foreach ($all_inventory as $row) {
    $key = $row['item_id'] . '_' . intval($row['pack_size']);
    $required = $active_reqs[$key] ?? 0;
    
    // Only show if we have more on hand than what is required
    if ($row['total_on_hand'] > $required) {
        $row['extra_qty'] = $row['total_on_hand'] - $required;
        $orphans[] = $row;
    }
}

function format_inv_val($val) {
    return (abs($val - round($val)) < 0.000001) ? (int)round($val) : round($val, 2);
}
?>

<div class="mb-3">
    <div class="alert alert-warning py-2 mb-0 small">
        <i class="fa fa-info-circle me-1"></i> These packs are <strong>unallocated</strong> (from cancellations or over-packing).
    </div>
</div>

<?php if (empty($orphans)): ?>
    <div class="alert alert-success text-center border-dashed">
        <i class="fa fa-check-circle me-2"></i>No orphaned or extra inventory found.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-striped table-hover border" id="orphan-inventory-sort-table">
            <thead class="table-dark">
                <tr>
                    <th class="sortable" style="cursor:pointer">Item & Pack Size <i class="fa fa-sort ms-1"></i></th>
                    <th class="text-center sortable" style="cursor:pointer">Extra Qty <i class="fa fa-sort ms-1"></i></th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orphans as $row): 
                    $displayName = htmlspecialchars($row['name']) . " " . format_inv_val($row['pack_size']) . "'s";
                    $extraAmt = (float)$row['extra_qty'];
                ?>
                    <tr>
                        <td class="align-middle">
                            <strong><?= $displayName ?></strong>
                            <div class="text-muted x-small">Total on shelf: <?= format_inv_val($row['total_on_hand']) ?></div>
                        </td>
                        <td class="text-center align-middle">
                            <span class="badge rounded-pill bg-danger fs-6">
                                <?= format_inv_val($extraAmt) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-danger btn-trigger-break-orphan" 
                                        data-item-id="<?= $row['item_id'] ?>" 
                                        data-pack-size="<?= $row['pack_size'] ?>"
                                        data-item-name="<?= $displayName ?>"
                                        data-max="<?= $row['total_on_hand'] ?>">
                                    Break
                                </button>
                                <button class="btn btn-warning btn-trigger-repack-orphan" 
                                        data-item-id="<?= $row['item_id'] ?>" 
                                        data-pack-size="<?= $row['pack_size'] ?>"
                                        data-item-name="<?= $displayName ?>"
                                        data-max="<?= $row['total_on_hand'] ?>">
                                    Repack
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<div class="modal fade" id="break-orphan-modal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" style="z-index: 1090;">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content shadow-lg border-danger">
            <div class="modal-header bg-danger text-white p-2">
                <h6 class="modal-title">Break Extra Pack</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="orphan-break-name" class="small fw-bold mb-2 text-danger"></p>
                <label class="small">Qty to break (Max: <span id="orphan-break-max"></span>):</label>
                <input type="number" id="orphan-break-qty" class="form-control form-control-lg text-center" value="1">
                <button type="button" class="btn btn-danger w-100 mt-3" id="btn-confirm-break-orphan">Break to Stock</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="repack-orphan-modal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" style="z-index: 1090;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-warning">
            <div class="modal-header bg-warning p-2">
                <h6 class="modal-title fw-bold"><i class="fa fa-sync-alt"></i> Repack Extra Stock</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small p-2 mb-3">
                    <strong>Source:</strong> <span id="orphan-repack-name"></span>
                    <div class="mt-1 d-flex align-items-center">
                        <span class="me-2 text-danger fw-bold">Qty to open:</span>
                        <input type="number" id="orphan-repack-src-qty" class="form-control form-control-sm text-center border-danger" style="width:70px;" value="1">
                        <span class="ms-2 text-muted x-small">(Available: <span id="orphan-repack-max"></span>)</span>
                    </div>
                </div>

                <div id="orphan-repack-targets">
                    <div class="orphan-repack-row row g-1 mb-2 align-items-end">
                        <div class="col-5">
                            <label class="x-small text-muted">New Pack Size</label>
                            <input type="number" class="form-control form-control-sm o-tar-size">
                        </div>
                        <div class="col-5">
                            <label class="x-small text-muted">No. of Packs</label>
                            <input type="number" class="form-control form-control-sm o-tar-qty">
                        </div>
                        <div class="col-2">
                            <button class="btn btn-sm btn-outline-danger btn-remove-o-row" style="display:none;"><i class="fa fa-times"></i></button>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 mt-1" id="btn-add-o-row">
                    <i class="fa fa-plus-circle"></i> Add Size
                </button>
                <button type="button" class="btn btn-warning w-100 fw-bold mt-3" id="btn-confirm-repack-orphan">Execute Repack</button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    let orphanData = {};

    // 1. ESC KEY FIX
    $(document).off('keydown.orinvent').on('keydown.orinvent', function(e) {
        if (e.key === "Escape") {
            const openSub = $('#break-orphan-modal.show, #repack-orphan-modal.show');
            if (openSub.length > 0) {
                openSub.last().modal('hide');
                e.stopImmediatePropagation();
            }
        }
    });

    // 2. LIVE REFRESH (No page reload)
    $('#break-orphan-modal, #repack-orphan-modal').on('hidden.bs.modal', function () {
        $('#orphaned-inv-body').load('orinvent.php');
        if (typeof window.refreshMainPackingTable === 'function') {
            window.refreshMainPackingTable();
        }
    });

    // --- BREAK ---
    $('.btn-trigger-break-orphan').on('click', function() {
        orphanData = $(this).data();
        $('#orphan-break-name').text(orphanData.itemName);
        $('#orphan-break-max').text(orphanData.max);
        $('#orphan-break-qty').val(1);
        $('#break-orphan-modal').modal('show');
    });

    $('#btn-confirm-break-orphan').on('click', function() {
        const qty = parseFloat($('#orphan-break-qty').val());
        if (qty <= 0 || qty > orphanData.max) return alert("Invalid Qty");

        $.post('actions.php', {
            action: 'add_packed_packs',
            item_id: orphanData.itemId,
            pack_size: orphanData.packSize,
            pack_count: (qty * -1),
            comment: 'Broken Orphan Stock'
        }, function(resp) {
            if (resp.success) $('#break-orphan-modal').modal('hide');
        }, 'json');
    });

    // --- REPACK ---
    $('.btn-trigger-repack-orphan').on('click', function() {
        orphanData = $(this).data();
        $('#orphan-repack-name').text(orphanData.itemName);
        $('#orphan-repack-max').text(orphanData.max);
        $('#orphan-repack-src-qty').val(1);
        $('#orphan-repack-targets').html(`
            <div class="orphan-repack-row row g-1 mb-2 align-items-end">
                <div class="col-5">
                    <label class="x-small text-muted">New Pack Size</label>
                    <input type="number" class="form-control form-control-sm o-tar-size">
                </div>
                <div class="col-5">
                    <label class="x-small text-muted">No. of Packs</label>
                    <input type="number" class="form-control form-control-sm o-tar-qty">
                </div>
                <div class="col-2"><button class="btn btn-sm btn-outline-danger btn-remove-o-row" style="display:none;"><i class="fa fa-times"></i></button></div>
            </div>
        `);
        $('#repack-orphan-modal').modal('show');
    });

    $('#btn-add-o-row').on('click', function() {
        const row = $('.orphan-repack-row').first().clone();
        row.find('input').val('');
        row.find('.btn-remove-o-row').show();
        $('#orphan-repack-targets').append(row);
    });

    $(document).on('click', '.btn-remove-o-row', function() { $(this).closest('.orphan-repack-row').remove(); });

    $('#btn-confirm-repack-orphan').on('click', async function() {
        const srcQty = parseFloat($('#orphan-repack-src-qty').val());
        let targets = [];
        $('.orphan-repack-row').each(function() {
            const size = parseFloat($(this).find('.o-tar-size').val());
            const qty = parseFloat($(this).find('.o-tar-qty').val());
            if (size > 0 && qty > 0) targets.push({ size, qty });
        });

        if (targets.length === 0 || srcQty <= 0) return alert("Check inputs");

        const res = await $.post('actions.php', {
            action: 'add_packed_packs',
            item_id: orphanData.itemId,
            pack_size: orphanData.packSize,
            pack_count: (srcQty * -1),
            comment: `Orphan Repack: Opened ${orphanData.packSize}`
        });

        if (res.success) {
            for (let t of targets) {
                await $.post('actions.php', {
                    action: 'add_packed_packs',
                    item_id: orphanData.itemId,
                    pack_size: t.size,
                    pack_count: t.qty,
                    comment: `Orphan Repack: Split to ${t.size}`
                });
            }
            $('#repack-orphan-modal').modal('hide');
        }
    });

    // Sorting
    $('#orphan-inventory-sort-table th.sortable').on('click', function() {
        const table = $(this).parents('table').eq(0);
        let rows = table.find('tr:gt(0)').toArray().sort((a,b) => {
            let valA = $(a).children('td').eq($(this).index()).text().trim();
            let valB = $(b).children('td').eq($(this).index()).text().trim();
            return $.isNumeric(valA) && $.isNumeric(valB) ? valA - valB : valA.localeCompare(valB);
        });
        this.asc = !this.asc;
        if (!this.asc) rows.reverse();
        rows.forEach(r => table.append(r));
    });
});
</script>