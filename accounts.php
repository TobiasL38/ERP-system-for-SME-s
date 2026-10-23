<<<<<<< HEAD
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'accountant']);
$pageTitle = 'Chart of Accounts';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'create') {
            $code = post_string('account_code', 30);
            $name = post_string('account_name', 150);
            $type = post_string('account_type', 30);

            if ($code === '' || $name === '') throw new RuntimeException('Code and name are required.');
            if (!in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], true)) throw new RuntimeException('Select a valid account type.');

            db()->prepare('INSERT INTO accounts (account_code, account_name, account_type, is_active) VALUES (?, ?, ?, 1)')
                ->execute([$code, $name, $type]);
            flash('success', 'Account created successfully.');
            redirect('accounts.php');
        }
        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 1);
            
            // Check if we are deactivating an account that has journal entries
            if ($isActive === 0) {
                $check = db()->prepare('SELECT COUNT(*) FROM journal_entries WHERE account_id=?');
                $check->execute([$id]);
                if ((int)$check->fetchColumn() > 0) throw new RuntimeException('Cannot deactivate an account with existing journal entries.');
            }
            
            db()->prepare('UPDATE accounts SET is_active=? WHERE id=?')->execute([$isActive, $id]);
            flash('success', 'Account status updated.');
            redirect('accounts.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = $exception->getCode() === '23000' ? 'Account code already exists.' : 'The account could not be saved.';
    }
}

$accounts = db()->query('SELECT a.*, 
    (SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) FROM journal_entries WHERE account_id = a.id) AS balance 
    FROM accounts a ORDER BY a.account_code')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Add Account</h2><p>Create a new ledger account.</p></div></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div><label for="account_code">Account Code *</label><input id="account_code" name="account_code" maxlength="30" required placeholder="e.g. 1001"></div>
            <div><label for="account_name">Account Name *</label><input id="account_name" name="account_name" maxlength="150" required placeholder="e.g. Cash on Hand"></div>
            <div><label for="account_type">Type *</label>
                <select id="account_type" name="account_type" required>
                    <option value="asset">Asset (e.g. Cash, Bank, Inventory)</option>
                    <option value="liability">Liability (e.g. Accounts Payable)</option>
                    <option value="equity">Equity (e.g. Owner's Capital)</option>
                    <option value="income">Income (e.g. Sales Revenue)</option>
                    <option value="expense">Expense (e.g. Rent, Utilities)</option>
                </select>
            </div>
            <button class="button button-primary" type="submit">Create Account</button>
        </form>
    </article>
    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Account List</h2><p><?= count($accounts) ?> registered accounts</p></div></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Code</th><th>Account Name</th><th>Type</th><th>Balance (Dr - Cr)</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): 
                $balance = (float)$a['balance'];
                $isDebit = $balance >= 0;
                $formattedBalance = money(abs($balance));
            ?>
                <tr>
                    <td><strong><?= e($a['account_code']) ?></strong></td>
                    <td><?= e($a['account_name']) ?></td>
                    <td><span class="pill pill-neutral"><?= e(ucfirst($a['account_type'])) ?></span></td>
                    <td>
                        <?= $formattedBalance ?> 
                        <small class="table-subtext"><?= $isDebit ? 'Dr' : 'Cr' ?></small>
                    </td>
                    <td><span class="pill pill-<?= $a['is_active'] ? 'success' : 'warning' ?>"><?= $a['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $a['is_active'] ? 0 : 1 ?>">
                            <button class="link-button <?= $a['is_active'] ? 'danger-link' : '' ?>" type="submit"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </article>
</section>
=======
<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin', 'manager', 'accountant']);
$pageTitle = 'Chart of Accounts';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 30);
    try {
        if ($action === 'create') {
            $code = post_string('account_code', 30);
            $name = post_string('account_name', 150);
            $type = post_string('account_type', 30);

            if ($code === '' || $name === '') throw new RuntimeException('Code and name are required.');
            if (!in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], true)) throw new RuntimeException('Select a valid account type.');

            db()->prepare('INSERT INTO accounts (account_code, account_name, account_type, is_active) VALUES (?, ?, ?, 1)')
                ->execute([$code, $name, $type]);
            flash('success', 'Account created successfully.');
            redirect('accounts.php');
        }
        if ($action === 'toggle') {
            $id = (int) ($_POST['id'] ?? 0);
            $isActive = (int) ($_POST['is_active'] ?? 1);
            
            // Check if we are deactivating an account that has journal entries
            if ($isActive === 0) {
                $check = db()->prepare('SELECT COUNT(*) FROM journal_entries WHERE account_id=?');
                $check->execute([$id]);
                if ((int)$check->fetchColumn() > 0) throw new RuntimeException('Cannot deactivate an account with existing journal entries.');
            }
            
            db()->prepare('UPDATE accounts SET is_active=? WHERE id=?')->execute([$isActive, $id]);
            flash('success', 'Account status updated.');
            redirect('accounts.php');
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        $error = $exception->getCode() === '23000' ? 'Account code already exists.' : 'The account could not be saved.';
    }
}

$accounts = db()->query('SELECT a.*, 
    (SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) FROM journal_entries WHERE account_id = a.id) AS balance 
    FROM accounts a ORDER BY a.account_code')->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
<section class="split-layout">
    <article class="panel form-panel">
        <div class="panel-heading"><div><h2>Add Account</h2><p>Create a new ledger account.</p></div></div>
        <form method="post" class="form-stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div><label for="account_code">Account Code *</label><input id="account_code" name="account_code" maxlength="30" required placeholder="e.g. 1001"></div>
            <div><label for="account_name">Account Name *</label><input id="account_name" name="account_name" maxlength="150" required placeholder="e.g. Cash on Hand"></div>
            <div><label for="account_type">Type *</label>
                <select id="account_type" name="account_type" required>
                    <option value="asset">Asset (e.g. Cash, Bank, Inventory)</option>
                    <option value="liability">Liability (e.g. Accounts Payable)</option>
                    <option value="equity">Equity (e.g. Owner's Capital)</option>
                    <option value="income">Income (e.g. Sales Revenue)</option>
                    <option value="expense">Expense (e.g. Rent, Utilities)</option>
                </select>
            </div>
            <button class="button button-primary" type="submit">Create Account</button>
        </form>
    </article>
    <article class="panel list-panel">
        <div class="panel-heading"><div><h2>Account List</h2><p><?= count($accounts) ?> registered accounts</p></div></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Code</th><th>Account Name</th><th>Type</th><th>Balance (Dr - Cr)</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): 
                $balance = (float)$a['balance'];
                $isDebit = $balance >= 0;
                $formattedBalance = money(abs($balance));
            ?>
                <tr>
                    <td><strong><?= e($a['account_code']) ?></strong></td>
                    <td><?= e($a['account_name']) ?></td>
                    <td><span class="pill pill-neutral"><?= e(ucfirst($a['account_type'])) ?></span></td>
                    <td>
                        <?= $formattedBalance ?> 
                        <small class="table-subtext"><?= $isDebit ? 'Dr' : 'Cr' ?></small>
                    </td>
                    <td><span class="pill pill-<?= $a['is_active'] ? 'success' : 'warning' ?>"><?= $a['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td>
                        <form method="post" class="inline-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                            <input type="hidden" name="is_active" value="<?= $a['is_active'] ? 0 : 1 ?>">
                            <button class="link-button <?= $a['is_active'] ? 'danger-link' : '' ?>" type="submit"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </article>
</section>
>>>>>>> 9675731b7defc439d304f0da27d69db06d37bfed
<?php require __DIR__ . '/includes/footer.php'; ?>