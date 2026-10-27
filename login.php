<?php
session_start();
require_once 'config/db.php';

$error = "";

if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare(
        "SELECT id, name, password, role FROM users WHERE email = ?"
    );

    if ($stmt) {
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 1) {
            $stmt->bind_result($id, $name, $hashedPassword, $role);
            $stmt->fetch();

            if (password_verify($password, $hashedPassword)) {
                session_regenerate_id(true);

                $_SESSION['user_id'] = $id;
                $_SESSION['name'] = $name;
                $_SESSION['role'] = $role;

                if ($role === "admin") {
                    header("Location: admin/dashboard.php");
                } elseif ($role === "candidate") {
                    header("Location: candidate/dashboard.php");
                } else {
                    header("Location: voter/dashboard.php");
                }
                exit;
            } else {
                $error = "Incorrect email or password.";
            }
        } else {
            $error = "Incorrect email or password.";
        }

        $stmt->close();
    } else {
        $error = "Something went wrong. Please try again.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | VotePortal</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --cream: #fff7ed;
    --orange: #c2410c;
    --orange-dark: #9a3412;
    --peach: #ffedd5;
    --border: #fed7aa;
    --text: #292524;
    --muted: #78716c;
    --white: #ffffff;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: var(--cream);
    color: var(--text);
    min-height: 100vh;
}

/* NAVBAR */
.navbar {
    width: 100%;
    min-height: 78px;
    background: var(--white);
    padding: 18px 7%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid var(--border);
}

.logo {
    font-size: 29px;
    font-weight: 800;
    color: var(--orange-dark);
    text-decoration: none;
    letter-spacing: -0.8px;
}

.logo span {
    color: #ea580c;
}

.navbar a.create-account {
    color: var(--orange);
    text-decoration: none;
    font-size: 16px;
    font-weight: 700;
}

.navbar a.create-account:hover {
    color: var(--orange-dark);
}

/* MAIN LAYOUT */
.main {
    width: 100%;
    min-height: calc(100vh - 78px);
    margin: 0 auto;
    padding: 55px 8%;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 65px;
    align-items: center;
}

/* WELCOME SECTION */
.welcome {
    width: 100%;
    max-width: 600px;
    justify-self: center;
}

.tag {
    display: inline-block;
    background: var(--peach);
    color: var(--orange-dark);
    padding: 10px 19px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.3px;
    margin-bottom: 24px;
}

.welcome h1 {
    font-size: clamp(42px, 3.7vw, 56px);
    line-height: 1.17;
    letter-spacing: -1.5px;
    margin-bottom: 22px;
    color: var(--text);
}

.welcome h1 span {
    color: #ea580c;
}

.welcome p {
    color: var(--muted);
    font-size: 17px;
    line-height: 1.85;
    max-width: 550px;
}

/* LOGIN CARD */
.form-card {
    width: 100%;
    max-width: 590px;
    justify-self: center;
    background: var(--white);
    padding: 44px 46px;
    border: 1px solid var(--border);
    border-radius: 23px;
    box-shadow: 0 20px 60px rgba(154, 52, 18, 0.09);
}

.form-card h2 {
    font-size: 35px;
    line-height: 1.3;
    margin-bottom: 10px;
    color: var(--text);
}

.subtitle {
    color: var(--muted);
    margin-bottom: 28px;
    font-size: 16px;
    line-height: 1.6;
}

/* ERROR MESSAGE */
.message {
    background: #fff1f2;
    color: #be123c;
    padding: 14px 16px;
    border: 1px solid #fecdd3;
    border-radius: 10px;
    margin-bottom: 22px;
    font-size: 14px;
    line-height: 1.5;
}

/* FORM */
form {
    width: 100%;
}

label {
    display: block;
    font-size: 15px;
    font-weight: 700;
    margin: 20px 0 9px;
    color: var(--text);
}

