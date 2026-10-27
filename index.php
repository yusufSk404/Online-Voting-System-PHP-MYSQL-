<?php
session_start();

$isLoggedIn = isset($_SESSION['user_id']);
$role = $_SESSION['role'] ?? '';
$name = $_SESSION['name'] ?? 'User';

$dashboard = 'login.php';

if ($isLoggedIn) {
    if ($role === 'admin') {
        $dashboard = 'admin/dashboard.php';
    } elseif ($role === 'candidate') {
        $dashboard = 'candidate/dashboard.php';
    } else {
        $dashboard = 'voter/dashboard.php';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VotePortal | Online Voting System</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --cream: #fffaf3;
            --peach: #f8e4d5;
            --orange: #c96843;
            --dark: #382b25;
            --muted: #776b64;
            --white: #ffffff;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--cream);
            color: var(--dark);
            line-height: 1.6;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .navbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 7%;
            background: rgba(255, 250, 243, 0.97);
            border-bottom: 1px solid #f0e3d8;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .logo {
            font-size: 25px;
            font-weight: 800;
            color: var(--orange);
        }

        .logo span {
            color: var(--dark);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 28px;
        }

        .nav-links a {
            font-size: 14px;
            font-weight: 600;
            transition: 0.2s;
        }

        .nav-links a:hover {
            color: var(--orange);
        }

        .btn {
            display: inline-block;
            padding: 12px 22px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            border: 1px solid var(--orange);
            transition: 0.2s;
        }

        .btn-primary {
            background: var(--orange);
            color: white;
        }

        .btn-primary:hover {
            background: #ad5030;
            transform: translateY(-2px);
        }

        .btn-outline {
            color: var(--orange);
            background: transparent;
        }

        .btn-outline:hover {
            background: var(--peach);
        }

        .hero {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            align-items: center;
            gap: 55px;
            padding: 85px 7% 90px;
            min-height: 570px;
        }

        .eyebrow {
            display: inline-block;
            background: var(--peach);
            color: #9e4c2e;
            padding: 8px 14px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 1px;
            margin-bottom: 22px;
        }

        .hero h1 {
            font-size: clamp(38px, 5vw, 61px);
            line-height: 1.12;
            letter-spacing: -1.8px;
            margin-bottom: 22px;
        }

        .hero h1 span {
            color: var(--orange);
        }

        .hero p {
            color: var(--muted);
            font-size: 16px;
            max-width: 540px;
            margin-bottom: 30px;
        }

        .hero-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 13px;
        }

        .hero-visual {
            background: var(--peach);
            border-radius: 28px;
            padding: 35px;
            position: relative;
            min-height: 360px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ballot-card {
            background: white;
            width: 100%;
            max-width: 350px;
            padding: 28px;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(77, 49, 34, 0.12);
            transform: rotate(-2deg);
        }

        .ballot-top {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee4db;
            margin-bottom: 20px;
        }

        .ballot-icon {
            width: 46px;
            height: 46px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: var(--peach);
            font-size: 24px;
        }

        .ballot-top h3 {
            font-size: 17px;
        }

        .ballot-top small {
            color: var(--muted);
        }

        .ballot-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px;
            border: 1px solid #eee4db;
            border-radius: 10px;
            margin-bottom: 12px;
        }

        .fake-radio {
            width: 19px;
            height: 19px;
            border: 2px solid #d7c7ba;
            border-radius: 50%;
        }

        .ballot-option.selected {
            border-color: var(--orange);
            background: #fff8f3;
        }

        .ballot-option.selected .fake-radio {
            border: 5px solid var(--orange);
        }

        .ballot-option strong {
            font-size: 14px;
        }

        .ballot-option small {
            display: block;
            color: var(--muted);
            font-size: 12px;
        }

        .vote-status {
            margin-top: 18px;
            background: #f1f8ef;
            color: #3c7b43;
            border-radius: 9px;
            text-align: center;
            padding: 11px;
            font-size: 13px;
            font-weight: 700;
        }

        .section {
            padding: 75px 7%;
        }

        .section-heading {
            text-align: center;
            max-width: 650px;
            margin: 0 auto 42px;
        }

        .section-heading .eyebrow {
            margin-bottom: 14px;
        }

        .section-heading h2 {
            font-size: clamp(28px, 4vw, 39px);
            line-height: 1.2;
            margin-bottom: 14px;
        }

        .section-heading p {
            color: var(--muted);
            font-size: 15px;
        }

        .features {
            background: white;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .feature-card {
            padding: 30px;
            border: 1px solid #f0e3d8;
            border-radius: 16px;
            background: var(--cream);
            transition: 0.25s;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(77, 49, 34, 0.07);
        }

        .feature-icon {
            width: 54px;
            height: 54px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: var(--peach);
            font-size: 25px;
            margin-bottom: 20px;
        }

        .feature-card h3 {
            font-size: 19px;
            margin-bottom: 10px;
        }

        .feature-card p {
            color: var(--muted);
            font-size: 14px;
        }

        .steps-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 25px;
        }

        .step {
            text-align: center;
            padding: 25px 18px;
        }

        .step-number {
            width: 55px;
            height: 55px;
            border-radius: 50%;
            background: var(--orange);
            color: white;
            display: grid;
            place-items: center;
            font-size: 21px;
            font-weight: 800;
            margin: 0 auto 18px;
        }

        .step h3 {
            margin-bottom: 10px;
            font-size: 19px;
        }

        .step p {
            color: var(--muted);
            font-size: 14px;
        }

        .cta {
            padding: 0 7% 75px;
        }

        .cta-box {
            background: var(--dark);
            color: white;
            border-radius: 22px;
            text-align: center;
            padding: 55px 25px;
        }

        .cta-box h2 {
            font-size: clamp(27px, 4vw, 38px);
            margin-bottom: 12px;
        }

        .cta-box p {
            color: #e2d5cd;
            margin-bottom: 25px;
        }

        .footer {
            background: #f6e9de;
            padding: 25px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 13px;
        }

        .footer strong {
            color: var(--orange);
            font-size: 18px;
        }

        @media (max-width: 850px) {
            .hero {
                grid-template-columns: 1fr;
                padding-top: 55px;
                gap: 35px;
            }

            .hero-visual {
                min-height: 330px;
            }

            .feature-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .nav-links {
                gap: 14px;
            }
        }

        @media (max-width: 600px) {
            .navbar {
                padding: 15px 5%;
                flex-wrap: wrap;
                gap: 14px;
            }

            .logo {
                font-size: 22px;
            }

            .nav-links {
                width: 100%;
                justify-content: space-between;
                gap: 10px;
                flex-wrap: wrap;
            }

            .nav-links a {
                font-size: 12px;
            }

            .nav-links .btn {
                padding: 9px 12px;
            }

            .hero {
                padding: 45px 5% 55px;
            }

            .hero h1 {
                letter-spacing: -1px;
            }

            .hero-visual {
                padding: 18px;
                min-height: 320px;
            }

            .ballot-card {
                padding: 20px;
            }

            .section {
                padding: 55px 5%;
            }

            .feature-grid,
            .steps-grid {
                grid-template-columns: 1fr;
            }

            .cta {
                padding: 0 5% 55px;
            }

            .footer {
                padding: 22px 5%;
                justify-content: center;
                text-align: center;
            }
        }
    </style>
