
<?php
session_start();
require_once '../config/db.php';

// Only admin can access this page
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$messageType = 'success';

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf = $_POST['csrf_token'] ?? '';

    if (!is_string($csrf) ||
        !hash_equals($_SESSION['csrf_token'], $csrf)) {

        $message = "Invalid security request. Please refresh the page.";
        $messageType = 'error';

    } else {

        $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$userId || $userId <= 0) {

            $message = "Please select a valid user.";
            $messageType = 'error';

        } elseif (strlen($newPassword) < 8) {

            $message = "Password must contain at least 8 characters.";
            $messageType = 'error';

        } elseif ($newPassword !== $confirmPassword) {

            $message = "New password and confirm password do not match.";
            $messageType = 'error';

        } else {

            // Only voter and candidate accounts can be reset here
            $check = $conn->prepare(
                "SELECT id, name, role
                 FROM users
                 WHERE id = ? AND role IN ('voter', 'candidate')
                 LIMIT 1"
            );

            $check->bind_param("i", $userId);
            $check->execute();
            $check->bind_result($targetId, $targetName, $targetRole);

            $userExists = $check->fetch();
            $check->close();

            if (!$userExists) {

                $message = "Selected voter or candidate account was not found.";
                $messageType = 'error';

            } else {

                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                $update = $conn->prepare(
                    "UPDATE users SET password = ? WHERE id = ?"
                );

                $update->bind_param(
                    "si",
                    $hashedPassword,
                    $targetId
                );

                if ($update->execute() && $update->affected_rows >= 0) {

                    $message = "Password successfully reset for " .
                               $targetName . " (" . $targetRole . ").";
                    $messageType = 'success';

                } else {

                    $message = "Unable to update password. Please try again.";
                    $messageType = 'error';
                }

                $update->close();
            }
        }
    }
}

// Fetch voter and candidate accounts
$users = [];

$result = $conn->query(
    "SELECT id, name, email, role, created_at
     FROM users
     WHERE role IN ('voter', 'candidate')
     ORDER BY id DESC"
);

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Users | VotePortal</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #FFF7ED;
    color: #292524;
    min-height: 100vh;
}

.navbar {
    background: white;
    border-bottom: 1px solid #FED7AA;
    padding: 20px 7%;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.logo {
    font-size: 27px;
    font-weight: 800;
    color: #9A3412;
    text-decoration: none;
}

.logo span {
    color: #EA580C;
}

.nav-links a {
    color: #C2410C;
    font-size: 14px;
    font-weight: bold;
    text-decoration: none;
    margin-left: 20px;
}

.container {
    width: 92%;
    max-width: 1200px;
    margin: 45px auto;
}

.heading {
    margin-bottom: 25px;
}

.heading h1 {
    font-size: 32px;
    margin-bottom: 10px;
}

.heading p {
    color: #78716C;
    line-height: 1.6;
}

.card {
    background: white;
    border: 1px solid #FED7AA;
    border-radius: 17px;
    padding: 28px;
    margin-bottom: 25px;
    box-shadow: 0 10px 30px rgba(154,52,18,.05);
}

.card h2 {
    font-size: 21px;
    margin-bottom: 20px;
}

.message {
    padding: 14px 17px;
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
    font-weight: bold;
    font-size: 14px;
    margin-bottom: 9px;
}

select, input {
    width: 100%;
    height: 49px;
    padding: 0 13px;
    border: 1px solid #E7D9CC;
    border-radius: 8px;
    background: #FFFCF8;
    color: #292524;
    font-size: 14px;
    outline: none;
}

select:focus, input:focus {
    border-color: #EA580C;
    box-shadow: 0 0 0 3px #FFEDD5;
}

.submit-btn {
    border: none;
    border-radius: 8px;
    padding: 15px 25px;
    background: #C2410C;
    color: white;
    font-size: 15px;
    font-weight: bold;
    cursor: pointer;
}

.submit-btn:hover {
    background: #9A3412;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th {
    background: #F5D2B8;
    color: #7C2D12;
    padding: 15px;
    text-align: left;
    font-size: 13px;
}

td {
    padding: 15px;
    border-bottom: 1px solid #F3E2D4;
    font-size: 14px;
}

.role {
    display: inline-block;
    padding: 6px 11px;
    border-radius: 20px;
    background: #FFEDD5;
    color: #9A3412;
    font-size: 12px;
    font-weight: bold;
    text-transform: capitalize;
}

.footer {
    text-align: center;
    color: #78716C;
    padding: 25px;
    font-size: 13px;
}

@media(max-width:700px) {
    .navbar {
        padding: 17px 5%;
    }

    .container {
        margin: 28px auto;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 0;
    }

    .card {
        padding: 20px;
    }

    .heading h1 {
        font-size: 26px;
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
        <h1>Manage Users</h1>
        <p>View registered voters and candidates and securely reset their passwords.</p>
    </div>

    <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="card">

        <h2>Reset User Password</h2>

        <form method="POST">

            <input type="hidden" name="csrf_token"
                   value="<?= e($_SESSION['csrf_token']) ?>">

            <div class="form-group">
                <label for="user_id">Select Voter / Candidate</label>

                <select name="user_id" id="user_id" required>
                    <option value="">-- Select User --</option>

                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int)$user['id'] ?>">
                            <?= e($user['name']) ?>
                            (<?= e($user['email']) ?> -
                            <?= e(ucfirst($user['role'])) ?>)
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-grid">

                <div class="form-group">
                    <label for="new_password">New Password</label>

                    <input
                        type="password"
                        name="new_password"
                        id="new_password"
                        placeholder="Enter new password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>

                    <input
                        type="password"
                        name="confirm_password"
                        id="confirm_password"
                        placeholder="Confirm new password"
                        minlength="8"
                        autocomplete="new-password"
                        required
                    >
                </div>

            </div>

            <button type="submit" class="submit-btn">
                Reset Password →
            </button>

        </form>

    </section>

    <section class="card">

        <h2>Registered Users (<?= count($users) ?>)</h2>

        <div class="table-wrapper">

            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email Address</th>
                        <th>Role</th>
                        <th>Registered On</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($users) > 0): ?>

                    <?php foreach ($users as $user): ?>

                        <tr>
                            <td><?= (int)$user['id'] ?></td>
                            <td><?= e($user['name']) ?></td>
                            <td><?= e($user['email']) ?></td>
                            <td>
                                <span class="role">
                                    <?= e($user['role']) ?>
                                </span>
                            </td>
                            <td><?= e($user['created_at']) ?></td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="5" style="text-align:center;">
                            No registered users found.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>

        </div>

    </section>

</main>

<footer class="footer">
    © <?= date('Y') ?> VotePortal - Admin Panel
</footer>

</body>
</html>
