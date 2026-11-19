
<?php
session_start();
require_once '../config/db.php';

// Only existing admins can access this page
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Asia/Kolkata');

$currentAdminId = (int)$_SESSION['user_id'];
$message = '';
$messageType = 'success';

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        $message = "Invalid security request. Refresh the page.";
        $messageType = 'error';

    } else {

        $action = $_POST['action'] ?? '';

        // CREATE ADMIN
        if ($action === 'create_admin') {

            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!is_string($password) || !is_string($confirmPassword)) {
                $message = "Invalid password input.";
                $messageType = 'error';

            } elseif ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
                $message = "Please fill in all fields.";
                $messageType = 'error';

            } elseif (strlen($name) > 100) {
                $message = "Name must not exceed 100 characters.";
                $messageType = 'error';

            } elseif (strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Please enter a valid email address.";
                $messageType = 'error';

            } elseif (strlen($password) < 8) {
                $message = "Password must contain at least 8 characters.";
                $messageType = 'error';

            } elseif ($password !== $confirmPassword) {
                $message = "Passwords do not match.";
                $messageType = 'error';

            } else {

                try {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                    if ($hashedPassword === false) {
                        throw new Exception("Password hashing failed.");
                    }

                    $stmt = $conn->prepare(
                        "INSERT INTO users (name, email, password, role)
                         VALUES (?, ?, ?, 'admin')"
                    );

                    if (!$stmt) {
                        throw new Exception("Unable to prepare admin creation.");
                    }

                    $stmt->bind_param("sss", $name, $email, $hashedPassword);

                    if ($stmt->execute()) {
                        $message = "New Admin account created successfully.";
                        $messageType = 'success';
                    }

                    $stmt->close();

                } catch (mysqli_sql_exception $ex) {

                    if ($ex->getCode() === 1062) {
                        $message = "This email address is already registered.";
                    } else {
                        error_log("Admin creation error: " . $ex->getMessage());
                        $message = "Unable to create Admin account.";
                    }

                    $messageType = 'error';

                } catch (Exception $ex) {
                    error_log("Admin creation error: " . $ex->getMessage());
                    $message = "Unable to create Admin account.";
                    $messageType = 'error';
                }
            }
        }

        // RESET ANOTHER ADMIN'S PASSWORD
        elseif ($action === 'reset_admin_password') {

            $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if (!is_string($newPassword) || !is_string($confirmPassword)) {
                $message = "Invalid password input.";
                $messageType = 'error';

            } elseif (!$targetId || $targetId <= 0) {
                $message = "Invalid Admin account.";
                $messageType = 'error';

            } elseif ($targetId === $currentAdminId) {
                $message = "You cannot reset your own password here. Use the account password change process.";
                $messageType = 'error';

            } elseif (strlen($newPassword) < 8) {
                $message = "Password must contain at least 8 characters.";
                $messageType = 'error';

            } elseif ($newPassword !== $confirmPassword) {
                $message = "Passwords do not match.";
                $messageType = 'error';

            } else {

                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                $stmt = $conn->prepare(
                    "UPDATE users SET password = ?
                     WHERE id = ? AND role = 'admin'"
                );

                if ($stmt) {
                    $stmt->bind_param("si", $hashedPassword, $targetId);

                    if ($stmt->execute() && $stmt->affected_rows === 1) {
                        $message = "Admin password updated successfully.";
                    } else {
                        $message = "Admin not found or password was unchanged.";
                        $messageType = 'error';
                    }

                    $stmt->close();
                } else {
                    $message = "Unable to prepare password update.";
                    $messageType = 'error';
                }
            }
        }

        // DELETE ANOTHER ADMIN
        elseif ($action === 'delete_admin') {

            $targetId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);

            if (!$targetId || $targetId <= 0) {
                $message = "Invalid Admin account.";
                $messageType = 'error';

            } elseif ($targetId === $currentAdminId) {
                $message = "You cannot delete your own Admin account.";
                $messageType = 'error';

            } else {

                try {
                    // Never allow deletion of the last Admin
                    $countResult = $conn->query(
                        "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'"
                    );

                    $adminCount = (int)$countResult->fetch_assoc()['total'];

                    if ($adminCount <= 1) {
                        $message = "The last Admin account cannot be deleted.";
                        $messageType = 'error';

                    } else {

                        // Protect accounts linked to important records
                        $check = $conn->prepare(
                            "SELECT
                                (SELECT COUNT(*) FROM elections WHERE created_by = ?) AS elections_count,
                                (SELECT COUNT(*) FROM candidates WHERE user_id = ?) AS candidates_count,
                                (SELECT COUNT(*) FROM votes WHERE voter_id = ?) AS votes_count"
                        );

                        if (!$check) {
                            throw new Exception("Unable to check Admin dependencies.");
                        }

                        $check->bind_param("iii", $targetId, $targetId, $targetId);
                        $check->execute();
                        $dependency = $check->get_result()->fetch_assoc();
                        $check->close();

                        if (
                            (int)$dependency['elections_count'] > 0 ||
                            (int)$dependency['candidates_count'] > 0 ||
                            (int)$dependency['votes_count'] > 0
                        ) {
                            $message = "This Admin has related election or voting records. Review those records before deletion.";
                            $messageType = 'error';

                        } else {

                            $delete = $conn->prepare(
                                "DELETE FROM users
                                 WHERE id = ?
                                 AND role = 'admin'
                                 AND id <> ?"
                            );

                            if (!$delete) {
                                throw new Exception("Unable to prepare Admin deletion.");
                            }

                            $delete->bind_param("ii", $targetId, $currentAdminId);

                            if ($delete->execute() && $delete->affected_rows === 1) {
                                $message = "Admin account deleted successfully.";
                                $messageType = 'success';
                            } else {
                                $message = "Admin account could not be deleted.";
                                $messageType = 'error';
                            }

                            $delete->close();
                        }
                    }

                } catch (Throwable $ex) {
                    error_log("Admin deletion error: " . $ex->getMessage());
                    $message = "Unable to delete Admin. Related database records may prevent deletion.";
                    $messageType = 'error';
                }
            }
        }

        else {
            $message = "Invalid action.";
            $messageType = 'error';
        }

        // Refresh CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// Fetch all Admin accounts