input {
    display: block;
    width: 100%;
    height: 56px;
    padding: 0 17px;
    border: 1px solid #e7d9cc;
    border-radius: 10px;
    background: #fffcf8;
    font-family: inherit;
    font-size: 16px;
    color: var(--text);
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

input::placeholder {
    color: #8a817a;
}

input:focus {
    border-color: #ea580c;
    box-shadow: 0 0 0 4px rgba(255, 237, 213, 0.9);
}

/* LOGIN BUTTON */
.submit-btn {
    display: block;
    width: 100%;
    min-height: 57px;
    padding: 15px 18px;
    margin-top: 28px;
    border: none;
    border-radius: 10px;
    background: var(--orange);
    color: var(--white);
    font-family: inherit;
    font-size: 16px;
    font-weight: 800;
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
}

.submit-btn:hover {
    background: var(--orange-dark);
    transform: translateY(-2px);
}

.submit-btn:active {
    transform: translateY(0);
}

/* REGISTER LINK */
.register-text {
    text-align: center;
    margin-top: 24px;
    color: var(--muted);
    font-size: 15px;
    line-height: 1.7;
}

.register-text a {
    color: var(--orange);
    text-decoration: none;
    font-weight: 800;
}

.register-text a:hover {
    color: var(--orange-dark);
    text-decoration: underline;
}

/* TABLET */
@media (max-width: 1000px) {
    .main {
        padding: 45px 5%;
        gap: 35px;
    }

    .form-card {
        padding: 36px 30px;
    }

    .welcome h1 {
        font-size: 43px;
    }

    .welcome p {
        font-size: 16px;
    }
}

/* MOBILE */
@media (max-width: 750px) {
    .navbar {
        min-height: 70px;
        padding: 17px 5%;
    }

    .logo {
        font-size: 25px;
    }

    .navbar a.create-account {
        font-size: 14px;
    }

    .main {
        min-height: auto;
        grid-template-columns: 1fr;
        padding: 40px 6% 55px;
        gap: 30px;
    }

    .welcome {
        max-width: 590px;
        text-align: center;
    }

    .tag {
        margin-bottom: 19px;
    }

    .welcome h1 {
        font-size: clamp(35px, 7vw, 45px);
        letter-spacing: -1px;
        margin-bottom: 17px;
    }

    .welcome p {
        font-size: 16px;
        line-height: 1.8;
        margin: 0 auto;
    }

    .form-card {
        max-width: 590px;
        padding: 35px 28px;
    }

    .form-card h2 {
        font-size: 30px;
    }
}

/* SMALL MOBILE */
@media (max-width: 420px) {
    .main {
        padding: 30px 16px 40px;
        gap: 25px;
    }

    .form-card {
        padding: 27px 20px;
        border-radius: 16px;
    }

    .form-card h2 {
        font-size: 27px;
    }

    .subtitle {
        font-size: 14px;
        margin-bottom: 22px;
    }

    label {
        font-size: 14px;
    }

    input {
        height: 52px;
        font-size: 15px;
    }

    .submit-btn {
        min-height: 53px;
        font-size: 15px;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a href="index.php" class="logo">Vote<span>Portal</span></a>

    <a href="register.php" class="create-account">
        Create Account →
    </a>
</header>

<main class="main">

    <section class="welcome">
        <div class="tag">WELCOME TO VOTEPORTAL</div>

        <h1>
            Your Voice.<br>
            <span>Your Vote. Your Power.</span>
        </h1>

        <p>
            A smarter way to participate in elections. Explore candidates,
            make informed choices, and cast your vote through a simple
            and accessible digital voting experience.
        </p>
    </section>

    <section class="form-card">
        <h2>Welcome Back!</h2>

        <p class="subtitle">
            Enter your account details to login.
        </p>

        <?php if ($error !== ""): ?>
            <div class="message" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <label for="email">Email Address</label>

            <input
                type="email"
                id="email"
                name="email"
                placeholder="you@example.com"
                autocomplete="email"
                value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                required
            >

            <label for="password">Password</label>

            <input
                type="password"
                id="password"
                name="password"
                placeholder="Enter your password"
                autocomplete="current-password"
                required
            >

            <button type="submit" name="login" class="submit-btn">
                Login to Account →
            </button>
        </form>

        <p class="register-text">
            Don't have an account?
            <a href="register.php">Register here</a>
        </p>
    </section>

</main>

</body>
</html>
