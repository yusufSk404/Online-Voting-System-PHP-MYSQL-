
<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$candidateId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$candidateId || $candidateId < 1) {
    exit('Invalid candidate application ID.');
}

$sql = "
    SELECT
        c.id AS candidate_id,
        c.user_id,
        c.election_id,
        c.manifesto,
        c.status AS application_status,
        c.applied_at,
        u.name,
        u.email,
        u.profile_image,
        e.title AS election_title,
        e.category AS election_category,
        e.description AS election_description,
        e.start_date,
        e.end_date,
        e.status AS election_status,
        (
            SELECT COUNT(*)
            FROM votes v
            WHERE v.election_id = c.election_id
              AND v.candidate_id = c.user_id
        ) AS vote_count
    FROM candidates c
    INNER JOIN users u ON u.id = c.user_id
    INNER JOIN elections e ON e.id = c.election_id
    WHERE c.id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $candidateId);
$stmt->execute();
$candidate = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$candidate) {
    http_response_code(404);
    exit('Candidate application not found.');
}

$photoUrl = '';
$storedPath = $candidate['profile_image'] ?? '';
$prefix = 'uploads/profiles/';

if (is_string($storedPath) && strpos($storedPath, $prefix) === 0) {
    $filename = substr($storedPath, strlen($prefix));

    if ($filename !== '' && basename($filename) === $filename) {
        $diskPath = __DIR__ . '/../uploads/profiles/' . $filename;

        if (is_file($diskPath)) {
            $photoUrl = '../uploads/profiles/' . rawurlencode($filename);
        }
    }
}

function showDate($date) {
    if (!$date || strtotime($date) === false) {
        return 'Not available';
    }

    return date('d M Y, h:i A', strtotime($date));
}

$initial = strtoupper(substr(trim($candidate['name'] ?? 'C'), 0, 1));
if ($initial === '') {
    $initial = 'C';
}

$status = strtolower($candidate['application_status'] ?? 'unknown');
$statusClass = in_array($status, ['pending', 'approved', 'rejected'], true)
    ? $status : 'unknown';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Candidate Details | VotePortal</title>

<style>
:root {
    --primary: #9A3412;
    --dark: #7C2D12;
    --page: #FFF7ED;
    --panel: #FCEBDD;
    --peach: #F8DDC5;
    --border: #F0D2BB;
    --text: #292524;
    --muted: #78716C;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: var(--page);
    color: var(--text);
    line-height: 1.6;
}

.navbar {
    padding: 16px 5%;
    background: #FFF9F3;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.logo {
    color: var(--primary);
    text-decoration: none;
    font-size: 23px;
    font-weight: 850;
}

.logo span {
    color: #C2410C;
}

.back-link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
}

.container {
    max-width: 1000px;
    width: calc(100% - 32px);
    margin: 35px auto;
}

.heading {
    margin-bottom: 22px;
}

.heading small {
    color: #C2410C;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-size: 11px;
    font-weight: 800;
}

.heading h1 {
    font-size: 30px;
    margin: 5px 0;
}

.heading p {
    color: var(--muted);
    font-size: 14px;
}

.profile-card {
    display: flex;
    align-items: center;
    gap: 24px;
    padding: 28px;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 16px;
    margin-bottom: 22px;
}

