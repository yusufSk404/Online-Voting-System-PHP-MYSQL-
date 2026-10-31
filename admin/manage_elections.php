
<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function dateDisplay($value, $format) {
    return $value && strtotime($value) !== false
        ? date($format, strtotime($value))
        : 'Not set';
}

function statusClass($status) {
    return in_array($status, ['active', 'upcoming', 'completed', 'draft'], true)
        ? $status : 'unknown';
}

$categories = [
    'Student Council', 'College Election', 'University Election',
    'Department Election', 'Class Election', 'Batch Coordinator',
    'Class Representative (CR)', 'Department Representative',
    'Student Representative', 'Year Representative',
    'Section Representative', 'Academic Representative',
    'Placement Coordinator', 'Training and Placement Representative',
    'Career Development Coordinator', 'Internship Coordinator',
    'Industry Relations Coordinator', 'Program Coordinator',
    'Course Coordinator', 'Academic Coordinator', 'Academic Secretary',
    'Lab Coordinator', 'Project Coordinator', 'Research Coordinator',
    'Program Organizer', 'Event Coordinator', 'Cultural Coordinator',
    'Fest Coordinator', 'Technical Fest Coordinator', 'Workshop Coordinator',
    'Seminar Coordinator', 'Hospitality Coordinator',
    'Public Relations Coordinator', 'Media Coordinator',
    'Photography Coordinator', 'Content Coordinator',
    'Social Media Coordinator', 'Sports Coordinator', 'Sports Captain',
    'Club President', 'Club Vice President', 'Club Secretary',
    'Club Treasurer', 'Club Coordinator', 'Technical Club Representative',
    'Cultural Club Representative', 'Literary Club Representative',
    'Coding Club Representative', 'Robotics Club Representative',
    'Student Welfare Coordinator', 'Discipline Coordinator',
    'Anti-Ragging Representative', 'Grievance Representative',
    'Women Cell Representative', 'Environmental Coordinator',
    'Community Service Coordinator', 'NSS Coordinator', 'NCC Coordinator',
    'General Secretary', 'Joint Secretary', 'President',
    'Vice President', 'Treasurer', 'Other'
];

$allowedStatuses = ['draft', 'upcoming', 'active', 'completed'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['manage_elections_message'] ?? '';
unset($_SESSION['manage_elections_message']);

/* ======================================
   CLOSE / REOPEN / DELETE ELECTION
====================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $id = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT);

    try {
        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new Exception('Security validation failed. Please try again.');
        }

        if (!$id || $id < 1) {
            throw new Exception('Invalid election selected.');
        }

        $check = $conn->prepare(
            "SELECT id, status FROM elections WHERE id = ?"
        );
        $check->bind_param("i", $id);
        $check->execute();
        $election = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$election) {
            throw new Exception('Election not found.');
        }

        if (isset($_POST['close_election'])) {
            $stmt = $conn->prepare(
                "UPDATE elections SET status = 'completed' WHERE id = ?"
            );
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['manage_elections_message'] =
                'Election closed successfully. Existing votes have been preserved.';

        } elseif (isset($_POST['reopen_election'])) {
            /*
             * Reopen the existing election row.
             * Do not delete votes, candidates, or create a new election ID.
             */
            $stmt = $conn->prepare(
                "UPDATE elections
                 SET status = 'active',
                     start_date = LEAST(start_date, NOW()),
                     end_date = DATE_ADD(NOW(), INTERVAL 1 DAY)
                 WHERE id = ?"
            );
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['manage_elections_message'] =
                'Election reopened for 24 hours. Existing votes have been preserved.';

        } elseif (isset($_POST['delete_election'])) {
            $voteCheck = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM votes
                 WHERE election_id = ?"
            );
            $voteCheck->bind_param("i", $id);
            $voteCheck->execute();
            $voteCount = (int)$voteCheck->get_result()->fetch_assoc()['total'];
            $voteCheck->close();

            if ($voteCount > 0) {
                throw new Exception(
                    'This election already has votes and cannot be deleted.'
                );
            }

            $conn->begin_transaction();

            try {
                $deleteCandidates = $conn->prepare(
                    "DELETE FROM candidates WHERE election_id = ?"
                );
                $deleteCandidates->bind_param("i", $id);
                $deleteCandidates->execute();
                $deleteCandidates->close();

                $deleteElection = $conn->prepare(
                    "DELETE FROM elections WHERE id = ?"
                );
                $deleteElection->bind_param("i", $id);
                $deleteElection->execute();

                if ($deleteElection->affected_rows !== 1) {
                    $deleteElection->close();
                    throw new Exception('Election could not be deleted.');
                }

                $deleteElection->close();
                $conn->commit();

                $_SESSION['manage_elections_message'] =
                    'Election deleted successfully from the database.';

            } catch (Throwable $ex) {
                $conn->rollback();
                throw $ex;
            }

        } else {
            throw new Exception('Invalid action requested.');
        }

    } catch (Throwable $ex) {
        $_SESSION['manage_elections_message'] = $ex->getMessage();
    }

    header("Location: manage_elections.php");
    exit;
}