</head>

<body>

<header class="navbar">
    <a href="index.php" class="logo">Vote<span>Portal</span></a>

    <nav class="nav-links">
        <a href="#home">Home</a>
        <a href="#features">Features</a>
        <a href="#how-it-works">How It Works</a>

        <?php if ($isLoggedIn): ?>
            <a href="<?= htmlspecialchars($dashboard) ?>" class="btn btn-primary">
                My Dashboard
            </a>
            <a href="logout.php" class="btn btn-outline">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php" class="btn btn-primary">Get Started</a>
        <?php endif; ?>
    </nav>
</header>

<main>
    <section class="hero" id="home">
        <div>
            <span class="eyebrow">YOUR VOICE. YOUR CHOICE.</span>

            <h1>Every Vote Matters.<br><span>Every Voice Counts.</span></h1>

            <p>
                Welcome to VotePortal, a simple online voting platform designed
                to make elections convenient, organized, and accessible.
                Explore elections and make your choice through a user-friendly interface.
            </p>

            <div class="hero-buttons">
                <?php if ($isLoggedIn): ?>
                    <a href="<?= htmlspecialchars($dashboard) ?>" class="btn btn-primary">
                        Go to Dashboard →
                    </a>
                <?php else: ?>
                    <a href="register.php" class="btn btn-primary">
                        Get Started →
                    </a>
                    <a href="login.php" class="btn btn-outline">
                        Login to Vote
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="hero-visual">
            <div class="ballot-card">
                <div class="ballot-top">
                    <div class="ballot-icon">🗳️</div>
                    <div>
                        <h3>Your Vote, Your Voice</h3>
                        <small>A simple voting experience</small>
                    </div>
                </div>

                <div class="ballot-option">
                    <div class="fake-radio"></div>
                    <div>
                        <strong>Explore Elections</strong>
                        <small>View available elections</small>
                    </div>
                </div>

                <div class="ballot-option selected">
                    <div class="fake-radio"></div>
                    <div>
                        <strong>Make Your Choice</strong>
                        <small>Choose your preferred candidate</small>
                    </div>
                </div>

                <div class="ballot-option">
                    <div class="fake-radio"></div>
                    <div>
                        <strong>View Results</strong>
                        <small>Check published results</small>
                    </div>
                </div>

                <div class="vote-status">✓ Simple · Organized · Accessible</div>
            </div>
        </div>
    </section>

    <section class="section features" id="features">
        <div class="section-heading">
            <span class="eyebrow">WHY VOTEPORTAL?</span>
            <h2>Voting Made Simple</h2>
            <p>
                Everything you need to participate in an election,
                from exploring candidates to viewing available results.
            </p>
        </div>

        <div class="feature-grid">
            <div class="feature-card">
                <div class="feature-icon">🔐</div>
                <h3>Account-Based Access</h3>
                <p>
                    Log in to your account and access the features
                    available for your assigned role.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">🗳️</div>
                <h3>Easy Voting</h3>
                <p>
                    Review approved candidates and submit your selection
                    when an election is open.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <h3>Election Results</h3>
                <p>
                    Check election results when they are made available
                    by the election administrator.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">👥</div>
                <h3>Candidate Information</h3>
                <p>
                    Explore candidate profiles and manifestos before
                    making your voting decision.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">📱</div>
                <h3>Responsive Design</h3>
                <p>
                    Use the website on a desktop, laptop, tablet,
                    or mobile device.
                </p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">⚙️</div>
                <h3>Election Management</h3>
                <p>
                    Administrators can manage elections and review
                    candidate applications through their dashboard.
                </p>
            </div>
        </div>
    </section>

    <section class="section" id="how-it-works">
        <div class="section-heading">
            <span class="eyebrow">THREE SIMPLE STEPS</span>
            <h2>How It Works</h2>
            <p>Follow these steps to participate in an available election.</p>
        </div>

        <div class="steps-grid">
            <div class="step">
                <div class="step-number">1</div>
                <h3>Create Your Account</h3>
                <p>Register with your details and log in to VotePortal.</p>
            </div>

            <div class="step">
                <div class="step-number">2</div>
                <h3>Choose an Election</h3>
                <p>Open your dashboard and review an active election and its candidates.</p>
            </div>

            <div class="step">
                <div class="step-number">3</div>
                <h3>Cast Your Vote</h3>
                <p>Select a candidate and submit your vote while voting is open.</p>
            </div>
        </div>
    </section>

    <section class="cta">
        <div class="cta-box">
            <h2>Ready to Make Your Voice Heard?</h2>
            <p>Join VotePortal and explore the elections available to you.</p>

            <?php if ($isLoggedIn): ?>
                <a href="<?= htmlspecialchars($dashboard) ?>" class="btn btn-primary">
                    Open Dashboard →
                </a>
            <?php else: ?>
                <a href="register.php" class="btn btn-primary">
                    Create an Account →
                </a>
            <?php endif; ?>
        </div>
    </section>
</main>

<footer class="footer">
    <div><strong>VotePortal</strong></div>
    <div>Making participation simple and accessible.</div>
    <div>© <?= date('Y') ?> VotePortal. All rights reserved.</div>
</footer>

</body>
</html>
