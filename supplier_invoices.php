<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'procurement']);
$pageTitle = 'Supplier Invoices';
$error = '';

// Catch the URL parameters from the PO page
$prefillPoId = isset($_GET['po_id']) ? (int)$_GET['po_id'] : 0;
$prefillSupplierId = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;

// Fetch PO Items for Auto-fill
$poItems = [];
if ($prefillPoId) {
    $sql = "SELECT p.name AS description, poi.quantity_ordered AS quantity, poi.unit_cost AS unit_price 
            FROM purchase_order_items poi 
            JOIN products p ON p.id = poi.product_id 
            WHERE poi.purchase_order_id = ?";
    $poItemStmt = db()->prepare($sql);
    $poItemStmt->execute([$prefillPoId]);
    $poItems = $poItemStmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        db()->beginTransaction();
        
        $action = $_POST['action'] ?? 'save_invoice';

        // =====================================================================
        // WORKFLOW ACTION: UPDATE STATUS (Approve / Mark as Paid)
        // =====================================================================
        if ($action === 'update_status') {
            $invId = (int)$_POST['invoice_id'];
            $newStatus = post_string('new_status', 50);
            
            // Fetch invoice details for validation and GL posting
            $invStmt = db()->prepare('SELECT * FROM supplier_invoices WHERE id = ?');
            $invStmt->execute([$invId]);
            $invoice = $invStmt->fetch();

            if (!$invoice) throw new RuntimeException('Invoice not found.');

            if ($newStatus === 'Approved' && $invoice['status'] === 'Draft') {
                
                // 1. Three-Way Matching Validation
                if ($invoice['po_id']) {
                    $billedStmt = db()->prepare("SELECT SUM(total_amount) FROM supplier_invoices WHERE po_id = ? AND status != 'Draft' AND id != ?");
                    $billedStmt->execute([$invoice['po_id'], $invId]);
                    $alreadyBilled = (float) $billedStmt->fetchColumn();
                    
                    $poStmt = db()->prepare("SELECT total_amount FROM purchase_orders WHERE id = ?");
                    $poStmt->execute([$invoice['po_id']]);
                    $poTotal = (float) $poStmt->fetchColumn();

                    if (($alreadyBilled + $invoice['total_amount']) > $poTotal) {
                        throw new RuntimeException("Discrepancy Error: This invoice exceeds the approved Purchase Order amount. Cannot approve.");
                    }
                }

                // 2. Post to General Ledger
                $apStmt = db()->query("SELECT id FROM accounts WHERE account_name LIKE '%accounts payable%' AND is_active = 1 LIMIT 1");  
                $apAccountId = $apStmt->fetchColumn();

                if (!$apAccountId) throw new RuntimeException('System Error: Accounts Payable account not found.');

                $entryRef = "INV-" . $invId;
                $entryDesc = "Supplier Invoice: " . $invoice['invoice_number'];
                $userId = current_user()['id'];

                $jeStmt = db()->prepare('INSERT INTO journal_entries (entry_reference, account_id, entry_date, description, debit, credit, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                
                // Fetch items to get the GL accounts to debit
                $itemStmt = db()->prepare('SELECT * FROM supplier_invoice_items WHERE invoice_id = ?');
                $itemStmt->execute([$invId]);
                $items = $itemStmt->fetchAll();

                // A. Debit Expenses/Assets
                foreach ($items as $item) {
                    $jeStmt->execute([$entryRef, $item['account_id'], $invoice['invoice_date'], $entryDesc, $item['total_price'], 0.00, $userId]);
                }

                // B. Credit Accounts Payable
                $jeStmt->execute([$entryRef, $apAccountId, $invoice['invoice_date'], $entryDesc, 0.00, $invoice['total_amount'], $userId]);
            }

            // Update the status
            db()->prepare('UPDATE supplier_invoices SET status = ? WHERE id = ?')->execute([$newStatus, $invId]);
            
            db()->commit();
            flash('success', 'Invoice marked as ' . $newStatus);
            redirect('supplier_invoices.php');
        }

        // =====================================================================
        // DEFAULT ACTION: SAVE NEW INVOICE AS DRAFT
        // =====================================================================
        if ($action === 'save_invoice') {
            $supplierId = (int) $_POST['supplier_id'];
            $poId = !empty($_POST['po_id']) ? (int) $_POST['po_id'] : null;
            $invNumber = post_string('invoice_number', 100);
            $invDate = post_string('invoice_date', 20);
            $dueDate = post_string('due_date', 20);
            
            if (!$supplierId || !$invNumber || !$invDate) {
                throw new RuntimeException('Supplier, Invoice Number, and Date are required.');
            }

            // Insert as Draft
            $stmt = db()->prepare('INSERT INTO supplier_invoices (supplier_id, po_id, invoice_number, invoice_date, due_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$supplierId, $poId, $invNumber, $invDate, $dueDate, 'Draft', current_user()['id']]);
            $invoiceId = (int) db()->lastInsertId();

            $totalAmount = 0;
            $descriptions = $_POST['item_description'] ?? [];
            $quantities = $_POST['item_quantity'] ?? [];
            $prices = $_POST['item_price'] ?? [];
            $accounts = $_POST['item_account'] ?? [];

            $itemStmt = db()->prepare('INSERT INTO supplier_invoice_items (invoice_id, description, quantity, unit_price, total_price, account_id) VALUES (?, ?, ?, ?, ?, ?)');
            
            foreach ($descriptions as $index => $desc) {
                if (trim($desc) === '') continue;
                
                $qty = (float) $quantities[$index];
                $price = (float) $prices[$index];
                $lineTotal = $qty * $price;
                $accountId = (int) $accounts[$index];
                
                $itemStmt->execute([$invoiceId, $desc, $qty, $price, $lineTotal, $accountId]);
                $totalAmount += $lineTotal;
            }

            db()->prepare('UPDATE supplier_invoices SET total_amount = ? WHERE id = ?')->execute([$totalAmount, $invoiceId]);

            db()->commit();
            flash('success', 'Invoice saved as Draft. Awaiting approval.');
            redirect('supplier_invoices.php');
        }

    } catch (Exception $e) {
        db()->rollBack();
        $error = $e->getMessage();
    }
}

$suppliers = db()->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll();
$accounts = db()->query("SELECT id, account_code, account_name FROM accounts WHERE account_type IN ('expense', 'asset') AND is_active = 1 ORDER BY account_code")->fetchAll();
$invoices = db()->query("SELECT si.*, s.name as supplier_name FROM supplier_invoices si JOIN suppliers s ON s.id = si.supplier_id ORDER BY si.created_at DESC")->fetchAll();

require __DIR__ . '/includes/header.php';
?>

<?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="split-layout">
    <article class="panel form-panel" style="flex: 3;">
        <div class="panel-heading"><h2>Record Supplier Invoice</h2></div>
        
        <form method="post" class="form-stack" id="invoice-form" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_invoice">
            
            <div class="field-grid">
                <div>
                    <label>Supplier *</label>
                    <select name="supplier_id" required style="width: 100%;">
                        <option value="">Select Supplier</option>
                        <?php foreach ($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>" <?= ($sup['id'] == $prefillSupplierId) ? 'selected' : '' ?>>
                                <?= e($sup['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Supplier Invoice # *</label>
                    <input type="text" name="invoice_number" required placeholder="e.g. INV-2023-001" style="width: 100%;">
                </div>
            </div>
            
            <div class="field-grid three-cols">
                <div>
                    <label>PO Reference (Optional)</label>
                    <input type="number" name="po_id" placeholder="PO ID" value="<?= $prefillPoId ?: '' ?>" <?= $prefillPoId ? 'readonly' : '' ?> style="width: 100%; background: <?= $prefillPoId ? '#f3f4f6' : '#fff' ?>;">
                </div>
                <div>
                    <label>Invoice Date *</label>
                    <input type="date" name="invoice_date" required value="<?= date('Y-m-d') ?>" style="width: 100%;">
                </div>
                <div>
                    <label>Due Date *</label>
                    <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('+30 days')) ?>" style="width: 100%;">
                </div>
            </div>

            <hr style="margin: 20px 0; border: 0; border-top: 1px solid #eee;">
            <h3>Line Items</h3>
            
            <div class="table-wrap" style="overflow-x: auto; width: 100%; padding-bottom: 10px;">
                <table class="table" id="items-table" style="width: 100%; min-width: 650px;">
                    <thead>
                        <tr>
                            <th style="width: 25%;">Description</th>
                            <th style="width: 8%;">Qty</th>
                            <th style="width: 12%;">Unit Price</th>
                            <th style="width: 35%;">GL Account (Debit) *</th>
                            <th style="width: 10%;">Total</th>
                            <th style="width: 5%;"></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            
            <div style="margin-top: 10px; margin-bottom: 20px;">
                <button type="button" class="button button-secondary" onclick="addRow()">+ Add Line Item</button>
            </div>
            
            <button class="button button-primary" type="submit" style="width: 100%;">Save Invoice as Draft</button>
        </form>
    </article>

    <article class="panel list-panel" style="flex: 2;">
        <div class="panel-heading"><h2>Recent Invoices & Workflow</h2></div>
        <div class="table-wrap">
            <table class="table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Supplier</th>
                        <th>Amount</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="4" style="text-align:center; padding: 20px; color:#888;">No invoices yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td>
                                <strong><?= e($inv['invoice_number']) ?></strong><br>
                                <span class="pill pill-<?= $inv['status'] === 'Paid' ? 'success' : ($inv['status'] === 'Approved' ? 'info' : 'warning') ?>" style="font-size: 10px; padding: 2px 6px;">
                                    <?= e($inv['status']) ?>
                                </span>
                            </td>
                            <td><?= e($inv['supplier_name']) ?></td>
                            <td><?= money($inv['total_amount']) ?></td>
                            <td style="text-align: right;">
                                <form method="post" style="display: inline-block; margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                    
                                    <?php if ($inv['status'] === 'Draft'): ?>
                                        <button type="submit" name="new_status" value="Approved" class="button button-primary" style="padding: 4px 10px; font-size: 11px;">Approve</button>
                                    
                                    <?php elseif ($inv['status'] === 'Approved'): ?>
                                        <button type="submit" name="new_status" value="Paid" class="button button-secondary" style="padding: 4px 10px; font-size: 11px; color: #059669; border-color: #059669;">Pay</button>
                                    
                                    <?php else: ?>
                                        <span style="color: #059669; font-weight: bold; font-size: 11px;">✓ Paid</span>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </article>
</section>

<script>
const accountsHtml = `<?php 
    $opts = '<option value="">Select Account</option>';
    foreach ($accounts as $acc) { 
        $opts .= '<option value="'.$acc['id'].'">'.e($acc['account_code'].' - '.$acc['account_name']).'</option>'; 
    }
    echo addslashes($opts);
?>`;

const poItemsData = <?= json_encode($poItems) ?>;

function addRow(data = null) {
    const tbody = document.querySelector('#items-table tbody');
    const tr = document.createElement('tr');
    
    const desc = data ? data.description : '';
    const qty = data ? data.quantity : '1';
    const price = data ? data.unit_price : '0.00';

    tr.innerHTML = `
        <td><input type="text" name="item_description[]" required value="${desc}" placeholder="Item description" style="width: 100%; box-sizing: border-box; padding: 8px;"></td>
        <td><input type="number" name="item_quantity[]" step="0.01" required value="${qty}" oninput="calcRow(this)" style="width: 100%; box-sizing: border-box; padding: 8px;"></td>
        <td><input type="number" name="item_price[]" step="0.01" required value="${price}" oninput="calcRow(this)" style="width: 100%; box-sizing: border-box; padding: 8px;"></td>
        <td><select name="item_account[]" required style="width: 100%; box-sizing: border-box; padding: 8px;">${accountsHtml}</select></td>
        <td class="line-total" style="vertical-align: middle; font-weight: bold;">0.00</td>
        <td style="vertical-align: middle; text-align: center;"><button type="button" onclick="this.closest('tr').remove()" style="color:#e11d48; border:none; background:none; cursor:pointer; font-size: 16px;" title="Remove Row">✕</button></td>
    `;
    tbody.appendChild(tr);
    
    if (data) {
        calcRow(tr.querySelector('input[name="item_quantity[]"]'));
    }
}

function calcRow(input) {
    const tr = input.closest('tr');
    const qty = parseFloat(tr.querySelector('input[name="item_quantity[]"]').value) || 0;
    const price = parseFloat(tr.querySelector('input[name="item_price[]"]').value) || 0;
    tr.querySelector('.line-total').textContent = (qty * price).toFixed(2);
}

document.addEventListener('DOMContentLoaded', () => {
    if (poItemsData && poItemsData.length > 0) {
        poItemsData.forEach(item => {
            addRow(item);
        });
    } else {
        addRow(); 
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>