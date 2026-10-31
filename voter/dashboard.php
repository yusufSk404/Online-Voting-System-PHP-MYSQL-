
<?php
session_start();
require_once '../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'voter'
) {
    header("Location: ../login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$name = $_SESSION['name'] ?? 'Voter';

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatDate($value) {
    if (empty($value)) {
        return 'Not specified';
    }

    $time = strtotime($value);
    return $time ? date('d M Y, h:i A', $time) : 'Not specified';
}

function getCount($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return 0;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : [];
    $stmt->close();

    return (int)($row['total'] ?? 0);
}

/* Dashboard statistics */
$activeElections = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM elections
     WHERE status = 'active'"
);

$completedElections = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM elections
     WHERE status = 'completed'"
);

$votesSubmitted = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM votes
     WHERE voter_id = ?",
    "i",
    [$userId]
);

$pendingElections = getCount(
    $conn,
    "SELECT COUNT(*) AS total
     FROM elections e
     WHERE e.status = 'active'
       AND NOT EXISTS (
           SELECT 1 FROM votes v
           WHERE v.election_id = e.id
             AND v.voter_id = ?
       )",
    "i",
    [$userId]
);

/* Active elections */
$elections = [];

$sql = "SELECT e.*,
        EXISTS (
            SELECT 1
            FROM votes v
            WHERE v.voter_id = ?
              AND v.election_id = e.id
        ) AS already_voted
        FROM elections e
        WHERE e.status = 'active'
        ORDER BY e.end_date ASC, e.id DESC";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $elections[] = $row;
        }
    }

    $stmt->close();
}

/* Voting history: candidate_id points to candidates.id */
$history = [];

$sql = "SELECT
            e.title,
            e.category,
            e.status AS election_status,
            v.voted_at,
            u.name AS candidate_name
        FROM votes v
        INNER JOIN elections e
            ON e.id = v.election_id
        LEFT JOIN candidates c
            ON c.id = v.candidate_id
           AND c.election_id = v.election_id
        LEFT JOIN users u
            ON u.id = c.user_id
        WHERE v.voter_id = ?
        ORDER BY v.voted_at DESC
        LIMIT 10";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $history[] = $row;
        }
    }

    $stmt->close();
}

/* Election results: match votes.candidate_id with candidates.id */
$resultsData = [];

$sql = "SELECT
            e.id AS election_id,
            e.title AS election_title,
            e.category,
            e.status AS election_status,
            e.end_date,
            c.id AS candidate_id,
            c.manifesto,
            u.name AS candidate_name,
            u.email AS candidate_email,
            COALESCE(vc.vote_count, 0) AS vote_count,
            COALESCE(et.total_votes, 0) AS total_votes
        FROM elections e
        INNER JOIN candidates c
            ON c.election_id = e.id
           AND c.status = 'approved'
        INNER JOIN users u
            ON u.id = c.user_id
        LEFT JOIN (
            SELECT election_id, candidate_id, COUNT(*) AS vote_count
            FROM votes
            GROUP BY election_id, candidate_id
        ) vc
            ON vc.election_id = e.id
           AND vc.candidate_id = c.id
        LEFT JOIN (
            SELECT election_id, COUNT(*) AS total_votes
            FROM votes
            GROUP BY election_id
        ) et
            ON et.election_id = e.id
        WHERE e.status IN ('active', 'completed')
        ORDER BY e.id DESC, vote_count DESC, u.name ASC";

$stmt = $conn->prepare($sql);

if ($stmt && $stmt->execute()) {
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $eid = (int)$row['election_id'];

        if (!isset($resultsData[$eid])) {
            $resultsData[$eid] = [
                'title' => $row['election_title'],
                'category' => $row['category'],
                'status' => $row['election_status'],
                'end_date' => $row['end_date'],
                'total_votes' => (int)$row['total_votes'],
                'candidates' => []
            ];
        }

        $resultsData[$eid]['candidates'][] = [
            'name' => $row['candidate_name'],
            'email' => $row['candidate_email'],
            'votes' => (int)$row['vote_count'],
            'manifesto' => $row['manifesto']
        ];
    }

    $stmt->close();
} elseif ($stmt) {
    $stmt->close();
    error_log('VotePortal: voter dashboard results query failed.');
}

