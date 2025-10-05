<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../includes/functions.php';

$message = '';
$edit_supplier = null;

// Handle add, edit, delete actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_supplier'])) {
        $name = trim($_POST['name'] ?? '');
        $landline = trim($_POST['landline'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
            if ($name === '' || $phone === '' || $email === '' || $address === '') {
                $message = 'Please fill all required fields (landline is optional).';
            } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = 'Please enter a valid email address.';
            } else if (supplier_name_exists($name)) {
                $message = 'Supplier name already exists.';
            } else if (supplier_phone_exists($phone)) {
                $message = 'Phone number already exists.';
            } else if (supplier_email_exists($email)) {
                $message = 'Email already exists.';
            } else if (add_supplier($name, $landline, $phone, $email, $address)) {
                $message = 'Supplier added!';
            } else {
                $message = 'Error adding supplier.';
            }
        } elseif (isset($_POST['edit_supplier'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $landline = trim($_POST['landline'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
            if ($id && $name !== '' && $phone !== '' && $email !== '' && $address !== '') {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = 'Please enter a valid email address.';
                } else if (supplier_name_exists($name, $id)) {
                    $message = 'Supplier name already exists.';
                } else if (supplier_phone_exists($phone, $id)) {
                    $message = 'Phone number already exists.';
                } else if (supplier_email_exists($email, $id)) {
                    $message = 'Email already exists.';
                } else if (edit_supplier($id, $name, $landline, $phone, $email, $address)) {
                $message = 'Supplier updated!';
            } else {
                $message = 'Error updating supplier.';
            }
        } else {
            $message = 'Please fill all required fields (landline is optional).';
        }
    } elseif (isset($_POST['delete_supplier'])) {
        $id = $_POST['id'] ?? '';
        if ($id) {
            if (delete_supplier($id)) {
                $message = 'Supplier deleted!';
            } else {
                $message = 'Error deleting supplier.';
            }
        }
    }
}
// Handle edit form display
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    $edit_supplier = get_supplier_by_id($edit_id);
}
$suppliers = get_all_suppliers();
// Compute potential duplicates by name/phone/email
$dupeFlags = [];
$byName = [];$byPhone = [];$byEmail = [];
foreach ($suppliers as $s) {
    $sid = (int)($s['supplier_id'] ?? 0);
    $nameKey = strtolower(trim((string)($s['name'] ?? '')));
    if ($nameKey !== '') { $byName[$nameKey][] = $sid; }
    $phoneKey = preg_replace('/\D+/', '', (string)($s['phone'] ?? ''));
    if ($phoneKey !== '') { $byPhone[$phoneKey][] = $sid; }
    $emailKey = strtolower(trim((string)($s['email'] ?? '')));
    if ($emailKey !== '') { $byEmail[$emailKey][] = $sid; }
}
foreach (['Name' => $byName, 'Phone' => $byPhone, 'Email' => $byEmail] as $label => $map) {
    if (is_array($map)) {
        foreach ($map as $key => $ids) {
            if (count($ids) > 1) {
                foreach ($ids as $idFlag) { $dupeFlags[$idFlag][$label] = true; }
            }
        }
    }
}
$duplicateCount = count($dupeFlags);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Management - RicePOS</title>
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo htmlspecialchars((string)$cssVer, ENT_QUOTES); ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    body { display: block; min-height: 100vh; margin: 0; background: #f4f6fb; }
    .main-content { padding: 2.5rem 2.5rem 2.5rem 2rem; background: #f4f6fb; min-height: 100vh; margin-left: 0 !important; position: relative !important; left: var(--sidebar-width, 260px) !important; width: calc(100% - var(--sidebar-width, 260px)) !important; }
    .user-form { 
        display: grid; 
        grid-template-columns: repeat(5, minmax(140px, 1fr)); 
        gap: 0.6rem; 
        align-items: end;
    }
    .user-form input, .user-form select { padding: 0.45rem 0.55rem; font-size: 0.95rem; border:1px solid #dbeafe; border-radius:8px; }
    .user-form .form-actions { grid-column: 1 / -1; display: flex; gap: 0.5rem; align-items: center; }

    .main-content .btn { padding: 0.4rem 0.75rem; font-size: 0.95rem; }
    .user-table { width:100%; border-collapse:separate; border-spacing:0; min-width: 720px; }
    .user-table thead th { position:sticky; top:0; background:linear-gradient(180deg,#f8fafc 0%, #eef2ff 100%); color:#1f2937; font-weight:700; font-size:0.92rem; text-align:left; padding:0.75rem 0.9rem; border-bottom:1px solid #e5e7eb; }
    .user-table tbody td { padding:0.7rem 0.9rem; border-bottom:1px solid #eef2f7; }
    .table-card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 8px 24px rgba(17,24,39,0.06); overflow:hidden; }
    .table-scroll{ overflow:auto; }
    @media (max-width: 700px) { .main-content { padding: 1.2rem 0.5rem 1.2rem 0.5rem; } }
    /* Lock hover styles for Add/Edit buttons so their background colors remain */
    .main-content .btn.btn-add { background:#22c55e; border-color:#22c55e; color:#fff; font-weight:700; transition:none; }
    .main-content .btn.btn-add:hover { background:#22c55e; border-color:#22c55e; color:#fff; box-shadow:none; transform:none; filter:none; }
    .main-content .btn.btn-edit { background:#2563eb; border-color:#2563eb; color:#fff; font-weight:700; transition:none; }
    .main-content .btn.btn-edit:hover { background:#2563eb; border-color:#2563eb; color:#fff; box-shadow:none; transform:none; filter:none; }
    </style>
    <script>
    function confirmDelete() {
        return Swal.fire({
            title: 'Are you sure?',
            text: "You won't be able to revert this!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Yes, delete it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            return result.isConfirmed;
        });
    }

    function confirmEdit() {
        return Swal.fire({
            title: 'Update Supplier?',
            text: "Are you sure you want to update this supplier's information?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, update it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            return result.isConfirmed;
        });
    }

    function confirmAdd() {
        return Swal.fire({
            title: 'Add Supplier?',
            text: "Are you sure you want to add this new supplier?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, add it!',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            return result.isConfirmed;
        });
    }
    </script>
</head>
<body>
    <?php $activePage = 'suppliers.php'; $pageTitle = 'Supplier Management'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        
        <?php if ($message): ?>
            <script>
                Swal.fire({
                    title: '<?php echo strpos($message, '!') !== false ? 'Success!' : 'Notice'; ?>',
                    text: '<?php echo htmlspecialchars($message); ?>',
                    icon: '<?php echo strpos($message, '!') !== false ? 'success' : 'info'; ?>',
                    confirmButtonColor: '#3085d6'
                });
            </script>
        <?php endif; ?>
        <?php if ($edit_supplier): ?>
        <form method="post" class="user-form">
            <input type="hidden" name="id" value="<?php echo $edit_supplier['supplier_id']; ?>">
            <input type="text" name="name" placeholder="Name" value="<?php echo htmlspecialchars($edit_supplier['name']); ?>" required>
            <input type="text" name="landline" placeholder="Landline No. (optional)" value="<?php echo htmlspecialchars($edit_supplier['landline'] ?? ''); ?>">
            <input type="text" name="phone" placeholder="Phone" value="<?php echo htmlspecialchars($edit_supplier['phone']); ?>">
            <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($edit_supplier['email']); ?>">
            <input type="text" name="address" placeholder="Address" value="<?php echo htmlspecialchars($edit_supplier['address']); ?>">
            <div class="form-actions">
                <button type="submit" name="edit_supplier" class="btn btn-edit"><i class='bx bx-edit'></i> Update</button>
                <a href="suppliers.php" class="btn">Cancel</a>
            </div>
        </form>
        <?php else: ?>
        <form method="post" class="user-form">
            <input type="text" name="name" placeholder="Name" required>
            <input type="text" name="landline" placeholder="Landline No. (optional)">
            <input type="text" name="phone" placeholder="Phone">
            <input type="email" name="email" placeholder="Email">
            <input type="text" name="address" placeholder="Address">
            <div class="form-actions">
                <button type="submit" name="add_supplier" class="btn btn-add"><i class='bx bx-plus'></i> Add Supplier</button>
            </div>
        </form>
        <?php endif; ?>
        <h3>Suppliers List</h3>
        <?php if (!empty($duplicateCount)): ?>
            <div class="info-msg" style="margin-bottom:0.6rem;">
                Potential duplicates detected: <?php echo (int)$duplicateCount; ?> supplier(s) share the same Name/Phone/Email.
            </div>
        <?php endif; ?>
        <div class="table-card">
        <div class="table-scroll">
        <table class="user-table">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Landline No.</th><th>Phone</th><th>Email</th><th>Address</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $supplier): ?>
                    <tr<?php $sid=(int)$supplier['supplier_id']; if(isset($dupeFlags[$sid])) echo ' style="background:#fff7ed;"'; ?>>
                        <td><?php echo $supplier['supplier_id']; ?></td>
                        <td><?php echo htmlspecialchars($supplier['name']); ?><?php if(isset($dupeFlags[$sid]['Name'])) echo ' <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">dupe</span>'; ?></td>
                        <td><?php echo htmlspecialchars(isset($supplier['landline']) && $supplier['landline'] !== '' ? $supplier['landline'] : 'None'); ?></td>
                        <td><?php echo htmlspecialchars($supplier['phone']); ?><?php if(isset($dupeFlags[$sid]['Phone'])) echo ' <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">dupe</span>'; ?></td>
                        <td><?php echo htmlspecialchars($supplier['email']); ?><?php if(isset($dupeFlags[$sid]['Email'])) echo ' <span class="badge" style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">dupe</span>'; ?></td>
                        <td><?php echo htmlspecialchars($supplier['address']); ?></td>
                        <td>
                            <a href="suppliers.php?edit=<?php echo $supplier['supplier_id']; ?>" class="btn btn-edit"><i class='bx bx-edit'></i> Edit</a>
                            <form method="post" class="delete-form" style="display:inline;">
                                <input type="hidden" name="id" value="<?php echo $supplier['supplier_id']; ?>">
                                <button type="submit" name="delete_supplier" class="btn btn-delete"><i class='bx bx-trash'></i> Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    </main>
    <script>
    // Wire SweetAlert2 confirmations only on this page
    document.addEventListener('DOMContentLoaded', function () {
        const animation = {
            showClass: { popup: 'animate__animated animate__fadeInDown' },
            hideClass: { popup: 'animate__animated animate__fadeOutUp' }
        };

        function swalConfirm(options) {
            return Swal.fire(Object.assign({
                icon: 'question',
                showCancelButton: true,
                buttonsStyling: false,
                focusCancel: true,
                reverseButtons: true,
                customClass: {
                    confirmButton: 'btn',
                    cancelButton: 'btn'
                }
            }, animation, options));
        }

        // Add/Edit form confirmations
        document.querySelectorAll('form.user-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();

                const isEdit = !!form.querySelector('button[name="edit_supplier"]');
                const isAdd = !!form.querySelector('button[name="add_supplier"]');

                const options = isEdit ? {
                    title: 'Update Supplier?',
                    text: "Are you sure you want to update this supplier's information?",
                    confirmButtonText: 'Yes, update',
                    icon: 'question',
                    customClass: { confirmButton: 'btn btn-edit', cancelButton: 'btn' }
                } : {
                    title: 'Add Supplier?',
                    text: 'Are you sure you want to add this new supplier?',
                    confirmButtonText: 'Yes, add',
                    icon: 'question',
                    customClass: { confirmButton: 'btn btn-add', cancelButton: 'btn' }
                };

                swalConfirm(options).then(function (res) {
                    if (res.isConfirmed) {
                        // Ensure the intended action is included in POST when submitting programmatically
                        const actionName = isEdit ? 'edit_supplier' : 'add_supplier';
                        if (!form.querySelector('input[type="hidden"][name="' + actionName + '"]')) {
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = actionName;
                            hidden.value = '1';
                            form.appendChild(hidden);
                        }
                        form.submit();
                    }
                });
            });
        });

        // Delete confirmations
        document.querySelectorAll('form.delete-form').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                swalConfirm({
                    title: 'Delete Supplier?',
                    text: "You won't be able to revert this.",
                    icon: 'warning',
                    confirmButtonText: 'Yes, delete',
                    customClass: { confirmButton: 'btn btn-delete', cancelButton: 'btn' }
                }).then(function (res) {
                    if (res.isConfirmed) {
                        // Ensure delete action is present in POST on programmatic submit
                        if (!form.querySelector('input[type="hidden"][name="delete_supplier"]')) {
                            const hidden = document.createElement('input');
                            hidden.type = 'hidden';
                            hidden.name = 'delete_supplier';
                            hidden.value = '1';
                            form.appendChild(hidden);
                        }
                        form.submit();
                    }
                });
            });
        });
    });
    </script>
    <script src="assets/js/main.js"></script>
</body>
</html> 