/* ======================================
   SEARCH AND FILTERS
====================================== */

$search = trim($_GET['search'] ?? '');
$categoryFilter = trim($_GET['category'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

if (!in_array($categoryFilter, $categories, true)) {
    $categoryFilter = '';
}

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

/* ======================================
   STATISTICS
====================================== */

$stats = [
    'total' => 0,
    'active' => 0,
    'upcoming' => 0,
    'completed' => 0,
    'draft' => 0
];

$statsResult = $conn->query(
    "SELECT status, COUNT(*) AS total
     FROM elections
     GROUP BY status"
);

if ($statsResult) {
    while ($row = $statsResult->fetch_assoc()) {
        $count = (int)$row['total'];
        $stats['total'] += $count;

        if (isset($stats[$row['status']])) {
            $stats[$row['status']] = $count;
        }
    }
}

/* ======================================
   FETCH ELECTIONS
====================================== */

$sql = "
    SELECT
        e.id,
        e.title,
        e.description,
        e.category,
        e.start_date,
        e.end_date,
        e.status,
        (SELECT COUNT(*)
         FROM candidates c
         WHERE c.election_id = e.id) AS candidate_count,
        (SELECT COUNT(*)
         FROM votes v
         WHERE v.election_id = e.id) AS vote_count
    FROM elections e
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (e.title LIKE ? OR e.description LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($categoryFilter !== '') {
    $sql .= " AND e.category = ?";
    $params[] = $categoryFilter;
    $types .= 's';
}

if ($statusFilter !== '') {
    $sql .= " AND e.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

$sql .= " ORDER BY e.id DESC";

$elections = [];
$queryError = '';

$stmt = $conn->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $elections[] = $row;
        }
    } else {
        $queryError = 'Unable to load elections. Please check your database.';
    }

    $stmt->close();
} else {
    $queryError = 'Unable to prepare the election query. Check your database columns.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Elections | VotePortal</title>

<style>
:root {
    --primary: #9A3412;
    --primary-dark: #7C2D12;
    --primary-light: #C2410C;

    --page: #FFF8F1;
    --panel: #FFF1E5;
    --panel-alt: #FCE8D8;
    --table-head: #F8E2CF;
    --input-bg: #FFFAF6;
    --border: #F5DECC;

    --text: #292524;
    --muted: #78716C;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: var(--page);
    color: var(--text);
    line-height: 1.5;
}

.navbar {
    min-height: 70px;
    padding: 15px 5%;
    background: #FFFCF9;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.logo {
    font-size: 23px;
    font-weight: 850;
    color: var(--primary);
    text-decoration: none;
}

.logo span {
    color: var(--primary-light);
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.nav-links a {
    color: var(--primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 750;
}

.nav-links a:hover {
    color: var(--primary-light);
}

.container {
    max-width: 1450px;
    width: calc(100% - 44px);
    margin: 30px auto 45px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    margin-bottom: 25px;
}

.eyebrow {
    color: var(--primary-light);
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 1.6px;
    font-weight: 850;
    margin-bottom: 7px;
}

.page-header h1 {
    font-size: clamp(25px, 3vw, 32px);
    margin-bottom: 6px;
}

.page-header p {
    color: var(--muted);
    font-size: 14px;
}

/* BUTTONS */

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 7px;
    min-height: 42px;
    padding: 10px 15px;
    border: 1px solid var(--primary);
    border-radius: 8px;
    background: var(--primary);
    color: #FFF7ED;
    font-family: inherit;
    font-size: 12px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
    transition: background .15s ease;
}

.btn:hover {
    background: var(--primary-dark);
}

.btn-light {
    background: #FBE5D2;
    color: var(--primary);
    border-color: #EFD0B5;
}

.btn-light:hover {
    background: #F5D5BC;
}

/* ALERTS */

.alert {
    padding: 13px 16px;
    border-radius: 9px;
    margin-bottom: 20px;
    font-size: 13px;
    overflow-wrap: anywhere;
}

.alert.success {
    background: #DCEBD6;
    border: 1px solid #B8D6AE;
    color: #245B26;
}

.alert.error {
    background: #F8DAD4;
    border: 1px solid #EAB9AD;
    color: #991B1B;
}

/* STATISTICS */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 15px;
    margin-bottom: 25px;
}

.stat-card {
    padding: 19px;
    border: 1px solid #F5DCC6;
    background: #FFF0E2;
    border-radius: 13px;
    box-shadow: none;
}

.stat-label {
    display: flex;
    align-items: center;
    gap: 9px;
    color: #6B5142;
    font-size: 12px;
    font-weight: 750;
}

.stat-icon {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 35px;
    height: 35px;
    border-radius: 9px;
    background: #FBE0C9;
    color: var(--primary);
    font-size: 16px;
}

.stat-number {
    margin-top: 14px;
    font-size: 28px;
    font-weight: 850;
    line-height: 1.2;
    color: #292524;
}

.stat-card small {
    display: block;
    color: var(--muted);
    font-size: 11px;
    margin-top: 5px;
}

/* MAIN PANEL */

.panel {
    background: #FFF1E5;
    border: 1px solid var(--border);
    border-radius: 15px;
    box-shadow: none;
    overflow: hidden;
}

.panel-header {
    padding: 22px 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    background: #FCE8D8;
    border-bottom: 1px solid var(--border);
}

.panel-header h2 {
    font-size: 18px;
    margin-bottom: 4px;
}

.panel-header p {
    color: var(--muted);
    font-size: 12px;
}

/* FILTERS */

.filter-form {
    display: grid;
    grid-template-columns:
        minmax(180px, 1.5fr)
        minmax(160px, 1fr)
        minmax(140px, .8fr)
        auto auto;
    gap: 10px;
    padding: 20px 24px;
    background: #FFF0E2;
    border-bottom: 1px solid var(--border);
}

.filter-form input,
.filter-form select {
    width: 100%;
    min-width: 0;
    min-height: 44px;
    padding: 10px 12px;
    border: 1px solid #EFD8C4;
    border-radius: 8px;
    background: var(--input-bg);
    font-family: inherit;
    font-size: 12px;
    color: var(--text);
}

.filter-form input::placeholder {
    color: #8B7466;
}

.filter-form input:focus,
.filter-form select:focus {
    outline: none;
    border-color: var(--primary-light);
    box-shadow: 0 0 0 3px rgba(194, 65, 12, .08);
}

.filter-btn {
    min-height: 44px;
    padding: 9px 15px;
}

.reset-link {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 9px 12px;
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
    font-weight: 750;
}

.reset-link:hover {
    text-decoration: underline;
}

/* TABLE */

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

thead,
th {
    background: var(--table-head);
}

th {
    padding: 14px 13px;
    text-align: left;
    white-space: nowrap;
    color: #71300F;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 850;
    border-bottom: 1px solid #F0D7C1;
}

td {
    padding: 15px 13px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    font-size: 12px;
    background: #FFF1E5;
}

tbody tr:nth-child(even) td {
    background: #FDEBDD;
}

tbody tr:hover td {
    background: #F9E2D0;
}

tbody tr:last-child td {
    border-bottom: none;
}

.election-title {
    font-size: 13px;
    font-weight: 800;
    color: #292524;
    max-width: 240px;
    overflow-wrap: anywhere;
}

.election-id {
    display: block;
    color: var(--muted);
    font-size: 11px;
    margin-top: 4px;
}

.category-label {
    display: inline-block;
    background: #FBE0C9;
    color: #8B3412;
    padding: 6px 9px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 800;
    max-width: 175px;
    overflow-wrap: anywhere;
}

.date-text {
    white-space: nowrap;
    line-height: 1.7;
}

.date-text strong {
    display: block;
    font-size: 12px;
    font-weight: 750;
}

.date-text small {
    display: block;
    color: var(--muted);
    font-size: 10px;
}

.count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 31px;
    min-height: 31px;
    padding: 5px 9px;
    background: #FBE0C9;
    color: #85300D;
    border-radius: 8px;
    font-weight: 850;
}

/* STATUS */

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
    text-transform: capitalize;
    white-space: nowrap;
}

