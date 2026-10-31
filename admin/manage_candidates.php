
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

function dateDisplay($value) {
    return $value && strtotime($value) !== false
        ? date('d M Y, h:i A', strtotime($value))
        : 'Not available';
}

function candidatePhotoUrl($path) {
    if (!is_string($path) || $path === '') {
        return '';
    }

    $prefix = 'uploads/profiles/';

    if (strpos($path, $prefix) !== 0) {
        return '';
    }

    $filename = substr($path, strlen($prefix));

    if ($filename === '' || basename($filename) !== $filename) {
        return '';
    }

    $absolute = __DIR__ . '/../uploads/profiles/' . $filename;

    if (!is_file($absolute)) {
        return '';
    }

    return '../uploads/profiles/' . rawurlencode($filename);
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = $_SESSION['manage_candidates_message'] ?? '';
$messageType = $_SESSION['manage_candidates_message_type'] ?? 'success';

unset(
    $_SESSION['manage_candidates_message'],
    $_SESSION['manage_candidates_message_type']
);

$allowedStatuses = ['pending', 'approved', 'rejected'];

/* APPROVE / REJECT */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token = $_POST['csrf_token'] ?? '';
        $candidateId = filter_input(INPUT_POST, 'candidate_id', FILTER_VALIDATE_INT);
        $newStatus = $_POST['new_status'] ?? '';

        if (!is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)) {
            throw new Exception('Security validation failed. Please try again.');
        }

        if (!$candidateId || $candidateId < 1) {
            throw new Exception('Invalid candidate selected.');
        }

        if (!in_array($newStatus, ['approved', 'rejected'], true)) {
            throw new Exception('Invalid candidate action.');
        }

        $check = $conn->prepare(
            "SELECT id, election_id
             FROM candidates
             WHERE id = ?"
        );
        $check->bind_param("i", $candidateId);
        $check->execute();
        $candidateRecord = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$candidateRecord) {
            throw new Exception('Candidate application not found.');
        }

        // votes.candidate_id stores candidates.id in this project.
        $voteCheck = $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM votes
             WHERE election_id = ? AND candidate_id = ?"
        );

        $voteCheck->bind_param(
            "ii",
            $candidateRecord['election_id'],
            $candidateId
        );
        $voteCheck->execute();
        $candidateVotes = (int)$voteCheck->get_result()->fetch_assoc()['total'];
        $voteCheck->close();

        if ($candidateVotes > 0) {
            throw new Exception(
                'This candidate already has votes. Their status cannot be changed.'
            );
        }

        $update = $conn->prepare(
            "UPDATE candidates SET status = ? WHERE id = ?"
        );
        $update->bind_param("si", $newStatus, $candidateId);

        if (!$update->execute()) {
            $update->close();
            throw new Exception('Unable to update candidate status.');
        }

        $update->close();

        $_SESSION['manage_candidates_message'] =
            'Candidate application ' . $newStatus . ' successfully.';
        $_SESSION['manage_candidates_message_type'] = 'success';

    } catch (Throwable $ex) {
        $_SESSION['manage_candidates_message'] = $ex->getMessage();
        $_SESSION['manage_candidates_message_type'] = 'error';
    }

    $redirectParams = [];

    foreach (['search', 'status', 'election_id'] as $key) {
        if (isset($_POST[$key]) && $_POST[$key] !== '') {
            $redirectParams[$key] = $_POST[$key];
        }
    }

    $redirect = 'manage_candidates.php';

    if ($redirectParams) {
        $redirect .= '?' . http_build_query($redirectParams);
    }

    header("Location: " . $redirect);
    exit;
}

/* SEARCH AND FILTERS */
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$electionFilter = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

if (!$electionFilter || $electionFilter < 1) {
    $electionFilter = 0;
}

/* ELECTION DROPDOWN */
$elections = [];
$electionResult = $conn->query(
    "SELECT id, title FROM elections ORDER BY id DESC"
);

if ($electionResult) {
    while ($row = $electionResult->fetch_assoc()) {
        $elections[] = $row;
    }
}