$admins = [];

$result = $conn->query(
    "SELECT id, name, email, created_at
     FROM users
     WHERE role = 'admin'
     ORDER BY id ASC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Admins | VotePortal</title>

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #FFF7ED;
    color: #292524;
}

.navbar {
    background: white;
    padding: 20px 7%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #FED7AA;
}

.logo {
    font-size: 25px;
    font-weight: bold;
    color: #9A3412;
    text-decoration: none;
}

.logo span {
    color: #EA580C;
}

.nav-links a {
    color: #C2410C;
    text-decoration: none;
    font-weight: bold;
    margin-left: 20px;
    font-size: 14px;
}

.container {
    width: 92%;
    max-width: 1150px;
    margin: 40px auto;
}

.heading {
    margin-bottom: 25px;
}

.heading h1 {
    font-size: 30px;
    margin-bottom: 9px;
}

.heading p {
    color: #78716C;
    line-height: 1.6;
}

.card {
    background: white;
    border: 1px solid #FED7AA;
    border-radius: 16px;
    padding: 28px;
    margin-bottom: 25px;
    box-shadow: 0 8px 25px rgba(154,52,18,.05);
}

.card h2 {
    font-size: 21px;
    margin-bottom: 20px;
}

.message {
    padding: 14px;
    border-radius: 9px;
    margin-bottom: 22px;
    font-size: 14px;
    line-height: 1.5;
}

.success {
    background: #ECFDF3;
    color: #166534;
    border: 1px solid #BBF7D0;
}

.error {
    background: #FFF1F2;
    color: #BE123C;
    border: 1px solid #FECDD3;
}

.warning {
    background: #FFF7ED;
    color: #9A3412;
    border: 1px solid #FED7AA;
    padding: 14px;
    border-radius: 9px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.6;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}

.form-group {
    margin-bottom: 18px;
}

label {
    display: block;
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 9px;
}

input {
    width: 100%;
    height: 48px;
    padding: 0 13px;
    border: 1px solid #E7D9CC;
    border-radius: 8px;
    background: #FFFCF8;
    font-size: 14px;
    outline: none;
}

input:focus {
    border-color: #EA580C;
    box-shadow: 0 0 0 3px #FFEDD5;
}

.btn {
    border: none;
    border-radius: 8px;
    padding: 13px 20px;
    background: #C2410C;
    color: white;
    font-size: 14px;
    font-weight: bold;
    cursor: pointer;
}

.btn:hover {
    background: #9A3412;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 700px;
    border-collapse: collapse;
}

th {
    background: #F5D2B8;
    color: #7C2D12;
    padding: 14px;
    text-align: left;
    font-size: 13px;
}

td {
    padding: 14px;
    border-bottom: 1px solid #F3E2D4;
    font-size: 14px;
}

