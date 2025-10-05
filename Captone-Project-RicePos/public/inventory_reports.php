<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../classes/Database.php';

$pdo = Database::getInstance()->getConnection();

// Range: daily / weekly / monthly
$range = $_GET['range'] ?? 'daily';
if (!in_array($range, ['daily','weekly','monthly'], true)) { $range = 'daily'; }

// Date filter base
$whereSales = '';
$whereMoves = '';
switch ($range) {
    case 'weekly':
        $whereSales = "WHERE YEARWEEK(datetime,1)=YEARWEEK(CURDATE(),1)";
        $whereMoves = "WHERE YEARWEEK(movement_date,1)=YEARWEEK(CURDATE(),1)";
        break;
    case 'monthly':
        $whereSales = "WHERE DATE_FORMAT(datetime,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')";
        $whereMoves = "WHERE DATE_FORMAT(movement_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')";
        break;
    default:
        $whereSales = "WHERE DATE(datetime)=CURDATE()";
        $whereMoves = "WHERE DATE(movement_date)=CURDATE()";
}

// Totals
$soldStmt = $pdo->query("SELECT COALESCE(SUM(si.quantity_sack),0) AS sacks_sold, COALESCE(SUM(si.price),0) AS revenue FROM sale_items si JOIN sales s ON s.id=si.sale_id $whereSales");
$sold = $soldStmt->fetch();

// Remaining stock now
$remStmt = $pdo->query("SELECT COALESCE(SUM(stock_sack),0) AS sacks_remaining FROM products");
$remaining = $remStmt->fetch();

// Wastage = stock_out by specific reasons
$wasteStmt = $pdo->query("SELECT COALESCE(SUM(quantity_sack),0) AS sacks_wasted FROM stock_movements WHERE type='out' AND reason IN ('spoilage','damaged','promo') " . ($whereMoves ? str_replace('WHERE','AND',$whereMoves) : ''));
$wastage = $wasteStmt->fetch();

// Movement breakdown per product
$salesDateCond = ($range==='daily'
    ? "DATE(s.datetime)=CURDATE()"
    : ($range==='weekly'
        ? "YEARWEEK(s.datetime,1)=YEARWEEK(CURDATE(),1)"
        : "DATE_FORMAT(s.datetime,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')"));

$moveDateCond = ($range==='daily'
    ? "DATE(sm.movement_date)=CURDATE()"
    : ($range==='weekly'
        ? "YEARWEEK(sm.movement_date,1)=YEARWEEK(CURDATE(),1)"
        : "DATE_FORMAT(sm.movement_date,'%Y-%m')=DATE_FORMAT(CURDATE(),'%Y-%m')"));

$byProdSql = "SELECT p.id, p.name,
            COALESCE(SUM(CASE WHEN sm.type='in' AND (".$moveDateCond.") THEN sm.quantity_sack ELSE 0 END),0) AS in_sacks,
            COALESCE(SUM(CASE WHEN sm.type='out' AND (".$moveDateCond.") THEN sm.quantity_sack ELSE 0 END),0) AS out_sacks,
            COALESCE(SUM(CASE WHEN (".$salesDateCond.") THEN si.quantity_sack ELSE 0 END),0) AS sold_sacks,
            p.stock_sack AS remaining_sacks
     FROM products p
     LEFT JOIN stock_movements sm ON sm.product_id=p.id
     LEFT JOIN sale_items si ON si.product_id=p.id
     LEFT JOIN sales s ON s.id=si.sale_id
     GROUP BY p.id, p.name, p.stock_sack
     ORDER BY p.name";
$byProd = $pdo->query($byProdSql)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Reports - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
</head>
<body>
<?php $activePage = 'inventory_reports.php'; $pageTitle = 'Inventory Reports'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
<main class="main-content">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-3" style="gap:0.5rem; flex-wrap: wrap;">
            <h5 class="m-0">Inventory Reports (<?php echo htmlspecialchars(ucfirst($range)); ?>)</h5>
            <div class="btn-group" role="group" aria-label="Range">
                <a class="btn btn-outline-secondary <?php echo $range==='daily'?'active':''; ?>" href="?range=daily">Daily</a>
                <a class="btn btn-outline-secondary <?php echo $range==='weekly'?'active':''; ?>" href="?range=weekly">Weekly</a>
                <a class="btn btn-outline-secondary <?php echo $range==='monthly'?'active':''; ?>" href="?range=monthly">Monthly</a>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary btn-sm" href="export_logs.php?format=csv&action=stock_in">Export Stock-In CSV</a>
                <a class="btn btn-outline-secondary btn-sm" href="export_logs.php?format=csv&action=stock_out">Export Stock-Out CSV</a>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Total Sacks Sold</div>
                    <div class="h4 m-0"><?php echo number_format((float)$sold['sacks_sold'], 2); ?></div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Remaining Stock (Sacks)</div>
                    <div class="h4 m-0"><?php echo number_format((float)$remaining['sacks_remaining'], 2); ?></div>
                </div></div>
            </div>
            <div class="col-md-4">
                <div class="card"><div class="card-body">
                    <div class="text-muted">Wastage (Sacks)</div>
                    <div class="h4 m-0"><?php echo number_format((float)$wastage['sacks_wasted'], 2); ?></div>
                </div></div>
            </div>
        </div>

        <div class="card">
            <div class="table-responsive" style="max-height: 60vh;">
                <table class="table table-hover align-middle" style="min-width:720px">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-end">Stock-In</th>
                            <th class="text-end">Stock-Out</th>
                            <th class="text-end">Sold</th>
                            <th class="text-end">Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byProd as $row): ?>
                        <tr>
                            <td><?php echo '#'.(int)$row['id'].' '.htmlspecialchars($row['name']); ?></td>
                            <td class="text-end"><?php echo number_format((float)$row['in_sacks'], 2); ?></td>
                            <td class="text-end"><?php echo number_format((float)$row['out_sacks'], 2); ?></td>
                            <td class="text-end"><?php echo number_format((float)$row['sold_sacks'], 2); ?></td>
                            <td class="text-end"><?php echo number_format((float)$row['remaining_sacks'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
<script src="assets/js/main.js"></script>
</body>
</html>