/* STATISTICS */
$stats = [
    'total' => 0,
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0
];

$statsResult = $conn->query(
    "SELECT status, COUNT(*) AS total
     FROM candidates
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

/* CANDIDATE APPLICATIONS */
$sql = "
    SELECT
        c.id AS candidate_id,
        c.user_id,
        c.election_id,
        c.manifesto,
        c.status,
        c.applied_at,
        u.name AS candidate_name,
        u.email AS candidate_email,
        u.profile_image AS candidate_profile_image,
        e.title AS election_title,
        (
            SELECT COUNT(*)
            FROM votes v
            WHERE v.election_id = c.election_id
              AND v.candidate_id = c.id
        ) AS vote_count
    FROM candidates c
    INNER JOIN users u ON u.id = c.user_id
    INNER JOIN elections e ON e.id = c.election_id
    WHERE 1 = 1
";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (
        u.name LIKE ?
        OR u.email LIKE ?
        OR e.title LIKE ?
        OR c.manifesto LIKE ?
    )";

    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if ($statusFilter !== '') {
    $sql .= " AND c.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}

if ($electionFilter > 0) {
    $sql .= " AND c.election_id = ?";
    $params[] = $electionFilter;
    $types .= 'i';
}

$sql .= "
    ORDER BY
        CASE c.status
            WHEN 'pending' THEN 1
            WHEN 'approved' THEN 2
            WHEN 'rejected' THEN 3
            ELSE 4
        END,
        c.applied_at DESC
";

$candidates = [];
$queryError = '';

$stmt = $conn->prepare($sql);

if ($stmt) {
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $candidates[] = $row;
        }
    } else {
        $queryError = 'Unable to load candidate applications.';
    }

    $stmt->close();
} else {
    $queryError = 'Unable to prepare candidate query.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Candidates | VotePortal</title>

<style>
:root {
    --primary: #9A3412;
    --primary-dark: #7C2D12;
    --orange: #C2410C;
    --page: #FFF7ED;
    --panel: #FCEBDD;
    --panel-alt: #F8DDC5;
    --table-head: #F2D0B5;
    --border: #F0D2BB;
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
    background: #FFF9F3;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.logo {
    font-size: 24px;
    font-weight: 850;
    color: var(--primary);
    text-decoration: none;
}

.logo span {
    color: var(--orange);
}

.nav-links {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
}

.nav-links a {
    color: var(--primary);
    text-decoration: none;
    font-size: 13px;
    font-weight: 750;
}

.container {
    max-width: 1500px;
    width: calc(100% - 36px);
    margin: 28px auto 40px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 18px;
    margin-bottom: 22px;
}

.eyebrow {
    color: var(--orange);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 850;
    margin-bottom: 6px;
}

h1 {
    font-size: clamp(25px, 3vw, 32px);
}

.page-header p {
    color: var(--muted);
    font-size: 14px;
    margin-top: 5px;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 10px 15px;
    border: 1px solid var(--primary);
    border-radius: 8px;
    background: var(--primary);
    color: #FFF7ED;
    font-size: 12px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

.btn:hover {
    background: var(--primary-dark);
}

.btn-light {
    background: #F5D9C0;
    color: var(--primary);
    border-color: #E8BFA0;
}

.alert {
    padding: 13px 16px;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 13px;
    overflow-wrap: anywhere;
}

.alert.success {
    background: #DCEBD6;
    color: #245B26;
}

.alert.error {
    background: #F8DAD4;
    color: #991B1B;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}

.stat-card {
    padding: 18px;
    border: 1px solid var(--border);
    background: #FBE7D5;
    border-radius: 13px;
}

.stat-label {
    color: #6B5142;
    font-size: 12px;
    font-weight: 750;
}

.stat-number {
    margin-top: 10px;
    font-size: 28px;
    font-weight: 850;
}

.stat-card small {
    color: var(--muted);
    font-size: 11px;
}

.panel {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 15px;
    overflow: hidden;
}

.panel-header {
    padding: 20px 24px;
    background: var(--panel-alt);
    border-bottom: 1px solid var(--border);
}

.panel-header h2 {
    font-size: 18px;
}

.panel-header p {
    color: var(--muted);
    font-size: 12px;
    margin-top: 4px;
}

.filter-form {
    display: grid;
    grid-template-columns: minmax(180px, 1.4fr) minmax(160px, .8fr) minmax(160px, .9fr) auto auto;
    gap: 10px;
    padding: 18px 24px;
    background: #FBE7D5;
    border-bottom: 1px solid var(--border);
}

.filter-form input,
.filter-form select {
    width: 100%;
    min-width: 0;
    min-height: 42px;
    padding: 9px 11px;
    border: 1px solid #E9C8AE;
    border-radius: 8px;
    background: #FFF4EA;
    font-family: inherit;
    font-size: 12px;
    color: var(--text);
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

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1000px;
    table-layout: fixed;
}

th {
    padding: 13px 12px;
    text-align: left;
    color: #71300F;
    background: var(--table-head);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    border-bottom: 1px solid #EBC5A5;
}

td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
    font-size: 12px;
    background: var(--panel);
    overflow-wrap: anywhere;
}

tbody tr:nth-child(even) td {
    background: #F9E3D0;
}

tbody tr:hover td {
    background: #F5D8BE;
}

/* Column widths keep the manifesto column compact. */
th:nth-child(1) { width: 23%; }
th:nth-child(2) { width: 17%; }
th:nth-child(3) { width: 20%; }
th:nth-child(4) { width: 13%; }
th:nth-child(5) { width: 7%; }
th:nth-child(6) { width: 9%; }
th:nth-child(7) { width: 11%; }

.candidate-cell {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
}

.candidate-avatar {
    width: 46px;
    height: 46px;
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--primary);
    border: 2px solid #FFF9F3;
    color: white;
    font-size: 18px;
    font-weight: 800;
}

.candidate-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.candidate-name {
    font-size: 13px;
    font-weight: 800;
}

.candidate-email {
    display: block;
    color: var(--muted);
    font-size: 11px;
    margin-top: 3px;
}

.details-btn {
    display: inline-flex;
    align-items: center;
    margin-top: 7px;
    padding: 6px 9px;
    border-radius: 6px;
    background: var(--primary);
    border: 1px solid var(--primary);
    color: #FFF7ED;
    text-decoration: none;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.details-btn:hover {
    background: var(--primary-dark);
}

.election-title {
    font-size: 12px;
    font-weight: 750;
}

.election-id {
    color: var(--muted);
    font-size: 11px;
    margin-top: 4px;
}

/* Manifesto is limited to three lines in the table. */
.manifesto {
    width: 100%;
    max-width: 230px;
    color: #665044;
    overflow-wrap: anywhere;
    white-space: normal;
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
    line-clamp: 3;
    overflow: hidden;
    line-height: 1.5;
    max-height: 4.5em;
}

.date-text {
    font-size: 11px;
    color: #665044;
}

.status {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 850;
    text-transform: capitalize;
}

.status.pending {
    background: #F7D1A8;
    color: #71300F;
}

.status.approved {
    background: #D8EED5;
    color: #215C27;
}

.status.rejected {
    background: #F8D8D3;
    color: #991B1B;
}

.status.unknown {
    background: #EDE0D4;
    color: #514034;
}

.vote-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 30px;
    min-height: 30px;
    padding: 5px 8px;
    background: #F5D5B9;
    color: #85300D;
    border-radius: 8px;
    font-weight: 850;
}

.actions-cell {
    min-width: 100px;
}

.action-form {
    margin: 0 0 6px;
}

.action-btn {
    padding: 7px 9px;
    border-radius: 7px;
    font-family: inherit;
    font-size: 11px;
    font-weight: 800;
    border: 1px solid;
    cursor: pointer;
}

.approve-btn {
    background: #D8EED5;
    border-color: #B7D9B0;
    color: #245B26;
}

.reject-btn {
    background: #F8D8D3;
    border-color: #EAB5AC;
    color: #991B1B;
}

.action-note {
    display: block;
    color: var(--muted);
    font-size: 10px;
}

.empty-state {
    text-align: center;
    padding: 50px 20px;
}

.table-footer {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 15px 24px;
    background: var(--panel-alt);
    color: var(--muted);
    font-size: 12px;
}

.footer {
    text-align: center;
    color: var(--muted);
    padding: 25px 15px;
    font-size: 12px;
}

@media (max-width: 1000px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
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

    .container {
        width: calc(100% - 24px);
        margin: 22px auto 30px;
    }

    .page-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .stats-grid {
        gap: 10px;
    }

    .stat-card {
        padding: 14px;
    }

    .filter-form {
        grid-template-columns: minmax(0, 1fr);
        padding: 15px;
    }

    .table-footer {
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
        <a href="manage_elections.php">Manage Elections</a>
        <a href="dashboard.php">← Dashboard</a>
    </nav>
</header>

<main class="container">

    <section class="page-header">
        <div>
            <div class="eyebrow">Admin Workspace / Candidates</div>
            <h1>Manage Candidates</h1>
            <p>Review applications and manage candidate approval status.</p>
        </div>

        <a href="manage_elections.php" class="btn">Manage Elections</a>
    </section>

    <?php if ($message !== ''): ?>
        <div class="alert <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($queryError !== ''): ?>
        <div class="alert error"><?= e($queryError) ?></div>
    <?php endif; ?>

    <section class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">▦ Total Applications</div>
            <div class="stat-number"><?= (int)$stats['total'] ?></div>
            <small>All candidate applications</small>
        </div>

        <div class="stat-card">
            <div class="stat-label">◷ Pending</div>
            <div class="stat-number"><?= (int)$stats['pending'] ?></div>
            <small>Awaiting review</small>
        </div>

        <div class="stat-card">
            <div class="stat-label">✓ Approved</div>
            <div class="stat-number"><?= (int)$stats['approved'] ?></div>
            <small>Approved candidates</small>
        </div>

        <div class="stat-card">
            <div class="stat-label">× Rejected</div>
            <div class="stat-number"><?= (int)$stats['rejected'] ?></div>
            <small>Rejected applications</small>
        </div>
    </section>

    <section class="panel">
        <div class="panel-header">
            <h2>Candidate Applications</h2>
            <p>Manifesto preview is limited to three lines. Open full details to read everything.</p>
        </div>

        <form method="GET" class="filter-form">
            <input
                type="search"
                name="search"
                placeholder="Search candidate, email or election..."
                value="<?= e($search) ?>"
            >

            <select name="election_id">
                <option value="">All Elections</option>
                <?php foreach ($elections as $item): ?>
                    <option
                        value="<?= (int)$item['id'] ?>"
                        <?= $electionFilter === (int)$item['id'] ? 'selected' : '' ?>
                    >
                        #<?= (int)$item['id'] ?> — <?= e($item['title']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">All Application Statuses</option>
                <?php foreach ($allowedStatuses as $statusOption): ?>
                    <option
                        value="<?= e($statusOption) ?>"
                        <?= $statusFilter === $statusOption ? 'selected' : '' ?>
                    >
                        <?= e(ucfirst($statusOption)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn">Search / Filter</button>
            <a href="manage_candidates.php" class="reset-link">Reset</a>
        </form>

        <?php if (!empty($candidates)): ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Candidate</th>
                            <th>Election</th>
                            <th>Manifesto Preview</th>
                            <th>Applied On</th>
                            <th>Votes</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($candidates as $candidate): ?>
                        <?php
                        $photoUrl = candidatePhotoUrl(
                            $candidate['candidate_profile_image'] ?? ''
                        );

                        $candidateInitial = strtoupper(
                            substr(trim($candidate['candidate_name'] ?? 'C'), 0, 1)
                        );

                        if ($candidateInitial === '') {
                            $candidateInitial = 'C';
                        }

                        $manifesto = trim((string)($candidate['manifesto'] ?? ''));
                        $manifesto = $manifesto !== ''
                            ? preg_replace('/\s+/', ' ', $manifesto)
                            : 'No manifesto provided.';
                        ?>
                        <tr>
                            <td>
                                <div class="candidate-cell">
                                    <div class="candidate-avatar">
                                        <?php if ($photoUrl !== ''): ?>
                                            <img
                                                src="<?= e($photoUrl) ?>"
                                                alt="Candidate photo"
                                                loading="lazy"
                                                onerror="this.style.display='none';this.parentElement.textContent='<?= e($candidateInitial) ?>';"
                                            >
                                        <?php else: ?>
                                            <?= e($candidateInitial) ?>
                                        <?php endif; ?>
                                    </div>

                                    <div>
                                        <div class="candidate-name">
                                            <?= e($candidate['candidate_name']) ?>
                                        </div>

                                        <span class="candidate-email">
                                            <?= e($candidate['candidate_email']) ?>
                                        </span>

                                        <a
                                            class="details-btn"
                                            href="candidate_details.php?id=<?= (int)$candidate['candidate_id'] ?>"
                                        >
                                            View Full Details →
                                        </a>
                                    </div>
                                </div>
                            </td>

                            <td>
                                <div class="election-title">
                                    <?= e($candidate['election_title']) ?>
                                </div>
                                <div class="election-id">
                                    Election #<?= (int)$candidate['election_id'] ?>
                                </div>
                            </td>

                            <td>
                                <div class="manifesto">
                                    <?= e($manifesto) ?>
                                </div>
                            </td>

                            <td>
                                <div class="date-text">
                                    <?= e(dateDisplay($candidate['applied_at'])) ?>
                                </div>
                            </td>

                            <td>
                                <span class="vote-badge">
                                    <?= (int)$candidate['vote_count'] ?>
                                </span>
                            </td>

                            <td>
                                <span class="status <?= e($candidate['status']) ?>">
                                    <?= e(ucfirst($candidate['status'])) ?>
                                </span>
                            </td>

                            <td class="actions-cell">
                                <?php if ($candidate['status'] !== 'approved'): ?>
                                    <form
                                        method="POST"
                                        class="action-form"
                                        onsubmit="return confirm('Approve this candidate application?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="candidate_id" value="<?= (int)$candidate['candidate_id'] ?>">
                                        <input type="hidden" name="new_status" value="approved">
                                        <input type="hidden" name="search" value="<?= e($search) ?>">
                                        <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                                        <input type="hidden" name="election_id" value="<?= (int)$electionFilter ?>">

                                        <button type="submit" class="action-btn approve-btn">
                                            Approve
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($candidate['status'] !== 'rejected'): ?>
                                    <form
                                        method="POST"
                                        class="action-form"
                                        onsubmit="return confirm('Reject this candidate application?');"
                                    >
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="candidate_id" value="<?= (int)$candidate['candidate_id'] ?>">
                                        <input type="hidden" name="new_status" value="rejected">
                                        <input type="hidden" name="search" value="<?= e($search) ?>">
                                        <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                                        <input type="hidden" name="election_id" value="<?= (int)$electionFilter ?>">

                                        <button type="submit" class="action-btn reject-btn">
                                            Reject
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ((int)$candidate['vote_count'] > 0): ?>
                                    <span class="action-note">
                                        Has votes: status changes are locked.
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <h3>No candidate applications found</h3>
                <p>Try changing the search text or filters.</p>
                <a href="manage_candidates.php" class="btn btn-light">Clear Filters</a>
            </div>
        <?php endif; ?>

        <div class="table-footer">
            <span>Showing <?= count($candidates) ?> application(s).</span>
            <span>VotePortal Admin Panel</span>
        </div>
    </section>
</main>

<footer class="footer">
    © <?= date('Y') ?> VotePortal · Online Voting System
</footer>

</body>
</html>
