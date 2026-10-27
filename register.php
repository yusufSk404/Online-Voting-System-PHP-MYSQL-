
<?php
require_once 'config/db.php';

$error = "";
$success = "";

if (isset($_POST['register'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($password) < 8) {
        $error = "Password must contain at least 8 characters.";
    } elseif (!in_array($role, ['voter', 'candidate', 'admin'], true)) {
        $error = "Please select a valid account type.";
    } else {
        try {
            $check = $conn->prepare(
                "SELECT id FROM users WHERE email = ? LIMIT 1"
            );

            $check->bind_param("s", $email);
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $error = "Email already registered. Please login.";
                $check->close();
            } else {
                $check->close();

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare(
                    "INSERT INTO users (name, email, password, role)
                     VALUES (?, ?, ?, ?)"
                );

                $stmt->bind_param(
                    "ssss",
                    $name,
                    $email,
                    $hashedPassword,
                    $role
                );

                if ($stmt->execute()) {
                    $success = "Registration successful! Please login.";
                    $_POST = [];
                } else {
                    $error = "Registration failed. Please try again.";
                }

                $stmt->close();
            }
        } catch (mysqli_sql_exception $ex) {
            error_log("VotePortal registration error: " . $ex->getMessage());
            $error = "Unable to create your account. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | VotePortal</title>

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
    min-height: 100vh;
}

.navbar {
    background: #FFFFFF;
    padding: 20px 7%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #FED7AA;
}

.logo {
    color: #9A3412;
    font-size: 25px;
    font-weight: bold;
}

.logo span {
    color: #EA580C;
}

.navbar a {
    color: #C2410C;
    text-decoration: none;
    font-weight: bold;
}

.main {
    max-width: 1100px;
    margin: 45px auto;
    padding: 20px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 35px;
    align-items: center;
}

.welcome {
    padding: 25px;
}

.tag {
    display: inline-block;
    background: #FFEDD5;
    color: #9A3412;
    padding: 9px 15px;
    border-radius: 25px;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 22px;
}

.welcome h1 {
    font-size: 42px;
    line-height: 1.2;
    margin-bottom: 18px;
}

.welcome h1 span {
    color: #EA580C;
}

.welcome p {
    color: #78716C;
    line-height: 1.8;
}

.features {
    display: grid;
    gap: 15px;
    margin-top: 28px;
}

.feature {
    display: flex;
    align-items: center;
    gap: 12px;
    font-weight: bold;
    font-size: 14px;
}

.feature-icon {
    background: #FFEDD5;
    color: #C2410C;
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
}

.form-card {
    background: white;
    padding: 35px;
    border-radius: 18px;
    border: 1px solid #FED7AA;
    box-shadow: 0 12px 35px rgba(154, 52, 18, 0.08);
}

.form-card h2 {
    font-size: 28px;
    margin-bottom: 8px;
}

.subtitle {
    color: #78716C;
    font-size: 14px;
    margin-bottom: 22px;
}

.message {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 15px;
    font-size: 14px;
    line-height: 1.5;
}

.error {
    background: #FFF1F2;
    color: #BE123C;
    border: 1px solid #FECDD3;
}

.success {
    background: #DCFCE7;
    color: #166534;
    border: 1px solid #BBF7D0;
}

.message a {
    color: inherit;
    font-weight: bold;
}

label {
    display: block;
    font-size: 14px;
    font-weight: bold;
    margin: 16px 0 8px;
}

input, select {
    width: 100%;
    padding: 13px;
    border: 1px solid #E7D9CC;
    border-radius: 8px;
    font-size: 15px;
    background: #FFFCF8;
    color: #292524;
    outline: none;
}

input:focus, select:focus {
    border-color: #EA580C;
    box-shadow: 0 0 0 3px #FFEDD5;
}

.submit-btn {
    width: 100%;
    padding: 14px;
    margin-top: 23px;
    border: none;
    border-radius: 8px;
    background: #C2410C;
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
    transition: background .2s;
}

.submit-btn:hover {
    background: #9A3412;
}

.login-text {
    text-align: center;
    margin-top: 20px;
    font-size: 14px;
    color: #78716C;
}

.login-text a {
    color: #C2410C;
    font-weight: bold;
    text-decoration: none;
}

.login-text a:hover {
    text-decoration: underline;
}

.footer {
    text-align: center;
    color: #78716C;
    font-size: 13px;
    padding: 20px;
}

@media (max-width: 750px) {
    .navbar {
        padding: 18px 5%;
    }

    .main {
        grid-template-columns: 1fr;
        margin: 15px auto;
        padding: 15px;
        gap: 15px;
    }

    .welcome {
        padding: 15px 5px;
    }

    .welcome h1 {
        font-size: 32px;
    }

    .form-card {
        padding: 25px 20px;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <div class="logo">Vote<span>Portal</span></div>
    <a href="login.php">Login →</a>
</header>

<main class="main">

    <section class="welcome">
        <div class="tag">YOUR VOICE MATTERS</div>

        <h1>
            Every vote counts.<br>
            <span>Make yours matter.</span>
        </h1>

        <p>
            Create your account to explore elections, view candidates
            and participate in the voting process.
        </p>

        <div class="features">
            <div class="feature">
                <div class="feature-icon">✓</div>
                Secure account registration
            </div>

            <div class="feature">
                <div class="feature-icon">♙</div>
                Easy candidate selection
            </div>

            <div class="feature">
                <div class="feature-icon">▣</div>
                One vote per election
            </div>
        </div>
    </section>

    <section class="form-card">
        <h2>Create Account</h2>
        <p class="subtitle">Fill in your details to get started.</p>

        <?php if ($error !== ''): ?>
            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="message success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                <a href="login.php">Login here</a>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <label for="name">Full Name</label>
            <input
                type="text"
                id="name"
                name="name"
                placeholder="Enter your full name"
                maxlength="100"
                value="<?= htmlspecialchars($_POST['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                required
            >

            <label for="email">Email Address</label>
            <input
                type="email"
                id="email"
                name="email"
                placeholder="you@example.com"
                maxlength="150"
                value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                required
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                placeholder="At least 8 characters"
                minlength="8"
                required
            >

            <label for="role">Register As</label>
            <select id="role" name="role" required>
                <option value="" disabled selected>Select account type</option>
                <option value="voter">Voter</option>
                <option value="candidate">Candidate</option>
                <option value="admin">Admin</option>
            </select>

            <button type="submit" name="register" class="submit-btn">
                Create My Account →
            </button>
        </form>

        <p class="login-text">
            Already registered?
            <a href="login.php">Login here</a>
        </p>
    </section>

</main>

<footer class="footer">
    © 2026 VotePortal | Online Voting System
</footer>

</body>
</html>