/* Show latest four elections */
$resultsData = array_slice($resultsData, 0, 4, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Voter Dashboard | VotePortal</title>

<style>
:root {
    --primary: #C2410C;
    --dark: #9A3412;
    --accent: #EA580C;
    --bg: #FFF7ED;
    --white: #FFFFFF;
    --border: #FED7AA;
    --text: #292524;
    --muted: #78716C;
    --soft: #FFEDD5;
    --green: #166534;
    --green-bg: #DCFCE7;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html {
    scroll-behavior: smooth;
}

body {
    font-family: Arial, sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
}

a {
    text-decoration: none;
}

.navbar {
    background: white;
    padding: 18px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 10;
}

.logo {
    font-size: 25px;
    font-weight: bold;
    color: var(--dark);
}

.logo span {
    color: var(--accent);
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.nav-links a {
    color: var(--primary);
    font-size: 13px;
    font-weight: bold;
}

.nav-links .logout {
    background: var(--primary);
    color: white;
    padding: 11px 16px;
    border-radius: 8px;
}

.container {
    max-width: 1200px;
    margin: 30px auto;
    padding: 0 22px;
}

.welcome {
    background: linear-gradient(120deg, white, #FFF0DF);
    padding: 35px;
    border: 1px solid var(--border);
    border-radius: 18px;
    margin-bottom: 25px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 25px;
    box-shadow: 0 8px 25px rgba(154,52,18,.04);
}

.welcome-label {
    display: inline-block;
    color: var(--primary);
    background: var(--soft);
    padding: 8px 12px;
    border-radius: 30px;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1px;
    margin-bottom: 15px;
}

.welcome h1 {
    font-size: 32px;
    line-height: 1.3;
    margin-bottom: 10px;
}

.welcome h1 span {
    color: var(--accent);
}

.welcome p {
    color: var(--muted);
    line-height: 1.8;
}

.welcome-icon {
    width: 90px;
    height: 90px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    background: var(--soft);
    border-radius: 25px;
    font-size: 43px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
    margin-bottom: 35px;
}

.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 23px;
    box-shadow: 0 5px 18px rgba(154,52,18,.04);
    transition: .2s;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(154,52,18,.09);
}

.stat-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    background: var(--soft);
    color: var(--primary);
    display: grid;
    place-items: center;
    font-size: 22px;
    margin-bottom: 17px;
}

.stat-number {
    font-size: 32px;
    font-weight: bold;
    margin-bottom: 7px;
}

.stat-label {
    font-size: 13px;
    color: var(--muted);
    line-height: 1.5;
}

.section {
    margin-bottom: 38px;
}

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin-bottom: 19px;
}

.section-heading h2 {
    font-size: 24px;
}

.section-heading p {
    color: var(--muted);
    font-size: 13px;
    margin-top: 6px;
    line-height: 1.6;
}

.text-link {
    color: var(--primary);
    font-size: 13px;
    font-weight: bold;
}

.election-grid,
.results-grid,
.quick-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.election-card,
.result-card,
.panel,
.quick-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(154,52,18,.035);
}

.election-card {
    padding: 25px;
    transition: .2s;
}

.election-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(154,52,18,.08);
}

.status {
    display: inline-block;
    padding: 7px 11px;
    border-radius: 20px;
    background: var(--soft);
    color: var(--dark);
    font-size: 11px;
    font-weight: bold;
    margin-bottom: 14px;
}

.status.approved,
.status.completed {
    color: var(--green);
    background: var(--green-bg);
}

.election-card h3 {
    font-size: 21px;
    margin-bottom: 10px;
    overflow-wrap: anywhere;
}

.election-card p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.7;
    margin-bottom: 16px;
}

.election-meta {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding: 14px 0;
    border-top: 1px solid #F5E9DE;
    border-bottom: 1px solid #F5E9DE;
    margin-bottom: 18px;
}

.meta-label {
    color: var(--muted);
    font-size: 11px;
    margin-bottom: 5px;
}

.meta-value {
    font-size: 12px;
    font-weight: bold;
}

.btn {
    display: inline-block;
    background: var(--primary);
    color: white;
    border: 1px solid var(--primary);
    border-radius: 8px;
    padding: 12px 17px;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
}

.btn:hover {
    background: var(--dark);
}

.btn-light {
    background: white;
    color: var(--primary);
    border-color: var(--border);
}

.btn-light:hover {
    background: var(--soft);
}

.btn-disabled {
    display: inline-block;
    padding: 12px 17px;
    border-radius: 8px;
    background: #F5F5F4;
    color: #78716C;
    font-size: 13px;
    font-weight: bold;
}