.role {
    display: inline-block;
    background: #FFEDD5;
    color: #9A3412;
    padding: 6px 11px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.action-form {
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-width: 170px;
}

.action-form input {
    height: 36px;
    font-size: 12px;
}

.small-btn {
    padding: 8px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
}

.reset-btn {
    background: #FFEDD5;
    color: #9A3412;
}

.delete-btn {
    background: #FEE2E2;
    color: #B91C1C;
}

.footer {
    text-align: center;
    color: #78716C;
    padding: 25px;
    font-size: 13px;
}

@media(max-width: 700px) {

    .form-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .card {
        padding: 20px;
    }

    .container {
        margin: 25px auto;
    }

    .navbar {
        padding: 17px 5%;
    }
}

</style>

</head>

<body>

<header class="navbar">

    <a href="dashboard.php" class="logo">
        Vote<span>Portal</span>
    </a>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="../logout.php">Logout</a>
    </div>

</header>

<main class="container">

    <div class="heading">
        <h1>Manage Admins</h1>
        <p>Create and manage authorized VotePortal administrators.</p>
    </div>

    <?php if ($message !== ''): ?>

        <div class="message <?= e($messageType) ?>" role="alert">
            <?= e($message) ?>
        </div>

    <?php endif; ?>

    <!-- CREATE ADMIN -->

    <section class="card">

        <h2>Create New Admin</h2>

        <div class="warning">
            <strong>Security notice:</strong>
            Only create Admin accounts for trusted people.
            Admins have access to sensitive election and user-management features.
        </div>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <input type="hidden" name="action"
                   value="create_admin">

            <div class="form-grid">

                <div class="form-group">
                    <label for="name">Full Name</label>

                    <input
                        type="text"
                        name="name"
                        id="name"
                        maxlength="100"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="email">Email Address</label>

                    <input
                        type="email"
                        name="email"
                        id="email"
                        maxlength="150"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>

                    <input
                        type="password"
                        name="password"
                        id="password"
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="Minimum 8 characters"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>

                    <input
                        type="password"
                        name="confirm_password"
                        id="confirm_password"
                        minlength="8"
                        autocomplete="new-password"
                        placeholder="Confirm password"
                        required
                    >
                </div>

            </div>

            <button type="submit" class="btn">
                Create Admin Account →
            </button>

        </form>

    </section>

    <!-- REGISTERED ADMINS -->

    <section class="card">

        <h2>Registered Admins (<?= count($admins) ?>)</h2>

        <div class="warning">
            You cannot delete your own account or the last remaining Admin.
            Admins linked to election or voting records are protected from deletion.
        </div>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($admins) > 0): ?>

                    <?php foreach ($admins as $admin): ?>

                        <tr>

                            <td><?= (int)$admin['id'] ?></td>

                            <td><?= e($admin['name']) ?></td>

                            <td><?= e($admin['email']) ?></td>

                            <td>
                                <span class="role">Admin</span>
                            </td>

                            <td><?= e($admin['created_at']) ?></td>

                            <td>

                                <?php if ((int)$admin['id'] === $currentAdminId): ?>

                                    <strong>You (Current Admin)</strong>

                                <?php else: ?>

                                    <!-- RESET ADMIN PASSWORD -->

                                    <form method="POST"
                                          class="action-form"
                                          onsubmit="return confirm('Reset this Admin password?');">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden" name="action"
                                               value="reset_admin_password">

                                        <input type="hidden" name="user_id"
                                               value="<?= (int)$admin['id'] ?>">

                                        <input type="password"
                                               name="new_password"
                                               minlength="8"
                                               placeholder="New password (8+)"
                                               autocomplete="new-password"
                                               required>

                                        <input type="password"
                                               name="confirm_password"
                                               minlength="8"
                                               placeholder="Confirm password"
                                               autocomplete="new-password"
                                               required>

                                        <button class="small-btn reset-btn" type="submit">
                                            Reset Password
                                        </button>

                                    </form>

                                    <!-- DELETE ADMIN -->

                                    <form method="POST"
                                          onsubmit="return confirm('Permanently delete this Admin account? This cannot be undone.');"
                                          style="margin-top:8px;">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden" name="action"
                                               value="delete_admin">

                                        <input type="hidden" name="user_id"
                                               value="<?= (int)$admin['id'] ?>">

                                        <button class="small-btn delete-btn" type="submit">
                                            Delete Admin
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="6">No Admin accounts found.</td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<footer class="footer">
    © <?= date('Y') ?> VotePortal | Admin Panel
</footer>

</body>
</html>
