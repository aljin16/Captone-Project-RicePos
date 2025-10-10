<?php
session_start();
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Database.php';
$user = new User();
if (!$user->isLoggedIn()) { header('Location: index.php'); exit; }

$pdo = Database::getInstance()->getConnection();

$q = trim($_GET['q'] ?? '');
// Optional day filter (YYYY-MM-DD)
$day = trim($_GET['day'] ?? '');
if ($day !== '') {
  $dt = DateTime::createFromFormat('Y-m-d', $day);
  if (!$dt || $dt->format('Y-m-d') !== $day) { $day = ''; }
}
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20; $offset = ($page-1) * $perPage;

$conditions = []; $params = [];
if ($q !== '') { $conditions[] = 'transaction_id LIKE ?'; $params[] = "%$q%"; }
if ($day !== '') { $conditions[] = 'DATE(datetime) = ?'; $params[] = $day; }
$where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM sales $where");
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

$sql = "SELECT transaction_id, datetime, total_amount FROM sales $where ORDER BY datetime DESC LIMIT $perPage OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// AJAX endpoint for incremental loading
if (isset($_GET['ajax']) && (int)$_GET['ajax'] === 1) {
  ob_start();
  foreach ($rows as $r) {
    ?>
    <tr>
      <td><?php echo htmlspecialchars($r['datetime']); ?></td>
      <td><?php echo htmlspecialchars($r['transaction_id']); ?></td>
      <td>₱<?php echo number_format((float)$r['total_amount'],2); ?></td>
      <td><a class="btn" href="receipt.php?txn=<?php echo urlencode($r['transaction_id']); ?>" target="_blank">View</a></td>
    </tr>
    <?php
  }
  $html = ob_get_clean();
  $hasMore = $page < $totalPages;
  header('Content-Type: application/json');
  echo json_encode(['html' => $html, 'hasMore' => $hasMore, 'nextPage' => $page + 1]);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recent Sales - RicePOS</title>
  <?php $__cssv = @filemtime(__DIR__.'/assets/css/style.css') ?: time(); ?>
  <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $__cssv; ?>">
  <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
  <style>
    *,*::before,*::after{ box-sizing:border-box; }
    html,body{ height:100%; }
    body{ display:block; min-height:100vh; margin:0; background:#f7f9fc; color:#111827; overflow-x:hidden; }
    .table-card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 8px 24px rgba(17,24,39,0.06); overflow:hidden; }
    .table-scroll{ overflow:auto; }
    .user-table{ width:100%; border-collapse:separate; border-spacing:0; min-width:720px; }
    .user-table thead th{ position:sticky; top:0; background:linear-gradient(180deg,#f8fafc 0%, #eef2ff 100%); color:#1f2937; font-weight:700; font-size:0.92rem; text-align:left; padding:0.75rem 0.9rem; border-bottom:1px solid #e5e7eb; }
    .user-table tbody td{ padding:0.7rem 0.9rem; border-bottom:1px solid #eef2f7; }
    .pagination{ display:flex; gap:0.35rem; margin-top:0.8rem; }
    .pagination a{ padding:0.3rem 0.6rem; border:1px solid #d1d5db; border-radius:10px; text-decoration:none; color:#111827; }
    .pagination .active{ background:#e5e7eb; }
  </style>
</head>
<body>
  <?php $activePage = 'recent_sales.php'; $pageTitle = 'Recent Sales'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
  <main class="main-content">
    
    <div class="toolbar">
      <form method="get" class="toolbar" style="margin:0;">
        <div class="toolbar-item">
          <label for="q">Transaction</label>
          <input id="q" type="text" name="q" placeholder="Search TXN ID" value="<?php echo htmlspecialchars($q); ?>">
        </div>
        <div class="toolbar-item">
          <label for="day">Day</label>
          <input id="day" type="date" name="day" value="<?php echo htmlspecialchars($day); ?>" aria-label="Filter by day">
        </div>
        <button class="btn btn-primary" type="submit">Search</button>
        <?php if ($q !== '' || $day !== ''): ?>
          <a class="btn" href="recent_sales.php">Clear</a>
        <?php endif; ?>
      </form>
      <div class="muted">Total: <?php echo (int)$totalRows; ?></div>
    </div>
    <div class="table-card">
      <div class="table-scroll">
        <table class="user-table">
          <thead><tr><th>Date/Time</th><th>Transaction</th><th>Total</th><th>Receipt</th></tr></thead>
          <tbody id="salesTbody">
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['datetime']); ?></td>
                <td><?php echo htmlspecialchars($r['transaction_id']); ?></td>
                <td>₱<?php echo number_format((float)$r['total_amount'],2); ?></td>
                <td><a class="btn" href="receipt.php?txn=<?php echo urlencode($r['transaction_id']); ?>" target="_blank">View</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="pagination" id="loadControls" style="gap:0.5rem; align-items:center;">
      <button type="button" class="btn" id="loadMoreBtn" <?php echo ($page >= $totalPages) ? 'style="display:none;"' : ''; ?>>Load more</button>
      <button type="button" class="btn" id="collapseBtn" style="display:none;">Collapse</button>
      <span class="muted" id="pageInfo">Page <?php echo (int)$page; ?> of <?php echo (int)$totalPages; ?></span>
    </div>
    <?php if ($totalPages > 1): ?>
      <div class="pagination">
        <?php for ($p=1; $p<=$totalPages; $p++): $qs = http_build_query(array_merge($_GET, ['page'=>$p])); ?>
          <a class="<?php echo $p===$page?'active':''; ?>" href="?<?php echo htmlspecialchars($qs); ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
      </div>
    <?php endif; ?>
  </main>
  <script>
  // Progressive reveal to avoid long scroll: show initial batch, then "View more"
  document.addEventListener('DOMContentLoaded', function(){
    var tbody = document.getElementById('salesTbody');
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var collapseBtn = document.getElementById('collapseBtn');
    var pageInfo = document.getElementById('pageInfo');
    if (!tbody || !loadMoreBtn) return;

    var initialHTML = tbody.innerHTML;
    var currentPage = <?php echo (int)$page; ?>;
    var totalPages = <?php echo (int)$totalPages; ?>;
    var q = <?php echo json_encode($q); ?>;
    var day = <?php echo json_encode($day); ?>;

    function updateInfo() {
      if (pageInfo) pageInfo.textContent = 'Page ' + currentPage + ' of ' + totalPages;
      loadMoreBtn.style.display = (currentPage < totalPages) ? '' : 'none';
      collapseBtn.style.display = (currentPage > 1) ? '' : 'none';
    }

    loadMoreBtn.addEventListener('click', function(){
      if (currentPage >= totalPages) return;
      var next = currentPage + 1;
      var url = 'recent_sales.php?ajax=1&page=' + encodeURIComponent(next)
        + (q ? '&q=' + encodeURIComponent(q) : '')
        + (day ? '&day=' + encodeURIComponent(day) : '');
      loadMoreBtn.disabled = true;
      fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(res){ return res.json(); })
        .then(function(data){
          if (data && data.html) {
            tbody.insertAdjacentHTML('beforeend', data.html);
            currentPage = next;
            updateInfo();
          }
        })
        .finally(function(){ loadMoreBtn.disabled = false; });
    });

    collapseBtn.addEventListener('click', function(){
      tbody.innerHTML = initialHTML;
      currentPage = 1;
      updateInfo();
    });

    updateInfo();
  });
  </script>
  <script src="assets/js/main.js"></script>
</body>
</html>