.result-header {
    padding: 22px 24px;
    background: #FFFAF5;
    border-bottom: 1px solid #F5E9DE;
}

.result-header h3 {
    font-size: 20px;
    margin-bottom: 7px;
    overflow-wrap: anywhere;
}

.result-header p {
    color: var(--muted);
    font-size: 12px;
}

.result-body {
    padding: 20px 24px;
}

.candidate-result {
    padding: 15px 0;
    border-bottom: 1px solid #F5E9DE;
}

.candidate-result:last-child {
    border-bottom: none;
}

.candidate-line {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 12px;
}

.candidate-info {
    min-width: 0;
}

.candidate-info strong {
    display: block;
    font-size: 13px;
    margin-bottom: 7px;
    overflow-wrap: anywhere;
}

.candidate-info span {
    display: block;
    font-size: 11px;
    color: var(--muted);
}

.leader-text {
    color: var(--primary) !important;
    font-weight: bold;
}

.vote-total {
    color: var(--primary);
    font-size: 12px;
    font-weight: bold;
    white-space: nowrap;
}

.progress-track {
    height: 8px;
    background: #F5E7DA;
    border-radius: 20px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: var(--accent);
    border-radius: 20px;
}

.result-footer {
    padding: 15px 24px;
    color: var(--muted);
    border-top: 1px solid #F5E9DE;
    font-size: 11px;
    line-height: 1.7;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

.history-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

.history-table th {
    background: #FFFAF5;
    text-align: left;
    padding: 15px 18px;
    font-size: 11px;
    color: var(--muted);
    text-transform: uppercase;
    border-bottom: 1px solid var(--border);
}

.history-table td {
    padding: 17px 18px;
    border-bottom: 1px solid #F5E9DE;
    font-size: 12px;
    vertical-align: middle;
}

.history-table tr:last-child td {
    border-bottom: none;
}

.muted {
    color: var(--muted);
    font-size: 11px;
    margin-top: 5px;
}

.empty {
    background: white;
    border: 1px dashed #FDBA74;
    border-radius: 14px;
    padding: 38px 20px;
    text-align: center;
    color: var(--muted);
    line-height: 1.8;
    grid-column: 1 / -1;
}

.empty-icon {
    font-size: 32px;
    margin-bottom: 10px;
}

.empty h3 {
    color: var(--text);
    font-size: 18px;
    margin-bottom: 6px;
}

.empty p {
    font-size: 13px;
}

.quick-card {
    padding: 21px;
    display: flex;
    align-items: center;
    gap: 15px;
    color: var(--text);
    transition: .2s;
}

.quick-card:hover {
    border-color: #FDBA74;
    transform: translateY(-2px);
}

.quick-icon {
    width: 47px;
    height: 47px;
    flex-shrink: 0;
    border-radius: 13px;
    background: var(--soft);
    display: grid;
    place-items: center;
    font-size: 23px;
}

.quick-card strong {
    display: block;
    font-size: 14px;
    margin-bottom: 6px;
}

.quick-card span {
    display: block;
    font-size: 12px;
    color: var(--muted);
    line-height: 1.6;
}

.footer {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 13px;
}

@media (max-width: 900px) {
    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 650px) {
    .navbar {
        padding: 16px 5%;
        align-items: flex-start;
        flex-direction: column;
        gap: 14px;
    }

    .nav-links {
        gap: 15px;
    }

    .container {
        margin: 20px auto;
        padding: 0 14px;
    }

    .welcome {
        padding: 24px 20px;
    }

    .welcome h1 {
        font-size: 25px;
    }

    .welcome-icon {
        display: none;
    }

    .stats-grid,
    .election-grid,
    .results-grid,
    .quick-grid {
        grid-template-columns: 1fr;
    }

    .stat-card {
        padding: 19px;
    }

    .section-heading h2 {
        font-size: 21px;
    }

    .election-card {
        padding: 20px;
    }

    .result-header,
    .result-body,
    .result-footer {
        padding-left: 18px;
        padding-right: 18px;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a href="dashboard.php" class="logo">Vote<span>Portal</span></a>

    <nav class="nav-links">
        <a href="#elections">Active Elections</a>
        <a href="#results">Election Results</a>
        <a href="#history">Voting History</a>
        <a href="../logout.php" class="logout">Logout</a>
    </nav>
</header>

<main class="container">

    <!-- Welcome -->
    <section class="welcome">
        <div>
            <div class="welcome-label">VOTER PORTAL</div>

            <h1>
                Welcome, <span><?= h($name) ?>!</span>
            </h1>

            <p>
                Explore active elections, review candidate standings,
                cast your vote and keep track of your voting history
                from one place.
            </p>
        </div>

        <div class="welcome-icon">🗳️</div>
    </section>

    <!-- Statistics -->
    <section class="stats-grid">
        <article class="stat-card">
            <div class="stat-icon">🗳️</div>
            <div class="stat-number"><?= $activeElections ?></div>
            <div class="stat-label">Active Elections</div>
        </article>

        <article class="stat-card">
            <div class="stat-icon">✓</div>
            <div class="stat-number"><?= $votesSubmitted ?></div>
            <div class="stat-label">Elections You Have Voted In</div>
        </article>

        <article class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-number"><?= $pendingElections ?></div>
            <div class="stat-label">Elections Awaiting Your Vote</div>
        </article>

        <article class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-number"><?= $completedElections ?></div>
            <div class="stat-label">Completed Elections</div>
        </article>
    </section>

    <!-- Active Elections -->
    <section class="section" id="elections">
        <div class="section-heading">
            <div>
                <h2>Active Elections</h2>
                <p>Choose an election to view candidates and vote.</p>
            </div>
        </div>

        <?php if (count($elections) > 0): ?>
            <div class="election-grid">

                <?php foreach ($elections as $election): ?>
                    <article class="election-card">

                        <span class="status">
                            ● Active Election
                        </span>

                        <h3><?= h($election['title']) ?></h3>

                        <p>
                            <?= h(
                                $election['description']
                                ?: 'Participate in this election and make your voice count.'
                            ) ?>
                        </p>

                        <div class="election-meta">
                            <div>
                                <div class="meta-label">Category</div>
                                <div class="meta-value">
                                    <?= h($election['category'] ?: 'General') ?>
                                </div>
                            </div>

                            <div>
                                <div class="meta-label">Voting Deadline</div>
                                <div class="meta-value">
                                    <?= h(formatDate($election['end_date'])) ?>
                                </div>
                            </div>
                        </div>

                        <?php if ((int)$election['already_voted'] === 1): ?>
                            <span class="btn-disabled">
                                ✓ Vote Already Submitted
                            </span>
                        <?php else: ?>
                            <a
                                class="btn"
                                href="vote.php?election_id=<?= (int)$election['id'] ?>"
                            >
                                View Candidates →
                            </a>
                        <?php endif; ?>

                    </article>
                <?php endforeach; ?>

            </div>
        <?php else: ?>
            <div class="empty">
                <div class="empty-icon">🗳️</div>
                <h3>No Active Elections</h3>
                <p>
                    There are no active elections at the moment.
                    Please check again later.
                </p>
            </div>
        <?php endif; ?>
    </section>

    <!-- Election Results -->
    <section class="section" id="results">
        <div class="section-heading">
            <div>
                <h2>Election Results</h2>
                <p>
                    View current standings for active elections and final
                    results for completed elections.
                </p>
            </div>
        </div>

        <?php if (count($resultsData) > 0): ?>

            <div class="results-grid">

                <?php foreach ($resultsData as $resultElection): ?>

                    <?php
                    $candidates = $resultElection['candidates'];

                    usort($candidates, function ($a, $b) {
                        return $b['votes'] <=> $a['votes'];
                    });

                    $highestVotes = count($candidates) > 0
                        ? max(array_column($candidates, 'votes'))
                        : 0;

                    $leaders = array_filter(
                        $candidates,
                        function ($candidate) use ($highestVotes) {
                            return $highestVotes > 0 &&
                                   $candidate['votes'] === $highestVotes;
                        }
                    );

                    $leaderCount = count($leaders);
                    ?>

                    <article class="result-card">

                        <div class="result-header">
                            <?php if ($resultElection['status'] === 'completed'): ?>
                                <span class="status completed">Final Results</span>
                            <?php else: ?>
                                <span class="status">Current Standing</span>
                            <?php endif; ?>

                            <h3><?= h($resultElection['title']) ?></h3>

                            <p>
                                <?= h($resultElection['category'] ?: 'General Election') ?>
                                · <?= (int)$resultElection['total_votes'] ?> total votes
                            </p>
                        </div>

                        <div class="result-body">

                            <?php foreach ($candidates as $candidate): ?>

                                <?php
                                $percentage = $resultElection['total_votes'] > 0
                                    ? ($candidate['votes'] / $resultElection['total_votes']) * 100
                                    : 0;

                                $isLeader = (
                                    $highestVotes > 0 &&
                                    $candidate['votes'] === $highestVotes
                                );
                                ?>

                                <div class="candidate-result">
                                    <div class="candidate-line">
                                        <div class="candidate-info">
                                            <strong>
                                                <?= h($candidate['name']) ?>

                                                <?php if ($isLeader): ?>
                                                    <span class="leader-text">
                                                        <?= $resultElection['status'] === 'completed'
                                                            ? ' · Winner'
                                                            : ' · Leading' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </strong>

                                            <span>
                                                <?= number_format($percentage, 2) ?>% of votes
                                            </span>
                                        </div>

                                        <span class="vote-total">
                                            <?= (int)$candidate['votes'] ?>
                                            <?= (int)$candidate['votes'] === 1
                                                ? 'vote'
                                                : 'votes' ?>
                                        </span>
                                    </div>

                                    <div class="progress-track">
                                        <div
                                            class="progress-fill"
                                            style="width:<?= min(100, max(0, $percentage)) ?>%;"
                                        ></div>
                                    </div>
                                </div>

                            <?php endforeach; ?>

                        </div>

                        <div class="result-footer">
                            <?php if ($resultElection['total_votes'] === 0): ?>
                                No votes have been recorded for this election yet.
                            <?php elseif ($resultElection['status'] === 'completed'): ?>
                                <?php if ($leaderCount > 1): ?>
                                    The election ended with a tie among the highest-vote candidates.
                                <?php else: ?>
                                    Final results are available for this completed election.
                                <?php endif; ?>
                            <?php else: ?>
                                These are current standings. The leader may change before voting closes.
                            <?php endif; ?>
                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty">
                <div class="empty-icon">📊</div>
                <h3>Results Will Appear Here</h3>
                <p>
                    There are no active or completed elections with approved
                    candidates to display at the moment.
                </p>
            </div>

        <?php endif; ?>
    </section>

    <!-- Voting History -->
    <section class="section" id="history">
        <div class="section-heading">
            <div>
                <h2>My Voting History</h2>
                <p>Review your latest recorded votes.</p>
            </div>
        </div>

        <div class="panel">

            <?php if (count($history) > 0): ?>

                <div class="table-wrap">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Election</th>
                                <th>Category</th>
                                <th>Vote Recorded</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach ($history as $item): ?>
                                <tr>
                                    <td>
                                        <strong><?= h($item['title']) ?></strong>

                                        <div class="muted">
                                            Voted for:
                                            <?= h($item['candidate_name'] ?: 'Candidate record unavailable') ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= h($item['category'] ?: 'General') ?>
                                    </td>

                                    <td>
                                        <?= h(formatDate($item['voted_at'])) ?>
                                    </td>

                                    <td>
                                        <span class="status approved">
                                            ✓ Vote Recorded
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php else: ?>

                <div class="empty">
                    <div class="empty-icon">📋</div>
                    <h3>No Voting History Yet</h3>
                    <p>
                        Your submitted votes will appear here after you vote
                        in an election.
                    </p>
                </div>

            <?php endif; ?>

        </div>
    </section>

    <!-- Quick Actions -->
    <section class="section">
        <div class="section-heading">
            <div>
                <h2>Quick Actions</h2>
                <p>Jump directly to the section you need.</p>
            </div>
        </div>

        <div class="quick-grid">

            <a href="#elections" class="quick-card">
                <div class="quick-icon">🗳️</div>
                <div>
                    <strong>Browse Elections</strong>
                    <span>Explore active elections and available candidates.</span>
                </div>
            </a>

            <a href="#history" class="quick-card">
                <div class="quick-icon">📋</div>
                <div>
                    <strong>Voting History</strong>
                    <span>Review your recently submitted votes.</span>
                </div>
            </a>

            <a href="#results" class="quick-card">
                <div class="quick-icon">📊</div>
                <div>
                    <strong>View Results</strong>
                    <span>Check election standings and final results.</span>
                </div>
            </a>

            <a href="../logout.php" class="quick-card">
                <div class="quick-icon">↪</div>
                <div>
                    <strong>Logout</strong>
                    <span>Securely sign out of your voter account.</span>
                </div>
            </a>

        </div>
    </section>

</main>

<footer class="footer">
    © <?= date('Y') ?> VotePortal | Online Voting System
</footer>

</body>
</html>
