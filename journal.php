<<<<<<< HEAD
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'accountant']);
$pageTitle = 'Journal Entry Detail';

$ref = trim((string) ($_GET['ref'] ?? ''));
if ($ref === '') redirect('journals.php');

$stmt = db()->prepare('SELECT je.*, a.account_code, a.account_name, u.full_name 
    FROM journal_entries je 
    JOIN accounts a ON a.id = je.account_id 
    LEFT JOIN users u ON u.id = je.created_by 
    WHERE je.entry_reference = ? ORDER BY je.id');
$stmt->execute([$ref]);
$lines = $stmt->fetchAll();

if (!$lines) {
    flash('error', 'Journal entry not found.');
    redirect('journals.php');
}

$totalDebit = 0; $totalCredit = 0;
require __DIR__ . '/includes/header.php';
?>
<section class="order-banner">
    <div>
        <span class="eyebrow">JOURNAL ENTRY</span>
        <h2><?= e($ref) ?></h2>
        <p>Recorded on <?= e(format_day($lines[0]['entry_date'])) ?> by <?= e($lines[0]['full_name'] ?? 'System') ?></p>
    </div>
    <div class="order-meta"><a class="text-link" href="journals.php">← All Journals</a></div>
</section>

<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>Code</th><th>Account</th><th>Description</th><th>Debit (KSh)</th><th>Credit (KSh)</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): 
            $totalDebit += (float)$l['debit'];
            $totalCredit += (float)$l['credit'];
        ?>
            <tr>
                <td><?= e($l['account_code']) ?></td>
                <td><strong><?= e($l['account_name']) ?></strong></td>
                <td><?= e($l['description']) ?></td>
                <td><?= (float)$l['debit'] > 0 ? e(money($l['debit'])) : '—' ?></td>
                <td><?= (float)$l['credit'] > 0 ? e(money($l['credit'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot style="background:#f8f9fc;font-weight:700">
            <tr>
                <td colspan="3" style="text-align:right">Totals</td>
                <td><?= e(money($totalDebit)) ?></td>
                <td><?= e(money($totalCredit)) ?></td>
            </tr>
        </tfoot>
    </table></div>
</section>
=======
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'accountant']);
$pageTitle = 'Journal Entry Detail';

$ref = trim((string) ($_GET['ref'] ?? ''));
if ($ref === '') redirect('journals.php');

$stmt = db()->prepare('SELECT je.*, a.account_code, a.account_name, u.full_name 
    FROM journal_entries je 
    JOIN accounts a ON a.id = je.account_id 
    LEFT JOIN users u ON u.id = je.created_by 
    WHERE je.entry_reference = ? ORDER BY je.id');
$stmt->execute([$ref]);
$lines = $stmt->fetchAll();

if (!$lines) {
    flash('error', 'Journal entry not found.');
    redirect('journals.php');
}

$totalDebit = 0; $totalCredit = 0;
require __DIR__ . '/includes/header.php';
?>
<section class="order-banner">
    <div>
        <span class="eyebrow">JOURNAL ENTRY</span>
        <h2><?= e($ref) ?></h2>
        <p>Recorded on <?= e(format_day($lines[0]['entry_date'])) ?> by <?= e($lines[0]['full_name'] ?? 'System') ?></p>
    </div>
    <div class="order-meta"><a class="text-link" href="journals.php">← All Journals</a></div>
</section>

<section class="panel">
    <div class="table-wrap"><table>
        <thead><tr><th>Code</th><th>Account</th><th>Description</th><th>Debit (KSh)</th><th>Credit (KSh)</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $l): 
            $totalDebit += (float)$l['debit'];
            $totalCredit += (float)$l['credit'];
        ?>
            <tr>
                <td><?= e($l['account_code']) ?></td>
                <td><strong><?= e($l['account_name']) ?></strong></td>
                <td><?= e($l['description']) ?></td>
                <td><?= (float)$l['debit'] > 0 ? e(money($l['debit'])) : '—' ?></td>
                <td><?= (float)$l['credit'] > 0 ? e(money($l['credit'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot style="background:#f8f9fc;font-weight:700">
            <tr>
                <td colspan="3" style="text-align:right">Totals</td>
                <td><?= e(money($totalDebit)) ?></td>
                <td><?= e(money($totalCredit)) ?></td>
            </tr>
        </tfoot>
    </table></div>
</section>
>>>>>>> 9675731b7defc439d304f0da27d69db06d37bfed
<?php require __DIR__ . '/includes/footer.php'; ?>