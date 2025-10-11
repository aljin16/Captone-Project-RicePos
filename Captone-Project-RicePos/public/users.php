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
    <?php $cssVer = @filemtime(__DIR__ . '/assets/css/style.css') ?: time(); ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo $cssVer; ?>">
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    body { display: block; min-height: 100vh; margin: 0; background: #f4f6fb; }
    .main-content { background: #f4f6fb; min-height: 100vh; padding: 2rem; }
    
    /* Modern form card styling */
    .form-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        padding: 2rem;
        margin-bottom: 2rem;
    }
    
    /* Form layout - improved grid structure */
    .user-form {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 1.25rem;
        align-items: end;
    }
    
    /* Form inputs and selects - consistent styling */
    .user-form input,
    .user-form select {
        width: 100%;
        padding: 0 1.125rem;
        height: 50px;
        font-size: 0.95rem;
        border: 1.5px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
        transition: all 0.2s ease;
        font-family: inherit;
    }
    
    .user-form input:focus,
    .user-form select:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    /* Select dropdown styling */
    .user-form select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23374151' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 1rem center;
        padding-right: 3rem;
        cursor: pointer;
    }
    
    /* Password field with checkbox */
    .password-field {
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 0.5rem;
        align-items: center;
    }
    
    .password-field label {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 0.875rem;
        color: #6b7280;
        white-space: nowrap;
        cursor: pointer;
    }
    
    /* Form actions container */
    .form-actions {
        grid-column: 1 / -1;
        display: flex;
        gap: 1rem;
        justify-content: flex-start;
        margin-top: 0.5rem;
    }
    
    /* Buttons - modern design */
    .user-form .btn {
        height: 50px;
        min-width: 140px;
        padding: 0 2rem;
        border-radius: 12px;
        font-weight: 600;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border: none;
        cursor: pointer;
        transition: all 0.2s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }
    
    .user-form .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    }
    
    .user-form .btn:active {
        transform: translateY(0);
    }
    
    /* Add User button - primary green */
    .user-form .btn.btn-add {
        background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
        color: #fff;
        border: none;
    }
    
    .user-form .btn.btn-add:hover {
        background: linear-gradient(135deg, #15803d 0%, #166534 100%);
    }
    
    /* Edit button - blue */
    .user-form .btn.btn-edit {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: #fff;
    }
    
    .user-form .btn.btn-edit:hover {
        background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    }
    
    /* Cancel button - neutral */
    .user-form .btn.btn-cancel {
        background: #f3f4f6;
        color: #374151;
        border: 1.5px solid #e5e7eb;
    }
    
    .user-form .btn.btn-cancel:hover {
        background: #e5e7eb;
    }
    
    /* Section heading */
    .section-heading {
        font-size: 1.5rem;
        font-weight: 700;
        color: #111827;
        margin-bottom: 1.5rem;
        margin-top: 2rem;
    }
    
    /* Table styling */
    .table-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        overflow: hidden;
    }
    
    .table-scroll {
        overflow: auto;
    }
    
    .user-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        min-width: 720px;
    }
    
    .user-table thead th {
        position: sticky;
        top: 0;
        background: linear-gradient(180deg, #f8fafc 0%, #eef2ff 100%);
        color: #1f2937;
        font-weight: 700;
        font-size: 0.875rem;
        text-align: left;
        padding: 1rem;
        border-bottom: 2px solid #e5e7eb;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .user-table tbody td {
        padding: 1rem;
        border-bottom: 1px solid #f3f4f6;
        color: #374151;
    }
    
    .user-table tbody tr:hover {
        background: #f9fafb;
    }
    
    /* Table action buttons */
    .user-table .btn {
        height: 38px;
        min-width: 90px;
        padding: 0 1rem;
        border-radius: 8px;
        font-weight: 600;
        font-size: 0.875rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        cursor: pointer;
        transition: all 0.2s ease;
        border: 1.5px solid;
    }
    
    .user-table .btn.btn-edit {
        background: #eff6ff;
        color: #2563eb;
        border-color: #bfdbfe;
    }
    
    .user-table .btn.btn-edit:hover {
        background: #dbeafe;
        border-color: #93c5fd;
    }
    
    .user-table .btn.btn-delete {
        background: #fef2f2;
        color: #dc2626;
        border-color: #fecaca;
    }
    
    .user-table .btn.btn-delete:hover {
        background: #fee2e2;
        border-color: #fca5a5;
    }
    
    .user-table td.actions {
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .user-table td.actions form {
        display: inline;
    }
    
    /* Responsive adjustments */
    @media (max-width: 768px) {
        .main-content {
            padding: 1rem;
        }
        
        .form-card {
            padding: 1.5rem;
        }
        
        .user-form {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .user-form .btn {
            width: 100%;
        }
    }
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
        <div class="form-card">
        <?php if ($edit_user): ?>
            <h2 style="font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 1.5rem;">
                <i class='bx bx-edit'></i> Edit User
            </h2>
            <form method="post" class="user-form edit-form">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
                <input type="text" name="username" placeholder="Username" value="<?php echo htmlspecialchars($edit_user['username']); ?>" required>
                <input type="email" name="email" placeholder="Email" value="<?php echo htmlspecialchars($edit_user['email']); ?>" required>
                <select name="role" required>
                    <option value="">Select Role</option>
                    <option value="staff" <?php if ($edit_user['role']==='staff') echo 'selected'; ?>>Staff</option>
                    <option value="delivery_staff" <?php if ($edit_user['role']==='delivery_staff') echo 'selected'; ?>>Delivery Staff</option>
                    <option value="admin" <?php if ($edit_user['role']==='admin') echo 'selected'; ?>>Admin</option>
                </select>
                <select name="status" required>
                    <option value="">Select Status</option>
                    <option value="active" <?php if ($edit_user['status']==='active') echo 'selected'; ?>>Active</option>
                    <option value="inactive" <?php if ($edit_user['status']==='inactive') echo 'selected'; ?>>Inactive</option>
                </select>
                <div class="form-actions">
                    <button type="submit" name="edit_user" class="btn btn-edit">
                        <i class='bx bx-save'></i> Update User
                    </button>
                    <a href="users.php" class="btn btn-cancel">
                        <i class='bx bx-x'></i> Cancel
                    </a>
                </div>
            </form>
        <?php else: ?>
            <h2 style="font-size: 1.25rem; font-weight: 700; color: #111827; margin-bottom: 1.5rem;">
                <i class='bx bx-user-plus'></i> Add New User
            </h2>
            <form method="post" class="user-form add-form">
                <input type="hidden" name="add_user" value="1">
                <input type="text" name="username" placeholder="Username" required>
                <input type="email" name="email" placeholder="Email" required>
                <div class="password-field">
                    <input id="pw" type="password" name="password" placeholder="Password" required>
                    <label><input type="checkbox" id="showPw"> Show</label>
                </div>
                <input id="cpw" type="password" name="confirm_password" placeholder="Confirm Password" required>
                <select name="role" required>
                    <option value="">Select Role</option>
                    <option value="staff">Staff</option>
                    <option value="delivery_staff">Delivery Staff</option>
                    <option value="admin">Admin</option>
                </select>
                <select name="status" required>
                    <option value="">Select Status</option>
                    <option value="active" selected>Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <div class="form-actions">
                    <button type="submit" name="add_user" class="btn btn-add">
                        <i class='bx bx-plus'></i> Add User
                    </button>
                </div>
            </form>
        <?php endif; ?>
        </div>
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
        <h3 class="section-heading">Existing Users</h3>
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