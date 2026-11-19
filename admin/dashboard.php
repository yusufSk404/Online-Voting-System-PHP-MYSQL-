
<?php
session_start();
require_once '../config/db.php';

// Admin authentication
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Asia/Kolkata');

$name = trim((string)($_SESSION['name'] ?? 'Admin'));

if ($name === '') {
    $name = 'Admin';
}

// Time-based greeting
$currentHour = (int)date('G');

if ($currentHour >= 5 && $currentHour < 12) {
    $timeGreeting = 'Good morning';
} elseif ($currentHour >= 12 && $currentHour < 17) {
    $timeGreeting = 'Good afternoon';
} else {
    $timeGreeting = 'Good evening';
}

// Fetch Sir/Ma'am from database
$honorific = '';

$salutationStmt = $conn->prepare(
    "SELECT salutation FROM users WHERE id = ? LIMIT 1"
);

if ($salutationStmt) {
    $salutationStmt->bind_param("i", $_SESSION['user_id']);

    if ($salutationStmt->execute()) {
        $salutationResult = $salutationStmt->get_result();

        if ($salutationRow = $salutationResult->fetch_assoc()) {
            $honorific = $salutationRow['salutation'] ?? '';
        }
    }

    $salutationStmt->close();
}

$displayName = $name;

// Safe database count helper
function getDashboardCount($conn, $sql) {
    $result = $conn->query($sql);

    if (!$result) {
        error_log('Dashboard query failed: ' . $conn->error);
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int)($row['total'] ?? 0);
}

// Dashboard statistics
$totalElections = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM elections"
);

$totalCandidates = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM candidates"
);

$totalVoters = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'voter'"
);

$pendingCandidates = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM candidates WHERE status = 'pending'"
);

// Election status statistics
$activeElections = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM elections WHERE status = 'active'"
);

$upcomingElections = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM elections WHERE status = 'upcoming'"
);

$completedElections = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM elections WHERE status = 'completed'"
);

$draftElections = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM elections WHERE status = 'draft'"
);

// Total Admins
$totalAdmins = getDashboardCount(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'"
);

// Recent elections
$recentElections = $conn->query(
    "SELECT id, title, status, start_date, end_date
     FROM elections
     ORDER BY created_at DESC, id DESC
     LIMIT 5"
);

if (!$recentElections) {
    error_log('Recent elections query failed: ' . $conn->error);
}

// HTML escaping
function h($value) {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Date formatting
function formatDashboardDate($value) {
    if (empty($value)) {
        return 'Not specified';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('d M Y, h:i A', $timestamp)
        : 'Not specified';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Admin Dashboard | VotePortal</title>

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

.layout {
    display: flex;
    min-height: 100vh;
}

/* SIDEBAR */

.sidebar {
    width: 245px;
    background: #FFFFFF;
    border-right: 1px solid #FED7AA;
    padding: 28px 18px;
    flex-shrink: 0;
}

.logo {
    font-size: 25px;
    font-weight: bold;
    color: #9A3412;
    padding: 0 12px 35px;
}

.logo span {
    color: #EA580C;
}

.side-title {
    color: #9A3412;
    font-size: 11px;
    letter-spacing: 1.5px;
    padding: 0 12px;
    margin-bottom: 13px;
}

.sidebar a {
    display: block;
    text-decoration: none;
    color: #7C2D12;
    padding: 14px 12px;
    border-radius: 9px;
    margin-bottom: 7px;
    font-size: 14px;
    transition: 0.2s;
}

.sidebar a:hover,
.sidebar a.active {
    background: #FFEDD5;
    color: #C2410C;
}

.sidebar .logout-link {
    margin-top: 35px;
    border: 1px solid #FED7AA;
}

/* MAIN AREA */

.main {
    flex: 1;
    min-width: 0;
}

.topbar {
    background: white;
    padding: 22px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    border-bottom: 1px solid #FED7AA;
}

.topbar h2 {
    font-size: 21px;
}

.profile {
    background: #FFEDD5;
    color: #9A3412;
    padding: 10px 15px;
    border-radius: 10px;
    font-size: 14px;
    font-weight: bold;
}

.content {
    padding: 30px;
}

/* WELCOME */

.welcome {
    background: white;
    border: 1px solid #FED7AA;
    border-radius: 18px;
    padding: 30px;
    margin-bottom: 28px;
}

.welcome .small {
    color: #C2410C;
    font-size: 12px;
    letter-spacing: 1.5px;
    margin-bottom: 12px;
    font-weight: bold;
}

.welcome .greeting {
    color: #9A3412;
    font-size: 14px;
    font-weight: bold;
    margin-bottom: 10px;
}

.welcome h1 {
    font-size: 29px;
    margin-bottom: 12px;
}

.welcome h1 span {
    color: #EA580C;
}

.welcome p {
    line-height: 1.7;
    color: #78716C;
}

/* STATISTICS */

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 17px;
    margin-bottom: 32px;
}

.stat-card {
    background: white;
    padding: 22px;
    border: 1px solid #FED7AA;
    border-radius: 15px;
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    background: #FFEDD5;
    color: #C2410C;
    display: grid;
    place-items: center;
    font-size: 22px;
    margin-bottom: 18px;
}

.stat-card p {
    font-size: 13px;
    color: #78716C;
    margin-bottom: 10px;
}

.stat-card h3 {
    font-size: 30px;
    color: #C2410C;
}

.stat-note {
    margin-top: 8px;
    font-size: 11px;
    color: #A8A29E;
}

.section-heading {
    font-size: 21px;
    margin-bottom: 17px;
}

/* QUICK ACTIONS */

.actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 17px;
    margin-bottom: 32px;
}

.action-card {
    background: white;
    padding: 23px;
    border: 1px solid #FED7AA;
    border-radius: 15px;
    transition: 0.2s;
}

.action-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 8px 22px rgba(154,52,18,0.07);
}

