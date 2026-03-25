$(document).ready(function () {
    // Add Packed Packs button
    $('#btn-add-pack').on('click', function () { openAddPackModal(); });

    // Scan Packs button
    $('#btn-scan-pack').on('click', function () { window.open('/entities/inventory/packing_scan.php', '_blank'); });

    /**
     * --- GLOBAL REFRESH LISTENER ---
     * Listens for when inventory sub-modals close and triggers the AJAX refresh.
     * The use of .always() in the refresh function ensures the spinner is hidden 
     * even if the request fails.
     */
    $(document).on('hidden.bs.modal', '#orphaned-inv-modal, #all-inv-modal', function () {
        if (typeof window.refreshMainPackingTable === 'function') {
            window.refreshMainPackingTable();
        }
    });

    function openAddPackModal() {
        const modalEl = document.getElementById('add-pack-modal');
        const modalInst = bootstrap.Modal.getOrCreateInstance(modalEl);
        modalInst.show();

        let $sel = $('#pack-item-select');
        if ($sel[0].tomselect) { $sel[0].tomselect.destroy(); }
        $sel.empty().append('<option value="">Loading...</option>');

        let options = [];
        $('#packing-log-table tbody tr').each(function () {
            let item_id = $(this).find('.row-item-id').val();
            let pack_size = $(this).find('.row-pack-size').val();
            // Optimized label selection to ignore sub-divs/categories
            let label = $(this).find('td').eq(1).find('strong').text().trim() || $(this).find('td').eq(1).contents().first().text().trim();
            
            if (item_id && pack_size) {
                options.push({ value: item_id + '_' + pack_size, text: label });
            }
        });
        
        $sel.empty().append('<option value="">Select Item & Pack Size</option>');
        options.forEach(function (opt) {
            $sel.append('<option value="' + opt.value + '">' + opt.text + '</option>');
        });

        if (typeof TomSelect !== "undefined") {
            new TomSelect($sel[0], { create: false, sortField: 'text' });
        }
    }

    /**
     * Modal Submit (Live Update)
     * Triggers the full table refresh to account for "Auto-generated" stock entries.
     */
    $('#add-pack-form').on('submit', function (e) {
        e.preventDefault();
        let item_pack = $('#pack-item-select').val();
        let pack_count = parseFloat($('#pack-count').val()); 
        let comment = $('#pack-comment').val();

        if (!item_pack || isNaN(pack_count)) return;
        let [item_id, pack_size] = item_pack.split('_');

        $.post('actions.php', { action: 'add_packed_packs', item_id, pack_size, pack_count, comment }, function (resp) {
            if (resp.success) {
                // Refresh the whole table to catch auto-generated stock or surplus shifts
                if (typeof window.refreshMainPackingTable === 'function') {
                    window.refreshMainPackingTable();
                } else {
                    updateRowUI(item_id, pack_size, resp);
                }
                
                const modalEl = document.getElementById('add-pack-modal');
                bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                $('#add-pack-form')[0].reset();
            } else { 
                alert(resp.error || 'Error!'); 
            }
        }, 'json');
    });

    /**
     * Inline Button (Live Update)
     * Updates specific rows; refreshes full table on negative values (potential orphans).
     */
    $(document).on('click', '.btn-update-pack', function () {
        let $tr = $(this).closest('tr');
        let item_id = $tr.find('.row-item-id').val();
        let pack_size = $tr.find('.row-pack-size').val();
        let pack_count = parseFloat($tr.find('.packs-input').val());

        if (!item_id || !pack_size || isNaN(pack_count)) return;

        $.post('actions.php', { action: 'add_packed_packs', item_id, pack_size, pack_count }, function (resp) {
            if (resp.success) {
                updateRowUI(item_id, pack_size, resp, $tr);
                $tr.find('.packs-input').val('');
                
                // If quantity was removed, refresh to check for orphaned status changes
                if(pack_count < 0 && typeof window.refreshMainPackingTable === 'function') {
                    window.refreshMainPackingTable();
                }
            } else { 
                alert(resp.error || 'Update failed'); 
            }
        }, 'json');
    });

    /**
     * Helper to update table row without page refresh
     */
    function updateRowUI(item_id, pack_size, data, $row) {
        if (!$row) {
            $('#packing-log-table tbody tr').each(function() {
                if ($(this).find('.row-item-id').val() == item_id && $(this).find('.row-pack-size').val() == pack_size) {
                    $row = $(this);
                }
            });
        }

        if ($row) {
            $row.find('.packs-packed-val').text(data.new_packed);
            let $badge = $row.find('.badge');
            let symbol = data.new_surplus > 0 ? '+' : '';
            $badge.text(symbol + data.new_surplus);
            
            // Sync colors with server-side badge logic
            $badge.removeClass('badge-surplus badge-settled badge-outstanding').addClass(data.badge_class);
            
            // Visual feedback
            $row.addClass('table-success');
            setTimeout(() => $row.removeClass('table-success'), 1000);
        }
    }
});