<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_roles(['admin']);
$pageTitle = 'Audit Trail';

$search = trim((string) ($_GET['q'] ?? ''));
$sql = 'SELECT al.*, u.full_name, u.username FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (al.action LIKE ? OR al.details LIKE ? OR u.full_name LIKE ?)';
    $term = '%' . $search . '%';
    $params = [$term, $term, $term];
}
$sql .= ' ORDER BY al.id DESC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<section class="panel">
    <div class="panel-heading">
        <div><h2>System Activity Log</h2><p>Most recent 200 actions</p></div>
    </div>
    <form method="get" class="search-form">
        <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search action, user, or details">
        <button class="button button-secondary" type="submit">Search</button>
        <?php if ($search !== ''): ?><a class="text-link" href="audit.php">Clear</a><?php endif; ?>
    </form>
    <div class="table-wrap"><table>
        <thead><tr><th>Date / Time</th><th>User</th><th>Action</th><th>Details</th><th>IP Address</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td style="white-space:nowrap"><?= e(format_date($log['created_at'])) ?></td>
                <td><strong><?= e($log['full_name'] ?? 'System/Guest') ?></strong><small class="table-subtext"><?= e($log['username'] ?? '—') ?></small></td>
                <td><span class="pill pill-info"><?= e($log['action']) ?></span></td>
                <td><?= e($log['details']) ?></td>
                <td><small class="muted"><?= e($log['ip_address'] ?? '—') ?></small></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$logs): ?><tr><td colspan="5" class="empty-cell">No logs found.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>