.status.active {
    background: #D8EED5;
    color: #215C27;
}

.status.upcoming {
    background: #D8E6F8;
    color: #1E4778;
}

.status.completed {
    background: #E5D7CA;
    color: #514034;
}

.status.draft {
    background: #F7D1A8;
    color: #71300F;
}

.status.unknown {
    background: #EDE0D4;
    color: #514034;
}

/* ACTIONS */

.actions-cell {
    min-width: 270px;
}

.action-link {
    display: inline-block;
    text-decoration: none;
    padding: 7px 9px;
    border-radius: 7px;
    font-size: 11px;
    font-weight: 800;
    margin: 2px;
    border: 1px solid #EFD0B5;
    color: #8B3412;
    background: #FBE5D2;
    cursor: pointer;
    font-family: inherit;
}

.action-link:hover {
    background: #F5D5BC;
    border-color: #E5BFA0;
}

.action-form {
    display: inline-block;
    margin: 2px;
}

.close-btn {
    background: #F8E0C8;
    border-color: #EBC39F;
    color: #85300D;
}

.reopen-btn {
    background: #DCEBD6;
    border-color: #B8D6AE;
    color: #245B26;
}

.delete-btn {
    background: #F8D8D3;
    border-color: #EAB5AC;
    color: #991B1B;
}