.action-icon {
    font-size: 25px;
    margin-bottom: 13px;
}

.action-card h3 {
    font-size: 17px;
    margin-bottom: 10px;
}

.action-card p {
    font-size: 14px;
    color: #78716C;
    line-height: 1.7;
    margin-bottom: 18px;
}

.btn {
    display: inline-block;
    padding: 11px 15px;
    background: #C2410C;
    color: white;
    text-decoration: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: bold;
    transition: 0.2s;
}

.btn:hover {
    background: #9A3412;
}

/* RECENT ELECTIONS */

.table-wrap {
    background: white;
    border: 1px solid #FED7AA;
    border-radius: 15px;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 500px;
}

th, td {
    padding: 17px 20px;
    text-align: left;
    border-bottom: 1px solid #F5E7D8;
    font-size: 13px;
}

th {
    background: #FFF7ED;
    color: #7C2D12;
}

/* STATUS BADGES */

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    background: #FFEDD5;
    color: #9A3412;
    font-size: 12px;
}

.status.active {
    background: #DCFCE7;
    color: #166534;
}

.status.upcoming {
    background: #DBEAFE;
    color: #1D4ED8;
}

.status.completed {
    background: #E7E5E4;
    color: #57534E;
}

.status.draft {
    background: #F3E8FF;
    color: #6B21A8;
}

.status.pending {
    background: #FEF3C7;
    color: #92400E;
}

.footer {
    text-align: center;
    color: #78716C;
    font-size: 12px;
    padding: 25px;
}

/* TABLET */

