
<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'admin') {
    header("Location: ../login.php");
    exit;
}

$name = $_SESSION['name'];

$totalElections = $conn->query("SELECT COUNT(*) AS total FROM elections")->fetch_assoc()['total'];
$totalCandidates = $conn->query("SELECT COUNT(*) AS total FROM candidates")->fetch_assoc()['total'];
$totalVoters = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role='voter'")->fetch_assoc()['total'];
$pendingCandidates = $conn->query("SELECT COUNT(*) AS total FROM candidates WHERE status='pending'")->fetch_assoc()['total'];

$recentElections = $conn->query(
    "SELECT id, title, status, start_date, end_date
     FROM elections ORDER BY id DESC LIMIT 5"
);
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

/* Orange and cream sidebar */
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

/* Main area */
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

.section-heading {
    font-size: 21px;
    margin-bottom: 17px;
}

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
}

.btn:hover {
    background: #9A3412;
}

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

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    background: #FFEDD5;
    color: #9A3412;
    font-size: 12px;
}

.footer {
    text-align: center;
    color: #78716C;
    font-size: 12px;
    padding: 25px;
}

@media (max-width: 1050px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

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

    <aside class="sidebar">
        <div class="logo">Vote<span>Portal.</span></div>

        <div class="side-title">MAIN MENU</div>

        <nav>
            <a href="dashboard.php" class="active">▦ &nbsp; Dashboard</a>
            <a href="create_election.php">＋ &nbsp; Create Election</a>
            <a href="manage_elections.php">☷ &nbsp; Manage Elections</a>
            <a href="manage_candidates.php">♙ &nbsp; Candidates</a>
            <a href="results.php">▤ &nbsp; Election Results</a>
            <a href="../logout.php" class="logout-link">↪ &nbsp; Logout</a>
        </nav>
    </aside>

    <main class="main">

        <header class="topbar">
            <h2>Dashboard Overview</h2>
            <div class="profile">
                ♙ &nbsp; <?php echo htmlspecialchars($name); ?>
            </div>
        </header>

        <div class="content">

            <section class="welcome">
                <div class="small">ADMINISTRATION PANEL</div>
                <h1>Welcome back, <span><?php echo htmlspecialchars($name); ?>!</span></h1>
                <p>Manage elections, review candidate applications and monitor your voting platform.</p>
            </section>

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
            </section>

            <h2 class="section-heading">Quick Actions</h2>

            <section class="actions">
                <div class="action-card">
                    <div class="action-icon">🗳️</div>
                    <h3>Create New Election</h3>
                    <p>Create an election with its title, description and schedule.</p>
                    <a href="create_election.php" class="btn">Create Election →</a>
                </div>

                <div class="action-card">
                    <div class="action-icon">📋</div>
                    <h3>Manage Elections</h3>
                    <p>View elections and manage their current status.</p>
                    <a href="manage_elections.php" class="btn">Manage Elections →</a>
                </div>

                <div class="action-card">
                    <div class="action-icon">👥</div>
                    <h3>Review Candidates</h3>
                    <p>Review applications and approve or reject candidates.</p>
                    <a href="manage_candidates.php" class="btn">Review Candidates →</a>
                </div>

                <div class="action-card">
                    <div class="action-icon">📊</div>
                    <h3>Election Results</h3>
                    <p>View voting results after an election is completed.</p>
                    <a href="results.php" class="btn">View Results →</a>
                </div>
            </section>

            <h2 class="section-heading">Recent Elections</h2>

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
                    <?php if ($recentElections && $recentElections->num_rows > 0) { ?>
                        <?php while ($election = $recentElections->fetch_assoc()) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($election['title']); ?></td>
                                <td><?php echo htmlspecialchars($election['start_date']); ?></td>
                                <td><?php echo htmlspecialchars($election['end_date']); ?></td>
                                <td><span class="status"><?php echo htmlspecialchars(ucfirst($election['status'])); ?></span></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="4">No elections created yet.</td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>

        </div>

        <footer class="footer">
            © 2026 VotePortal | Admin Panel
        </footer>

    </main>
</div>
</body>
</html>