.delete-btn:hover {
    background: #F1C3BB;
    border-color: #D9968C;
}

/* EMPTY STATE */

.empty-state {
    text-align: center;
    padding: 55px 20px;
    background: #FFF1E5;
}

.empty-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 65px;
    height: 65px;
    border-radius: 18px;
    background: #FBE0C9;
    color: var(--primary);
    font-size: 28px;
    margin-bottom: 16px;
}

.empty-state h3 {
    font-size: 18px;
    margin-bottom: 7px;
}

.empty-state p {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 18px;
}

/* FOOTER */

.table-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    padding: 15px 24px;
    background: #FCE8D8;
    border-top: 1px solid var(--border);
    color: var(--muted);
    font-size: 12px;
}

.footer {
    text-align: center;
    color: var(--muted);
    padding: 25px 15px;
    font-size: 12px;
}

/* RESPONSIVE */

@media (max-width: 1100px) {
    .stats-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .filter-form {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 650px) {
    .navbar {
        padding: 14px 18px;
        align-items: flex-start;
        flex-direction: column;
    }

    .logo {
        font-size: 20px;
    }

    .nav-links {
        gap: 12px;
    }

    .container {
        width: calc(100% - 24px);
        margin: 22px auto 30px;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .page-header > .btn {
        width: 100%;
    }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }

    .stat-card {
        padding: 15px;
    }

    .stat-number {
        font-size: 24px;
    }

    .panel-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 18px;
    }

    .filter-form {
        grid-template-columns: minmax(0, 1fr);
        padding: 16px;
    }

    .filter-form .btn,
    .filter-form .reset-link {
        width: 100%;
    }

    .table-footer {
        align-items: flex-start;
        flex-direction: column;
        padding: 15px 18px;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a href="dashboard.php" class="logo">Vote<span>Portal</span></a>

    <nav class="nav-links">
        <a href="create_election.php">+ Create Election</a>
        <a href="manage_candidates.php">Manage Candidates</a>
        <a href="dashboard.php">← Dashboard</a>
    </nav>
</header>

<main class="container">

    <section class="page-header">
        <div>
            <div class="eyebrow">Admin Workspace / Elections</div>
            <h1>Manage Elections</h1>
            <p>Edit, reopen, close, delete and monitor your elections.</p>
        </div>

        <a href="create_election.php" class="btn">
            + Create New Election
        </a>
    </section>

    <?php if ($message !== ''): ?>
        <div class="alert success"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($queryError !== ''): ?>
        <div class="alert error"><?php echo e($queryError); ?></div>
    <?php endif; ?>

    <section class="stats-grid">

        <div class="stat-card">
            <div class="stat-label">
                <span class="stat-icon">▦</span> Total Elections
            </div>
            <div class="stat-number"><?php echo (int)$stats['total']; ?></div>
            <small>All election records</small>
        </div>

        <div class="stat-card">
            <div class="stat-label">
                <span class="stat-icon">●</span> Active
            </div>
            <div class="stat-number"><?php echo (int)$stats['active']; ?></div>
            <small>Active status</small>
        </div>

        <div class="stat-card">
            <div class="stat-label">
                <span class="stat-icon">◷</span> Upcoming
            </div>
            <div class="stat-number"><?php echo (int)$stats['upcoming']; ?></div>
            <small>Scheduled elections</small>
        </div>

        <div class="stat-card">
            <div class="stat-label">
                <span class="stat-icon">✓</span> Completed
            </div>
            <div class="stat-number"><?php echo (int)$stats['completed']; ?></div>
            <small>Completed elections</small>
        </div>

        <div class="stat-card">
            <div class="stat-label">
                <span class="stat-icon">✎</span> Drafts
            </div>
            <div class="stat-number"><?php echo (int)$stats['draft']; ?></div>
            <small>Saved drafts</small>
        </div>

    </section>

    <section class="panel">

        <div class="panel-header">
            <div>
                <h2>All Elections</h2>
                <p>Search and filter election records. Reopening preserves existing votes.</p>
            </div>

            <a href="create_election.php" class="btn btn-light">
                + Add Election
            </a>
        </div>

        <form method="GET" class="filter-form">

            <input
                type="search"
                name="search"
                placeholder="Search election title or description..."
                value="<?php echo e($search); ?>"
            >

            <select name="category">
                <option value="">All Categories / Posts</option>

                <?php foreach ($categories as $category): ?>
                    <option
                        value="<?php echo e($category); ?>"
                        <?php echo $categoryFilter === $category ? 'selected' : ''; ?>
                    >
                        <?php echo e($category); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">All Statuses</option>

                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option
                        value="<?php echo e($statusOption); ?>"
                        <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>
                    >
                        <?php echo e(ucfirst($statusOption)); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn filter-btn">
                Search / Filter
            </button>

            <a href="manage_elections.php" class="reset-link">Reset</a>

        </form>

        <?php if (!empty($elections)): ?>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Election</th>
                            <th>Category / Post</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Candidates</th>
                            <th>Votes</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($elections as $election): ?>
                        <tr>

                            <td>
                                <div class="election-title">
                                    <?php echo e($election['title']); ?>
                                </div>

                                <span class="election-id">
                                    Election #<?php echo (int)$election['id']; ?>
                                </span>
                            </td>

                            <td>
                                <span class="category-label">
                                    <?php echo e($election['category'] ?: 'Other'); ?>
                                </span>
                            </td>

                            <td>
                                <div class="date-text">
                                    <strong>
                                        <?php echo e(dateDisplay($election['start_date'], 'd M Y')); ?>
                                    </strong>
                                    <small>
                                        <?php echo e(dateDisplay($election['start_date'], 'h:i A')); ?>
                                    </small>
                                </div>
                            </td>

                            <td>
                                <div class="date-text">
                                    <strong>
                                        <?php echo e(dateDisplay($election['end_date'], 'd M Y')); ?>
                                    </strong>
                                    <small>
                                        <?php echo e(dateDisplay($election['end_date'], 'h:i A')); ?>
                                    </small>
                                </div>
                            </td>

                            <td>
                                <span class="count-badge">
                                    <?php echo (int)$election['candidate_count']; ?>
                                </span>
                            </td>

                            <td>
                                <span class="count-badge">
                                    <?php echo (int)$election['vote_count']; ?>
                                </span>
                            </td>

                            <td>
                                <span class="status <?php echo e(statusClass($election['status'])); ?>">
                                    <?php echo e(ucfirst($election['status'])); ?>
                                </span>
                            </td>

                            <td class="actions-cell">

                                <a
                                    href="edit_election.php?id=<?php echo (int)$election['id']; ?>"
                                    class="action-link"
                                >Edit</a>

                                <a
                                    href="results.php?election_id=<?php echo (int)$election['id']; ?>"
                                    class="action-link"
                                >Results</a>

                                <?php if ($election['status'] === 'active'): ?>

                                    <form
                                        method="POST"
                                        class="action-form"
                                        onsubmit="return confirm('Close this election? Existing votes will be preserved.');"
                                    >
                                        <input type="hidden" name="csrf_token"
                                               value="<?php echo e($_SESSION['csrf_token']); ?>">

                                        <input type="hidden" name="election_id"
                                               value="<?php echo (int)$election['id']; ?>">

                                        <button
                                            type="submit"
                                            name="close_election"
                                            value="1"
                                            class="action-link close-btn"
                                        >Close</button>
                                    </form>

                                <?php elseif ($election['status'] === 'completed'): ?>

                                    <form
                                        method="POST"
                                        class="action-form"
                                        onsubmit="return confirm('Reopen this election for 24 hours? Existing votes will be preserved.');"
                                    >
                                        <input type="hidden" name="csrf_token"
                                               value="<?php echo e($_SESSION['csrf_token']); ?>">

                                        <input type="hidden" name="election_id"
                                               value="<?php echo (int)$election['id']; ?>">

                                        <button
                                            type="submit"
                                            name="reopen_election"
                                            value="1"
                                            class="action-link reopen-btn"
                                        >Reopen</button>
                                    </form>

                                <?php endif; ?>

                                <form
                                    method="POST"
                                    class="action-form"
                                    onsubmit="return confirmDelete(<?php echo (int)$election['id']; ?>, <?php echo (int)$election['vote_count']; ?>);"
                                >
                                    <input type="hidden" name="csrf_token"
                                           value="<?php echo e($_SESSION['csrf_token']); ?>">

                                    <input type="hidden" name="election_id"
                                           value="<?php echo (int)$election['id']; ?>">

                                    <button
                                        type="submit"
                                        name="delete_election"
                                        value="1"
                                        class="action-link delete-btn"
                                    >Delete</button>
                                </form>

                            </td>
                        </tr>
                    <?php endforeach; ?>

                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <div class="empty-icon">▦</div>
                <h3>No elections found</h3>

                <p>
                    <?php if ($search !== '' || $categoryFilter !== '' || $statusFilter !== ''): ?>
                        Try changing your search or filters.
                    <?php else: ?>
                        You have not created any elections yet.
                    <?php endif; ?>
                </p>

                <?php if ($search !== '' || $categoryFilter !== '' || $statusFilter !== ''): ?>
                    <a href="manage_elections.php" class="btn btn-light">
                        Clear Filters
                    </a>
                <?php else: ?>
                    <a href="create_election.php" class="btn">
                        + Create Your First Election
                    </a>
                <?php endif; ?>
            </div>

        <?php endif; ?>

        <div class="table-footer">
            <span>Showing <?php echo count($elections); ?> election(s).</span>
            <span>VotePortal Admin Panel</span>
        </div>

    </section>

</main>

<footer class="footer">
    © 2026 VotePortal · Online Voting System
</footer>

<script>
function confirmDelete(id, votes) {
    if (votes > 0) {
        alert(
            "Election #" + id +
            " already has votes and cannot be deleted."
        );
        return false;
    }

    return confirm(
        "Delete Election #" + id + "?\n\n" +
        "Its candidate application records will also be deleted. " +
        "This action cannot be undone."
    );
}
</script>

</body>
</html>
