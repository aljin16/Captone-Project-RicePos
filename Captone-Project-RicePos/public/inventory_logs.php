<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();

// Filters and pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(100, (int)($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;
$action = trim($_GET['action'] ?? '');
$productId = isset($_GET['product_id']) ? (int)$_GET['product_id'] : null;

$where = [];
$params = [];
if ($action !== '') { $where[] = 'action = :action'; $params[':action'] = $action; }
if ($productId) { $where[] = 'product_id = :pid'; $params[':pid'] = $productId; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Total for current filter
$countSql = "SELECT COUNT(*) AS c FROM inventory_activity_logs $whereSql";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $k => $v) { $countStmt->bindValue($k, $v); }
$countStmt->execute();
$total = (int)$countStmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT * FROM inventory_activity_logs $whereSql ORDER BY created_at DESC, id DESC LIMIT :lim OFFSET :off");
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
$stmt->bindValue(':off', $offset, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll();

$actions = [
    'product_add' => 'success',
    'product_update' => 'warning',
    'product_delete' => 'danger',
    'stock_update' => 'info',
    'stock_sale' => 'primary',
    'product_activate' => 'success',
    'product_hide' => 'secondary',
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Activity Logs - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        .badge-success{background:#16a34a} .badge-warning{background:#f59e0b} .badge-danger{background:#ef4444} .badge-info{background:#06b6d4} .badge-primary{background:#3b82f6} .badge-secondary{background:#64748b}
        .log-details{white-space: pre-wrap; color:#334155}
        .table thead th{ position: sticky; top: 0; background: #f8fafc; z-index: 1; }
    </style>
</head>
<body>
<?php $activePage = 'inventory_logs.php'; $pageTitle = 'Inventory Activity Logs'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
<main class="main-content">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="m-0">Inventory Activity Logs</h5>
            <div class="d-flex gap-2">
                <a class="btn btn-sm btn-outline-secondary" href="export_logs.php?format=csv<?php echo $action?('&action='.urlencode($action)) : '';?><?php echo $productId?('&product_id='.(int)$productId):''; ?>"><i class='bx bx-download'></i> CSV</a>
                <a class="btn btn-sm btn-outline-secondary" href="export_logs.php?format=xlsx<?php echo $action?('&action='.urlencode($action)) : '';?><?php echo $productId?('&product_id='.(int)$productId):''; ?>"><i class='bx bx-download'></i> Excel</a>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-body">
                <form class="row g-2" method="get">
                    <div class="col-md-3">
                        <label class="form-label">Action</label>
                        <select class="form-select" name="action">
                            <option value="">All</option>
                            <?php foreach (array_keys($actions) as $a): ?>
                                <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $action===$a?'selected':''; ?>><?php echo $a; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Product ID</label>
                        <input type="number" class="form-control" name="product_id" value="<?php echo $productId?:''; ?>" placeholder="e.g. 12">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Per Page</label>
                        <select class="form-select" name="limit">
                            <?php foreach ([10,25,50,100] as $l): ?>
                                <option value="<?php echo $l; ?>" <?php echo $limit===$l?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit"><i class='bx bx-filter'></i> Filter</button>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <a class="btn btn-outline-dark w-100" href="export_logs_pdf.php<?php echo $action?('?action='.urlencode($action)) : '';?><?php echo (!$action && $productId)?('?product_id='.(int)$productId):($productId?('&product_id='.(int)$productId):''); ?>" target="_blank"><i class='bx bxs-file-pdf'></i> PDF</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Product</th>
                            <th>Stock Before</th>
                            <th>Stock After</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody id="logs-body">
                        <?php foreach ($logs as $log): $color = $actions[$log['action']] ?? 'secondary'; ?>
                            <tr data-id="<?php echo (int)$log['id']; ?>">
                                <td style="white-space:nowrap; font-variant-numeric: tabular-nums;"><?php echo htmlspecialchars($log['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($log['username'] ?: ('User#'.($log['user_id'] ?? '-'))); ?></td>
                                <td><span class="badge badge-<?php echo $color; ?>"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                <td><?php echo $log['product_id'] ? ('#'.(int)$log['product_id'].' '.htmlspecialchars($log['product_name'] ?? '')) : '-'; ?></td>
                                <td><?php echo ($log['stock_before_sack'] !== null ? (float)$log['stock_before_sack'] : '-') . ' sack(s)'; ?></td>
                                <td><?php echo ($log['stock_after_sack'] !== null ? (float)$log['stock_after_sack'] : '-') . ' sack(s)'; ?></td>
                                <td class="log-details"><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>Page <?php echo $page; ?> of <?php echo max(1, ceil($total / $limit)); ?></div>
                <div class="btn-group">
                    <?php if ($page > 1): $prev = $page - 1; ?>
                        <a class="btn btn-outline-secondary" href="?page=<?php echo $prev; ?>&limit=<?php echo $limit; ?><?php echo $action?('&action='.urlencode($action)) : '';?><?php echo $productId?('&product_id='.(int)$productId):''; ?>">Prev</a>
                    <?php endif; ?>
                    <?php if ($offset + $limit < $total): $next = $page + 1; ?>
                        <a class="btn btn-outline-secondary" href="?page=<?php echo $next; ?>&limit=<?php echo $limit; ?><?php echo $action?('&action='.urlencode($action)) : '';?><?php echo $productId?('&product_id='.(int)$productId):''; ?>">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="small text-muted mt-2">Live: this page auto-updates with new logs.</div>
    </div>
</main>
<script>
// Live updates via lightweight polling using since-id
(function(){
    const tbody = document.getElementById('logs-body');
    function getMaxId(){
        let maxId = 0;
        tbody.querySelectorAll('tr[data-id]').forEach(tr=>{
            const id = parseInt(tr.getAttribute('data-id')||'0',10); if (id>maxId) maxId = id;
        });
        return maxId;
    }
    function prependRows(rowsHtml){
        if (!rowsHtml) return;
        const tmp = document.createElement('tbody');
        tmp.innerHTML = rowsHtml;
        const rows = Array.from(tmp.children);
        rows.forEach(row=>{ tbody.insertBefore(row, tbody.firstChild); });
    }
    async function poll(){
        const since = getMaxId();
        try {
            const r = await fetch('logs_stream.php?poll=1&since='+encodeURIComponent(since));
            const j = await r.json();
            if (j && j.html) { prependRows(j.html); }
        } catch(e){}
    }
    // Visibility-aware interval
    let id = null;
    function start(){ if (id) return; id = setInterval(poll, document.hidden ? 12000 : 4000); }
    function stop(){ if (id) { clearInterval(id); id = null; } }
    document.addEventListener('visibilitychange', ()=>{ stop(); start(); });
    start();
})();
</script>
</body>
</html>


