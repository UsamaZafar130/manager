// Inventory Management JS - Live Update Version
$(document).ready(function () {
    // --- 1. SELECTION & NAVIGATION ---
    $('#select-all-orders').on('change', function() {
        $('.order-checkbox').prop('checked', this.checked);
    });
    $(document).on('change', '.order-checkbox', function() {
        let allChecked = $('.order-checkbox').length === $('.order-checkbox:checked').length;
        $('#select-all-orders').prop('checked', allChecked);
    });

    $('#btn-stock-req').on('click', function() {
        let orderIds = $('.order-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if (orderIds.length === 0) { alert('Please select at least one order!'); return; }
        window.open('stock_requirements.php?order_ids=' + orderIds.join(','), '_blank');
    });

    $('#btn-packing-log').on('click', function() {
        let orderIds = $('.order-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if (orderIds.length === 0) { alert('Please select at least one order!'); return; }
        window.open('packing.php?order_ids=' + orderIds.join(','), '_blank');
    });

    $('#btn-packing-labels').on('click', function() {
        let orderIds = $('.order-checkbox:checked').map(function(){ return $(this).val(); }).get();
        if (orderIds.length === 0) { alert('Please select at least one order!'); return; }
        window.open('packing_labels.php?order_ids=' + orderIds.join(','), '_blank');
    });

    // --- 2. MODAL TRIGGERS ---
    $('#btn-add-stock').on('click', function() {
        if (typeof window.showFloatingAddStockModal === 'function') {
            window.showFloatingAddStockModal();
        }
    });

    $('#btn-excess-stock').on('click', function() {
        if (typeof window.showExcessStockModal === 'function') {
            window.showExcessStockModal();
        }
    });

    // --- 3. LIVE FEEDBACK HELPER ---
    function showSuccessTick() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'success',
                title: 'Updated',
                showConfirmButton: false,
                timer: 1000,
                position: 'center',
                backdrop: false,
                width: '200px'
            });
        }
    }

    // --- 4. INLINE LIVE UPDATE (Stock Requirements Page) ---
    $(document).on('click', '.btn-update-stock', function() {
        let $tr = $(this).closest('tr');
        let item_id = $tr.find('.row-item-id').val();
        let qtyToAdd = parseFloat($tr.find('.manufactured-input').val());

        // Get values from table for math
        let totalRequired = parseFloat($tr.find('td:nth-child(4)').text().replace(/,/g, ''));
        
        if (!item_id || isNaN(qtyToAdd) || qtyToAdd <= 0) {
            alert('Please enter a positive quantity.');
            return;
        }

        $.post('actions.php', {action:'update_manufactured', item_id, qty: qtyToAdd}, function(resp) {
            if (resp.success) {
                // 1. Show the tick
                showSuccessTick();

                // 2. Update "Manufactured" text (resp.new_total must be sent by actions.php)
                $tr.find('.manufactured-val').text(resp.new_total.toLocaleString(undefined, {minimumFractionDigits: 2}));

                // 3. Recalculate Surplus/Shortfall Badge
                let newDiff = totalRequired - resp.new_total;
                let $badge = $tr.find('.badge');
                
                // Update badge color & symbol
                $badge.removeClass('badge-surplus badge-settled badge-outstanding');
                if (newDiff < 0) {
                    $badge.addClass('badge-surplus').text('+' + Math.abs(newDiff).toFixed(2));
                } else if (newDiff > 0) {
                    $badge.addClass('badge-outstanding').text('-' + Math.abs(newDiff).toFixed(2));
                } else {
                    $badge.addClass('badge-settled').text('0.00');
                }

                // 4. Reset input and flash row
                $tr.find('.manufactured-input').val('0');
                $tr.addClass('table-success');
                setTimeout(() => $tr.removeClass('table-success'), 1200);

            } else {
                alert(resp.error || 'Update failed');
            }
        }, 'json');
    });
});