@media (max-width: 1050px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* MOBILE */

@media (max-width: 700px) {

    .layout {
        flex-direction: column;
    }

    .sidebar {
        width: 100%;
        padding: 17px;
        border-right: none;
        border-bottom: 1px solid #FED7AA;
    }

    .logo {
        padding: 0 5px 17px;
    }

    .side-title {
        display: none;
    }

    .sidebar nav {
        display: flex;
        flex-wrap: wrap;
        gap: 5px;
    }

    .sidebar a {
        padding: 10px;
        margin: 0;
        font-size: 12px;
    }

    .sidebar .logout-link {
        margin-top: 0;
    }

    .topbar {
        padding: 18px;
    }

    .topbar h2 {
        font-size: 17px;
    }

    .profile {
        font-size: 12px;
        padding: 9px;
    }

    .content {
        padding: 17px;
    }

    .welcome {
        padding: 23px;
    }

    .welcome h1 {
        font-size: 24px;
    }

    .actions {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 400px) {
    .stats {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>

<div class="layout">

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="logo">
            Vote<span>Portal.</span>
        </div>

        <div class="side-title">MAIN MENU</div>

        <nav>

            <a href="dashboard.php" class="active">
                ▦ &nbsp; Dashboard
            </a>

            <a href="create_election.php">
                ＋ &nbsp; Create Election
            </a>

            <a href="manage_elections.php">
                ☷ &nbsp; Manage Elections
            </a>

            <a href="manage_candidates.php">
                ♙ &nbsp; Candidates
            </a>

            <a href="manage_users.php">
                👥 &nbsp; Manage Users
            </a>

            <!-- NEW MANAGE ADMINS LINK -->

            <a href="manage_admins.php">
                🛡️ &nbsp; Manage Admins
            </a>

            <a href="results.php">
                ▤ &nbsp; Election Results
            </a>

            <a href="../logout.php" class="logout-link">
                ↪ &nbsp; Logout
            </a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main">

        <!-- TOPBAR -->

        <header class="topbar">

            <h2>Dashboard Overview</h2>

            <div class="profile">
                ♙ &nbsp;
                <?php echo h($displayName); ?>
            </div>

        </header>

        <div class="content">

            <!-- WELCOME SECTION -->

            <section class="welcome">

                <div class="small">
                    ADMINISTRATION PANEL
                </div>

                <div class="greeting">
                    <?php
                    echo h($timeGreeting);

                    if ($honorific !== '') {
                        echo ' ' . h($honorific);
                    }

                    echo ',';
                    ?>
                </div>

                <h1>
                    Welcome back,
                    <span><?php echo h($displayName); ?>!</span>
                </h1>

                <p>
                    Manage elections, review candidate applications
                    and monitor your voting platform.
                </p>

            </section>

            <!-- STATISTICS -->

            <section class="stats">

                <div class="stat-card">
                    <div class="stat-icon">▣</div>
                    <p>Total Elections</p>
                    <h3><?php echo $totalElections; ?></h3>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">♙</div>
                    <p>Total Candidates</p>
                    <h3><?php echo $totalCandidates; ?></h3>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">☷</div>
                    <p>Registered Voters</p>
                    <h3><?php echo $totalVoters; ?></h3>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">◷</div>
                    <p>Pending Applications</p>
                    <h3><?php echo $pendingCandidates; ?></h3>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🟢</div>
                    <p>Active Elections</p>
                    <h3><?php echo $activeElections; ?></h3>
                    <div class="stat-note">Currently open for voting</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📅</div>
                    <p>Upcoming Elections</p>
                    <h3><?php echo $upcomingElections; ?></h3>
                    <div class="stat-note">Scheduled to start</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">🏁</div>
                    <p>Completed Elections</p>
                    <h3><?php echo $completedElections; ?></h3>
                    <div class="stat-note">Voting period ended</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">📝</div>
                    <p>Draft Elections</p>
                    <h3><?php echo $draftElections; ?></h3>
                    <div class="stat-note">Not published yet</div>
                </div>

                <!-- NEW ADMIN STATISTIC -->

                <div class="stat-card">
                    <div class="stat-icon">🛡️</div>
                    <p>Total Admins</p>
                    <h3><?php echo $totalAdmins; ?></h3>
                    <div class="stat-note">Authorized administrators</div>
                </div>

            </section>

            <!-- QUICK ACTIONS -->

            <h2 class="section-heading">
                Quick Actions
            </h2>

            <section class="actions">

                <div class="action-card">
                    <div class="action-icon">🗳️</div>
                    <h3>Create New Election</h3>

                    <p>
                        Create an election with its title,
                        description and schedule.
                    </p>

                    <a href="create_election.php" class="btn">
                        Create Election →
                    </a>
                </div>

                <div class="action-card">
                    <div class="action-icon">📋</div>
                    <h3>Manage Elections</h3>

                    <p>
                        View elections and manage their
                        current status.
                    </p>

                    <a href="manage_elections.php" class="btn">
                        Manage Elections →
                    </a>
                </div>

                <div class="action-card">
                    <div class="action-icon">👥</div>
                    <h3>Review Candidates</h3>

                    <p>
                        Review applications and approve
                        or reject candidates.
                    </p>

                    <a href="manage_candidates.php" class="btn">
                        Review Candidates →
                    </a>
                </div>

                <div class="action-card">
                    <div class="action-icon">📊</div>
                    <h3>Election Results</h3>

                    <p>
                        View voting results after an
                        election is completed.
                    </p>

                    <a href="results.php" class="btn">
                        View Results →
                    </a>
                </div>

                <div class="action-card">
                    <div class="action-icon">🔐</div>
                    <h3>Manage Users</h3>

                    <p>
                        View registered voters and candidates
                        and securely reset their passwords.
                    </p>

                    <a href="manage_users.php" class="btn">
                        Manage Users →
                    </a>
                </div>

                <!-- NEW MANAGE ADMINS CARD -->

                <div class="action-card">
                    <div class="action-icon">🛡️</div>
                    <h3>Manage Admins</h3>

                    <p>
                        Create new administrator accounts,
                        view registered Admins and manage
                        their access securely.
                    </p>

                    <a href="manage_admins.php" class="btn">
                        Manage Admins →
                    </a>
                </div>

            </section>

            <!-- RECENT ELECTIONS -->

            <h2 class="section-heading">
                Recent Elections
            </h2>

            <div class="table-wrap">

                <table>

                    <thead>
                        <tr>
                            <th>Election Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($recentElections && $recentElections->num_rows > 0): ?>

                        <?php while ($election = $recentElections->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <?php echo h($election['title']); ?>
                                </td>

                                <td>
                                    <?php echo h(formatDashboardDate($election['start_date'])); ?>
                                </td>

                                <td>
                                    <?php echo h(formatDashboardDate($election['end_date'])); ?>
                                </td>

                                <td>
                                    <span class="status <?php echo h(strtolower($election['status'])); ?>">
                                        <?php echo h(ucfirst($election['status'])); ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="4">
                                No elections created yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- FOOTER -->

        <footer class="footer">
            © <?php echo date('Y'); ?> VotePortal | Admin Panel
        </footer>

    </main>

</div>

</body>
</html>
