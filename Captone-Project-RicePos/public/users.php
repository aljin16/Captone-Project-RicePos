<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_admin();
require_once __DIR__ . '/../includes/functions.php';

$message = '';
$edit_user = null;

// Handle add, edit, delete actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_user'])) {
            $username = $_POST['username'] ?? '';
            $email = $_POST['email'] ?? '';
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $role = $_POST['role'] ?? 'staff';
            $status = $_POST['status'] ?? 'active'; // Added status field
            if ($username && $email && $password && $confirm_password && in_array($role, ['admin', 'staff', 'delivery_staff'])) {
                if ($password !== $confirm_password) {
                    $message = 'Passwords do not match.';
                } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $message = 'Please enter a valid email address.';
                } else {
                    try {
                        if (create_user($username, $email, $password, $role, $status)) {
                            $_SESSION['flash_message'] = 'User created successfully!';
                            header('Location: users.php');
                            exit;
                        } else { $message = 'Error creating user.'; }
                    } catch (Exception $ex) {
                        if ($ex->getMessage() === 'duplicate_username') { $message = 'Username is already taken.'; }
                        else if ($ex->getMessage() === 'duplicate_email') { $message = 'Email is already registered.'; }
                        else { $message = 'Error creating user.'; }
                    }
                }
            } else {
                $message = 'Please fill all fields.';
            }
        } elseif (isset($_POST['edit_user'])) {
        $id = $_POST['id'] ?? '';
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $role = $_POST['role'] ?? 'staff';
        $status = $_POST['status'] ?? 'active'; // Added status field
        if ($id && $username && $email && in_array($role, ['admin', 'staff', 'delivery_staff'])) {
            if (edit_user($id, $username, $email, $role, $status)) {
                $_SESSION['flash_message'] = 'User updated successfully!';
                header('Location: users.php');
                exit;
            } else { $message = 'Error updating user.'; }
        } else {
            $message = 'Please fill all fields.';
        }
    } elseif (isset($_POST['delete_user'])) {
        $id = $_POST['id'] ?? '';
        if ($id) {
            try {
                if (delete_user($id)) {
                    $_SESSION['flash_message'] = 'User deleted successfully!';
                    header('Location: users.php');
                    exit;
                } else { $message = 'Error deleting user.'; }
            } catch (Exception $ex) {
                if ($ex->getMessage() === 'fk_constraint') {
                    $message = 'Cannot delete user because there are related records (sales, logs). Remove or reassign those first.';
                } else {
                    $message = 'Error deleting user.';
                }
            }
        }
    }
}

