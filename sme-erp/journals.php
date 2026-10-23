<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'accountant']);
$pageTitle = 'Journal Entries';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'record') {
            $date = post_string('entry_date', 10);
            $ref = post_string('entry_reference', 50);
            $accounts = $_POST['account_id'] ?? [];
            $descriptions = $_POST['line_description'] ?? [];
            $debits = $_POST['debit'] ?? [];
            $credits = $_POST['credit'] ?? [];

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Enter a valid date.');
            if ($ref === '') throw new RuntimeException('Reference is required.');
            if (count($accounts) < 2) throw new RuntimeException('A journal entry must have at least two lines.');

            $totalDebit = 0;
            $totalCredit = 0;
            $lines = [];

            foreach ($accounts as $i => $accId) {
                $accId = (int)$accId;
                $desc = trim((string)($descriptions[$i] ?? ''));
                $d = (float)($debits[$i] ?? 0);
                $c = (float)($credits[$i] ?? 0);

                if ($accId < 1) continue;
                if ($desc === '') throw new RuntimeException('Description is required for all lines.');
                if ($d > 0 && $c > 0) throw new RuntimeException('A line cannot have both a debit and a credit.');
                if ($d <= 0 && $c <= 0) throw new RuntimeException('A line must have either a debit or a credit greater than zero.');

                $totalDebit += $d;
                $totalCredit += $c;
                $lines[] = ['account_id' => $accId, 'description' => $desc, 'debit' => $d, 'credit' => $c];
            }

            if (abs($totalDebit - $totalCredit) > 0.01) {
                throw new RuntimeException('Debits (' . number_format($totalDebit, 2) . ') must equal Credits (' . number_format($totalCredit, 2) . ').');
            }

            db()->beginTransaction();
            $stmt = db()->prepare('INSERT INTO journal_entries (entry_reference, account_id, entry_date, description, debit, credit, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            foreach ($lines as $line) {
                $stmt->execute([$ref, $line['account_id'], $date, $line['description'], $line['debit'], $line['credit'], current_user()['id']]);
            }
            db()->commit();
            flash('success', 'Journal entry recorded successfully.');
            redirect('journals.php');
        }
    } catch (RuntimeException $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        if (db()->inTransaction()) db()->rollBack();
        $error = 'The journal entry could not be saved.';
    }
}

$journals = db()->query('SELECT entry_reference, entry_date, SUM(debit) as total_debit, COUNT(*) as line_count, description 
    FROM journal_entries 
    GROUP BY entry_reference, entry_date 
    ORDER BY MAX(id) DESC LIMIT 100')->fetchAll();
$activeAccounts = db()->query('SELECT id, account_code, account_name FROM accounts WHERE is_active=1 ORDER BY account_code')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>

<section class="panel" style="margin-bottom:20px">
    <div class="panel-heading"><div><h2>Record Journal Entry</h2><p>Debits must equal Credits.</p></div></div>
    <?php if (count($activeAccounts) < 2): ?>
        <div class="empty-state compact"><strong>Need more accounts</strong><p>Create at least two active accounts in the Chart of Accounts first.</p></div>
    <?php else: ?>
    <form method="post" id="journal-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="record">
        <div class="field-grid" style="max-width:600px;margin-bottom:20px">
            <div><label for="entry_date">Date *</label><input id="entry_date" name="entry_date" type="date" required value="<?= date('Y-m-d') ?>"></div>
            <div><label for="entry_reference">Reference *</label><input id="entry_reference" name="entry_reference" required placeholder="e.g. JV-001"></div>
        </div>
        
        <div class="journal-row" style="font-weight:700;font-size:10px;color:#8792a3;margin-bottom:4px">
            <div>ACCOUNT</div><div>DESCRIPTION</div><div>DEBIT (KSh)</div><div>CREDIT (KSh)</div><div></div>
        </div>
        
        <div id="journal-lines">
            <!-- Default 2 lines -->
            <div class="journal-row">
                <select name="account_id[]" required>
                    <option value="">Select Account</option>
                    <?php foreach ($activeAccounts as $acc): ?><option value="<?= (int)$acc['id'] ?>"><?= e($acc['account_code'].' - '.$acc['account_name']) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="line_description[]" placeholder="Line item description" required>
                <input type="number" name="debit[]" step="0.01" min="0" placeholder="0.00" oninput="updateTotals()">
                <input type="number" name="credit[]" step="0.01" min="0" placeholder="0.00" oninput="updateTotals()">
                <button type="button" class="btn-remove" onclick="removeRow(this)">×</button>
            </div>
            <div class="journal-row">
                <select name="account_id[]" required>
                    <option value="">Select Account</option>
                    <?php foreach ($activeAccounts as $acc): ?><option value="<?= (int)$acc['id'] ?>"><?= e($acc['account_code'].' - '.$acc['account_name']) ?></option><?php endforeach; ?>
                </select>
                <input type="text" name="line_description[]" placeholder="Line item description" required>
                <input type="number" name="debit[]" step="0.01" min="0" placeholder="0.00" oninput="updateTotals()">
                <input type="number" name="credit[]" step="0.01" min="0" placeholder="0.00" oninput="updateTotals()">
                <button type="button" class="btn-remove" onclick="removeRow(this)">×</button>
            </div>
        </div>
        
        <button type="button" class="button button-quiet button-small" onclick="addRow()" style="margin-top:10px">+ Add Line</button>
        
        <div class="journal-summary">
            <span>Totals:</span>
            <span>Debit: <span id="total-debit">0.00</span></span>
            <span>Credit: <span id="total-credit">0.00</span></span>
        </div>
        
        <button class="button button-primary" type="submit" style="margin-top:20px">Save Journal Entry</button>
    </form>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-heading"><div><h2>Recent Journals</h2><p>Latest 100 entries</p></div></div>
    <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Lines</th><th>Amount</th></tr></thead>
        <tbody>
        <?php foreach ($journals as $j): ?>
            <tr>
                <td><?= e(format_day($j['entry_date'])) ?></td>
                <td><strong><a href="journal.php?ref=<?= urlencode($j['entry_reference']) ?>"><?= e($j['entry_reference']) ?></a></strong></td>
                <td><?= e($j['description']) ?>...</td>
                <td><?= (int)$j['line_count'] ?></td>
                <td><?= e(money($j['total_debit'])) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$journals): ?><tr><td colspan="5" class="empty-cell">No journal entries yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>

<script>
function addRow() {
    var container = document.getElementById('journal-lines');
    var row = container.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach(i => i.value = '');
    row.querySelector('select').value = '';
    container.appendChild(row);
}
function removeRow(btn) {
    var container = document.getElementById('journal-lines');
    if (container.children.length > 2) {
        btn.parentElement.remove();
        updateTotals();
    }
}
function updateTotals() {
    var debits = document.querySelectorAll('input[name="debit[]"]');
    var credits = document.querySelectorAll('input[name="credit[]"]');
    var tD = 0, tC = 0;
    debits.forEach(i => tD += parseFloat(i.value) || 0);
    credits.forEach(i => tC += parseFloat(i.value) || 0);
    document.getElementById('total-debit').textContent = tD.toFixed(2);
    document.getElementById('total-credit').textContent = tC.toFixed(2);
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>