.avatar {
    width: 130px;
    height: 130px;
    flex-shrink: 0;
    border-radius: 50%;
    overflow: hidden;
    background: var(--primary);
    color: white;
    border: 4px solid #FFF9F3;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 42px;
    font-weight: 800;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.profile-info h2 {
    font-size: 25px;
    overflow-wrap: anywhere;
}

.profile-info p {
    color: var(--muted);
    font-size: 14px;
    overflow-wrap: anywhere;
}

.badge {
    display: inline-block;
    margin-top: 12px;
    padding: 5px 12px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 800;
    text-transform: capitalize;
}

.pending {
    color: #71300F;
    background: #F7D1A8;
}

.approved {
    color: #215C27;
    background: #D8EED5;
}

.rejected {
    color: #991B1B;
    background: #F8D8D3;
}

.unknown {
    color: #514034;
    background: #EDE0D4;
}

.details-card {
    background: #FFF9F3;
    border: 1px solid var(--border);
    border-radius: 15px;
    overflow: hidden;
    margin-bottom: 22px;
}

.card-heading {
    padding: 17px 22px;
    background: var(--peach);
    border-bottom: 1px solid var(--border);
    font-size: 17px;
    font-weight: 800;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.detail {
    padding: 17px 22px;
    border-bottom: 1px solid #F0E0D3;
    overflow-wrap: anywhere;
}

.detail:nth-child(odd) {
    border-right: 1px solid #F0E0D3;
}

.detail-label {
    display: block;
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .6px;
    margin-bottom: 5px;
    font-weight: 750;
}

.detail-value {
    font-size: 14px;
    font-weight: 650;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.description {
    padding: 22px;
    font-size: 14px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 22px;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 11px 17px;
    border-radius: 8px;
    text-decoration: none;
    background: var(--primary);
    color: white;
    border: 1px solid var(--primary);
    font-size: 13px;
    font-weight: 800;
}

.btn:hover {
    background: var(--dark);
}

.btn-light {
    color: var(--primary);
    background: #F5D9C0;
    border-color: #E8BFA0;
}

.btn-light:hover {
    background: #EFC9A9;
}

footer {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 12px;
}

@media (max-width: 600px) {
    .profile-card {
        flex-direction: column;
        align-items: flex-start;
        padding: 20px;
    }

    .details-grid {
        grid-template-columns: 1fr;
    }

    .detail:nth-child(odd) {
        border-right: 0;
    }

    .heading h1 {
        font-size: 25px;
    }

    .navbar {
        padding: 14px 18px;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a href="dashboard.php" class="logo">Vote<span>Portal</span></a>
    <a href="manage_candidates.php" class="back-link">← Back to Candidates</a>
</header>

<main class="container">

    <section class="heading">
        <small>Admin Workspace / Candidate Profile</small>
        <h1>Candidate Details</h1>
        <p>View the candidate's profile and election application information.</p>
    </section>

    <section class="profile-card">
        <div class="avatar">
            <?php if ($photoUrl !== ''): ?>
                <img src="<?= e($photoUrl) ?>" alt="Candidate profile photo">
            <?php else: ?>
                <?= e($initial) ?>
            <?php endif; ?>
        </div>

        <div class="profile-info">
            <h2><?= e($candidate['name']) ?></h2>
            <p><?= e($candidate['email']) ?></p>
            <span class="badge <?= e($statusClass) ?>">
                <?= e($candidate['application_status']) ?> Application
            </span>
        </div>
    </section>

    <section class="details-card">
        <div class="card-heading">Personal & Application Information</div>

        <div class="details-grid">
            <div class="detail">
                <span class="detail-label">Candidate Name</span>
                <div class="detail-value"><?= e($candidate['name']) ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Email Address</span>
                <div class="detail-value"><?= e($candidate['email']) ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Application ID</span>
                <div class="detail-value">#<?= (int)$candidate['candidate_id'] ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Application Date</span>
                <div class="detail-value"><?= e(showDate($candidate['applied_at'])) ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Application Status</span>
                <div class="detail-value"><?= e(ucfirst($candidate['application_status'])) ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Total Votes</span>
                <div class="detail-value"><?= (int)$candidate['vote_count'] ?></div>
            </div>
        </div>
    </section>

    <section class="details-card">
        <div class="card-heading">Election Information</div>

        <div class="details-grid">
            <div class="detail">
                <span class="detail-label">Election Name</span>
                <div class="detail-value"><?= e($candidate['election_title']) ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Election ID</span>
                <div class="detail-value">#<?= (int)$candidate['election_id'] ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Category</span>
                <div class="detail-value"><?= e($candidate['election_category'] ?: 'Not specified') ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Election Status</span>
                <div class="detail-value"><?= e(ucfirst($candidate['election_status'])) ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Election Start</span>
                <div class="detail-value"><?= e(showDate($candidate['start_date'])) ?></div>
            </div>

            <div class="detail">
                <span class="detail-label">Election End</span>
                <div class="detail-value"><?= e(showDate($candidate['end_date'])) ?></div>
            </div>
        </div>

        <div class="card-heading">Candidate Manifesto</div>
        <div class="description">
            <?= e(trim((string)$candidate['manifesto']) !== ''
                ? $candidate['manifesto']
                : 'No manifesto provided.') ?>
        </div>

        <div class="card-heading">Election Description</div>
        <div class="description">
            <?= e(trim((string)$candidate['election_description']) !== ''
                ? $candidate['election_description']
                : 'No election description provided.') ?>
        </div>
    </section>

    <div class="actions">
        <a href="manage_candidates.php" class="btn">← Back to Candidates</a>
        <a href="manage_elections.php" class="btn btn-light">Manage Elections</a>
    </div>

</main>

<footer>© <?= date('Y') ?> VotePortal · Online Voting System</footer>

</body>
</html>