// Handle edit form display
if (isset($_GET['edit'])) {
    $edit_id = $_GET['edit'];
    foreach (get_all_users() as $u) {
        if ($u['id'] == $edit_id) {
            $edit_user = $u;
            break;
        }
    }
}
$users = get_all_users();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - RicePOS</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    body { display: block; min-height: 100vh; margin: 0; background: #f4f6fb; }
    /* Use shared .main-content sizing from assets/css/style.css for consistent sidebar/header layout */
    .main-content { background: #f4f6fb; min-height: 100vh; }
    .user-form { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; align-items: end; }
    .user-form input, .user-form select { width:100%; padding: 0 1rem; height: 48px; font-size: 1rem; border:1px solid #e5e7eb; border-radius: 9999px; background:#fff; }
    .user-form input:focus, .user-form select:focus { outline:none; border-color:#c7d2fe; box-shadow: 0 0 0 0.12rem rgba(147,197,253,0.28); }
    .user-form .form-actions { display: block; }
    @media (max-width: 700px) { .main-content { padding: 1.2rem 0.5rem 1.2rem 0.5rem; } }
    .table-card{ background:#fff; border:1px solid #e5e7eb; border-radius:14px; box-shadow:0 8px 24px rgba(17,24,39,0.06); overflow:hidden; }
    .table-scroll{ overflow:auto; }
    .user-table{ width:100%; border-collapse:separate; border-spacing:0; min-width: 720px; }
    .user-table thead th{ position:sticky; top:0; background:linear-gradient(180deg,#f8fafc 0%, #eef2ff 100%); color:#1f2937; font-weight:700; font-size:0.92rem; text-align:left; padding:0.75rem 0.9rem; border-bottom:1px solid #e5e7eb; }
    .user-table tbody td{ padding:0.7rem 0.9rem; border-bottom:1px solid #eef2f7; }
    /* Unified, equal-size buttons (scoped to Users page) */
    .user-form .btn, .user-table .btn {
        height: 48px; min-width: initial; padding: 0 18px; border-radius: 9999px; font-weight: 700; display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%;
        border: 1px solid #e5e7eb; background:#fff; color:#111827; box-shadow: 0 4px 12px rgba(17,24,39,0.06); transition: box-shadow .15s ease, background .15s ease, border-color .15s ease, transform .12s ease;
    }
    .user-form .btn:hover, .user-table .btn:hover { background:#f8fafc; border-color:#d1d5db; box-shadow: 0 8px 18px rgba(17,24,39,0.08); transform: translateY(-1px); }
    .user-form .btn:active, .user-table .btn:active { transform: translateY(0); box-shadow: 0 3px 8px rgba(17,24,39,0.06); }
    /* Solid green Add button on form */
    .user-form .btn.btn-add { background:#16a34a; color:#fff; border-color:#16a34a; box-shadow: 0 6px 14px rgba(22,163,74,0.22); }
    .user-form .btn.btn-add:hover { background:#16a34a; border-color:#16a34a; box-shadow: 0 6px 14px rgba(22,163,74,0.22); transform:none; }
    .user-form .btn.btn-add:active { background:#16a34a; }
    /* Keep table action variants outlined */
    .user-table .btn.btn-add { color:#16a34a; border-color:#bbf7d0; width:auto; }
    .user-form .btn.btn-edit, .user-table .btn.btn-edit { color:#2563eb; border-color:#bfdbfe; }
    .user-form .btn.btn-delete, .user-table .btn.btn-delete { color:#dc2626; border-color:#fecaca; }
    .user-form .btn.btn-add:hover, .user-table .btn.btn-add:hover { background:#16a34a; border-color:#16a34a; color:#fff; box-shadow: 0 6px 14px rgba(22,163,74,0.22); transform:none; }
    .user-form .btn.btn-edit:hover, .user-table .btn.btn-edit:hover { background:#eff6ff; border-color:#93c5fd; }
    .user-form .btn.btn-delete:hover, .user-table .btn.btn-delete:hover { background:#fef2f2; border-color:#fca5a5; }

    /* Table actions cell uses flex to keep equal buttons aligned */
    .user-table td.actions { white-space: nowrap; }
    .user-table td.actions { display:flex; align-items:center; gap:0.5rem; }
    .user-table td.actions form { display:inline; }
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
            title: 'Update User?',
            text: "Are you sure you want to update this user's information?",
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
            title: 'Add User?',
            text: "Are you sure you want to add this new user?",
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
    <?php $activePage = 'users.php'; $pageTitle = 'User Management'; include __DIR__ . '/../includes/sidebar.php'; include __DIR__ . '/../includes/header.php'; ?>
    <main class="main-content">
        
        <?php if (!empty($_SESSION['flash_message'])): ?>
            <script>
                Swal.fire({ title: 'Success!', text: '<?php echo htmlspecialchars($_SESSION['flash_message']); ?>', icon: 'success', confirmButtonColor: '#3085d6' });
            </script>
            <?php unset($_SESSION['flash_message']); ?>
        <?php elseif ($message): ?>
            <script>
                Swal.fire({
                    title: '<?php echo strpos($message, 'successfully') !== false ? 'Success!' : 'Notice'; ?>',
                    text: '<?php echo htmlspecialchars($message); ?>',
                    icon: '<?php echo strpos($message, 'successfully') !== false ? 'success' : 'info'; ?>',
                    confirmButtonColor: '#3085d6'
                });
            </script>
        <?php endif; ?>
        <?php if ($edit_user): ?>
        <form method="post" class="user-form edit-form">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
            <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($edit_user['username']); ?>" required>
            <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($edit_user['email']); ?>" required>
            <select name="role">
                <option value="staff" <?php if ($edit_user['role']==='staff') echo 'selected'; ?>>Staff</option>
                <option value="delivery_staff" <?php if ($edit_user['role']==='delivery_staff') echo 'selected'; ?>>Delivery Staff</option>
                <option value="admin" <?php if ($edit_user['role']==='admin') echo 'selected'; ?>>Admin</option>
            </select>
            <select name="status">
                <option value="active" <?php if ($edit_user['status']==='active') echo 'selected'; ?>>Active</option>
                <option value="inactive" <?php if ($edit_user['status']==='inactive') echo 'selected'; ?>>Inactive</option>
            </select>
            <div class="form-actions toolbar" style="justify-content:flex-start;">
                <button type="submit" name="edit_user" class="btn btn-edit">
                    <i class='bx bx-edit'></i> Update User
                </button>
                <a href="users.php" class="btn">Cancel</a>
            </div>
        </form>
        <?php else: ?>
        <form method="post" class="user-form add-form">
            <input type="hidden" name="add_user" value="1">
            <input type="text" name="username" placeholder="Username" required>
            <input type="email" name="email" placeholder="Email" required>
            <div style="display:grid; grid-template-columns:1fr auto; gap:0.4rem; align-items:center;">
                <input id="pw" type="password" name="password" placeholder="Password" required>
                <label style="display:flex; align-items:center; gap:6px; font-size:0.9rem; color:#374151;"><input type="checkbox" id="showPw"> Show</label>
            </div>
            <input id="cpw" type="password" name="confirm_password" placeholder="Confirm Password" required>
            <select name="role">
                <option value="staff">Staff</option>
                <option value="delivery_staff">Delivery Staff</option>
                <option value="admin">Admin</option>
            </select>
            <select name="status">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
            </select>
            <div class="form-actions toolbar" style="justify-content:flex-start;">
                <button type="submit" name="add_user" class="btn btn-add">
                    <i class='bx bx-plus'></i> Add User
                </button>
            </div>
        </form>
        <?php endif; ?>
        <script>
        (function(){
          const pw = document.getElementById('pw');
          const cpw = document.getElementById('cpw');
          const show = document.getElementById('showPw');
          if (show && pw && cpw) {
            show.addEventListener('change', function(){
              const t = this.checked ? 'text' : 'password';
              pw.type = t; cpw.type = t;
            });
          }
        })();
        </script>
        <h3>Existing Users</h3>
        <div class="table-card"><div class="table-scroll">
        <table class="user-table">
            <thead>
                <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Last Login</th><th>Created At</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo $user['id']; ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo $user['role']; ?></td>
                        <td><?php echo $user['status']; ?></td>
                        <td><?php echo $user['last_login'] ? $user['last_login'] : '-'; ?></td>
                        <td><?php echo $user['created_at']; ?></td>
                        <td class="actions">
                            <a href="users.php?edit=<?php echo $user['id']; ?>" class="btn btn-edit">
                                <i class='bx bx-edit'></i> Edit
                            </a>
                            <form method="post" style="display:inline;" class="delete-form">
                                <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
                                <input type="hidden" name="delete_user" value="1">
                                <button type="submit" name="delete_user" class="btn btn-delete">
                                    <i class='bx bx-trash'></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div></div>
    </main>
        <script src="assets/js/main.js"></script>
        <script>
        document.addEventListener('DOMContentLoaded', function(){
            const addForm = document.querySelector('form.add-form');
            if (addForm) addForm.addEventListener('submit', function(e){
                e.preventDefault();
                Swal.fire({
                    title: 'Add User?',
                    text: 'Are you sure you want to add this new user?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, add it!',
                    cancelButtonText: 'Cancel'
                }).then(res => { if (res.isConfirmed) e.target.submit(); });
            });

            const editForm = document.querySelector('form.edit-form');
            if (editForm) editForm.addEventListener('submit', function(e){
                e.preventDefault();
                Swal.fire({
                    title: 'Update User?',
                    text: "Are you sure you want to update this user's information?",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#6c757d',
                    confirmButtonText: 'Yes, update it!',
                    cancelButtonText: 'Cancel'
                }).then(res => { if (res.isConfirmed) e.target.submit(); });
            });

            document.querySelectorAll('form.delete-form').forEach(f => {
                f.addEventListener('submit', function(e){
                    e.preventDefault();
                    Swal.fire({
                        title: 'Delete user?',
                        text: "This action cannot be undone.",
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#d33',
                        cancelButtonColor: '#6c757d',
                        confirmButtonText: 'Yes, delete',
                        cancelButtonText: 'Cancel'
                    }).then(res => { if (res.isConfirmed) e.target.submit(); });
                });
            });
        });
        </script>
</body>
</html> 