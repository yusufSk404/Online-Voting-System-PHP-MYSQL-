
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

function statusClass($status) {
    switch ($status) {
        case 'active': return 'active';
        case 'upcoming': return 'upcoming';
        case 'completed': return 'completed';
        case 'draft': return 'draft';
        default: return 'unknown';
    }
}

$elections = [];
$electionQuery = $conn->query(
    "SELECT id, title, category, start_date, end_date, status
     FROM elections
     ORDER BY id DESC"
);

if ($electionQuery) {
    while ($row = $electionQuery->fetch_assoc()) {
        $elections[] = $row;
    }
}

$requestedId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);
$selectedId = null;

if ($requestedId && $requestedId > 0) {
    foreach ($elections as $item) {
        if ((int)$item['id'] === (int)$requestedId) {
            $selectedId = (int)$item['id'];
            break;
        }
    }
} elseif (!empty($elections)) {
    $selectedId = (int)$elections[0]['id'];
}

$selectedElection = null;
$results = [];
$totalVotes = 0;
$approvedCandidateCount = 0;
$leaderVotes = 0;
$leaderNames = [];
$winnerMessage = '';
$winnerClass = 'neutral';
$error = '';

if ($selectedId !== null) {
    foreach ($elections as $item) {
        if ((int)$item['id'] === $selectedId) {
            $selectedElection = $item;
            break;
        }
    }

    // Count all recorded votes for the selected election.
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM votes
         WHERE election_id = ?"
    );

    if ($stmt) {
        $stmt->bind_param("i", $selectedId);
        $stmt->execute();
        $totalRow = $stmt->get_result()->fetch_assoc();
        $totalVotes = (int)($totalRow['total'] ?? 0);
        $stmt->close();
    } else {
        $error = 'Unable to count votes.';
        error_log('VotePortal total vote query error: ' . $conn->error);
    }

    /*
     * IMPORTANT:
     * In this database, votes.candidate_id stores candidates.id.
     * Therefore, join votes.candidate_id with candidates.id,
     * NOT candidates.user_id.
     */
    $sql = "
        SELECT
            c.id AS candidate_id,
            c.user_id,
            c.manifesto,
            c.status AS candidate_status,
            u.name AS candidate_name,
            u.email AS candidate_email,
            COUNT(v.id) AS vote_count
        FROM candidates c
        INNER JOIN users u
            ON u.id = c.user_id
        LEFT JOIN votes v
            ON v.candidate_id = c.id
            AND v.election_id = c.election_id
        WHERE c.election_id = ?
          AND c.status = 'approved'
        GROUP BY
            c.id,
            c.user_id,
            c.manifesto,
            c.status,
            u.name,
            u.email
        ORDER BY vote_count DESC, u.name ASC
    ";

    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("i", $selectedId);
        $stmt->execute();
        $data = $stmt->get_result();

        while ($row = $data->fetch_assoc()) {
            $row['vote_count'] = (int)$row['vote_count'];

            $row['percentage'] = $totalVotes > 0
                ? ($row['vote_count'] / $totalVotes) * 100
                : 0;

            $results[] = $row;
        }

        $stmt->close();
    } else {
        $error = 'Unable to load candidate results. Please check the database query.';
        error_log('VotePortal results query error: ' . $conn->error);
    }

    $approvedCandidateCount = count($results);

    if ($totalVotes === 0) {
        $winnerMessage = 'No votes have been cast yet.';
    } elseif ($approvedCandidateCount === 0) {
        $winnerMessage = 'No approved candidates are available for this election.';
    } else {
        $leaderVotes = (int)$results[0]['vote_count'];

        foreach ($results as $candidate) {
            if ((int)$candidate['vote_count'] === $leaderVotes) {
                $leaderNames[] = $candidate['candidate_name'];
            } else {
                break;
            }
        }

        if ($selectedElection['status'] === 'completed') {
            if (count($leaderNames) > 1) {
                $winnerMessage = 'Result: Tie between ' . implode(', ', $leaderNames);
                $winnerClass = 'tie';
            } else {
                $winnerMessage = 'Winner: ' . $leaderNames[0];
                $winnerClass = 'winner';
            }
        } else {
            if (count($leaderNames) > 1) {
                $winnerMessage = 'Current leaders: ' . implode(', ', $leaderNames);
            } else {
                $winnerMessage = 'Current leader: ' . $leaderNames[0];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Election Results | VotePortal</title>

<style>
:root {
    --orange: #C2410C;
    --orange-dark: #9A3412;
    --bright: #EA580C;
    --cream: #FFF7ED;
    --line: #F0DFCF;
    --ink: #292524;
    --muted: #78716C;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: "Segoe UI", Arial, sans-serif;
    background: var(--cream);
    color: var(--ink);
    line-height: 1.5;
}

.navbar {
    min-height: 70px;
    padding: 15px 5%;
    background: white;
    border-bottom: 1px solid var(--line);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
}

.logo {
    font-size: 23px;
    font-weight: 800;
    color: var(--orange-dark);
    text-decoration: none;
}

.logo span {
    color: var(--bright);
}

.nav-links {
    display: flex;
    gap: 17px;
    flex-wrap: wrap;
}

.nav-links a {
    color: var(--orange-dark);
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
}

.nav-links a:hover {
    color: var(--bright);
}

.container {
    max-width: 1250px;
    width: calc(100% - 36px);
    margin: 32px auto 45px;
}

.page-header {
    margin-bottom: 25px;
}

.eyebrow {
    color: var(--orange);
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 1.5px;
    font-weight: 800;
    margin-bottom: 7px;
}

.page-header h1 {
    font-size: clamp(26px, 4vw, 34px);
    margin-bottom: 7px;
}

.page-header p {
    color: var(--muted);
    font-size: 14px;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-height: 42px;
    padding: 10px 15px;
    border: 1px solid var(--orange);
    border-radius: 8px;
    background: var(--orange);
    color: white;
    font-family: inherit;
    font-size: 13px;
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
}

.btn:hover {
    background: var(--orange-dark);
}

.btn-light {
    background: white;
    color: var(--orange-dark);
    border-color: var(--line);
}

.btn-light:hover {
    background: #FFF0E3;
}

.panel {
    background: white;
    border: 1px solid var(--line);
    border-radius: 14px;
    box-shadow: 0 7px 24px rgba(124, 45, 18, .035);
    overflow: hidden;
    margin-bottom: 22px;
}

.panel-header {
    padding: 21px 24px;
    border-bottom: 1px solid #F5E9DE;
}

.panel-header h2 {
    font-size: 18px;
    margin-bottom: 4px;
}

.panel-header p {
    font-size: 12px;
    color: var(--muted);
}

.select-form {
    padding: 22px 24px;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
    align-items: end;
    background: #FFFCF9;
}

.field label {
    display: block;
    font-size: 12px;
    font-weight: 750;
    margin-bottom: 7px;
}

.field select {
    width: 100%;
    min-height: 44px;
    padding: 10px 12px;
    border: 1px solid #E7D5C5;
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    background: white;
    color: var(--ink);
}

.election-info {
    padding: 22px 24px;
}

.info-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    flex-wrap: wrap;
}

.info-top h2 {
    font-size: 22px;
    margin-bottom: 6px;
    overflow-wrap: anywhere;
}

.info-top p {
    color: var(--muted);
    font-size: 12px;
}

.status {
    display: inline-block;
    padding: 7px 11px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 800;
    text-transform: capitalize;
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
    background: #FFEDD5;
    color: #9A3412;
}

.status.unknown {
    background: #F3F4F6;
    color: #374151;
}

.info-meta {
    display: flex;
    gap: 25px;
    flex-wrap: wrap;
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid #F5E9DE;
}

.meta-item {
    font-size: 12px;
    color: var(--muted);
}

.meta-item strong {
    display: block;
    color: var(--ink);
    font-size: 13px;
    margin-top: 4px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 22px;
}

.stat-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 13px;
    padding: 21px;
}

.stat-label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 650;
}

.stat-number {
    margin-top: 10px;
    font-size: 30px;
    font-weight: 850;
    line-height: 1.25;
}

.stat-help {
    color: var(--muted);
    font-size: 11px;
    margin-top: 6px;
}

.result-banner {
    border: 1px solid var(--line);
    border-radius: 12px;
    padding: 19px 21px;
    margin-bottom: 22px;
    background: white;
}

.result-banner h3 {
    font-size: 15px;
    margin-bottom: 5px;
}

.result-banner p {
    font-size: 13px;
    overflow-wrap: anywhere;
}

.result-banner.winner {
    background: #F0FDF4;
    border-color: #BBF7D0;
    color: #166534;
}

.result-banner.tie {
    background: #FFF7ED;
    border-color: #FDBA74;
    color: #9A3412;
}

.result-banner.neutral {
    background: white;
    color: var(--ink);
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 760px;
    border-collapse: collapse;
}

thead {
    background: #FFFAF5;
}

th {
    padding: 14px 17px;
    text-align: left;
    color: #78716C;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 800;
    border-bottom: 1px solid var(--line);
}

td {
    padding: 17px;
    border-bottom: 1px solid #F5EDE5;
    vertical-align: middle;
    font-size: 13px;
}

tbody tr:last-child td {
    border-bottom: none;
}

tbody tr:hover {
    background: #FFFCF9;
}

.rank {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-width: 32px;
    height: 32px;
    border-radius: 9px;
    background: #FFF0E3;
    color: var(--orange-dark);
    font-weight: 850;
}

.candidate-name {
    font-weight: 750;
    overflow-wrap: anywhere;
}

.candidate-email {
    font-size: 11px;
    color: var(--muted);
    margin-top: 4px;
    overflow-wrap: anywhere;
}

.vote-number {
    font-size: 15px;
    font-weight: 850;
}

.progress {
    width: 150px;
    max-width: 100%;
    height: 8px;
    background: #F5E7DA;
    border-radius: 999px;
    overflow: hidden;
    margin-bottom: 6px;
}

.progress-fill {
    height: 100%;
    background: var(--bright);
    border-radius: 999px;
}

.percentage {
    font-size: 12px;
    font-weight: 750;
    color: var(--orange-dark);
}

.table-note {
    padding: 15px 22px;
    color: var(--muted);
    font-size: 11px;
    border-top: 1px solid #F5E9DE;
}

.empty-state {
    text-align: center;
    padding: 42px 20px;
}

.empty-state h3 {
    font-size: 18px;
    margin-bottom: 7px;
}

.empty-state p {
    color: var(--muted);
    font-size: 13px;
}

.alert {
    padding: 13px 16px;
    border-radius: 9px;
    margin-bottom: 20px;
    font-size: 13px;
    background: #FEE2E2;
    border: 1px solid #FECACA;
    color: #991B1B;
}

.footer {
    text-align: center;
    color: var(--muted);
    padding: 25px 15px;
    font-size: 12px;
}

@media (max-width: 700px) {
    .navbar {
        padding: 15px 18px;
        align-items: flex-start;
        flex-direction: column;
    }

    .container {
        width: calc(100% - 24px);
        margin-top: 23px;
    }

    .select-form {
        grid-template-columns: 1fr;
        padding: 17px;
    }

    .select-form .btn {
        width: 100%;
    }

    .panel-header,
    .election-info {
        padding: 18px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
        gap: 11px;
    }

    .stat-card {
        padding: 17px;
    }

    .stat-number {
        font-size: 26px;
    }

    .info-meta {
        gap: 15px;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a href="dashboard.php" class="logo">Vote<span>Portal</span></a>

    <nav class="nav-links">
        <a href="manage_elections.php">Manage Elections</a>
        <a href="manage_candidates.php">Manage Candidates</a>
        <a href="dashboard.php">← Dashboard</a>
    </nav>
</header>

<main class="container">

    <section class="page-header">
        <div class="eyebrow">Admin Workspace / Reports</div>
        <h1>Election Results</h1>
        <p>Review candidate vote counts, percentages and election standings.</p>
    </section>

    <?php if ($error !== ''): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (empty($elections)): ?>

        <section class="panel">
            <div class="empty-state">
                <h3>No elections available</h3>
                <p>Create an election first to view its results.</p>
                <br>
                <a href="create_election.php" class="btn">+ Create Election</a>
            </div>
        </section>

    <?php else: ?>

        <section class="panel">
            <div class="panel-header">
                <h2>Select Election</h2>
                <p>Choose an election to see its results.</p>
            </div>

            <form method="GET" class="select-form">
                <div class="field">
                    <label for="election_id">Election</label>
                    <select name="election_id" id="election_id" required>
                        <?php foreach ($elections as $item): ?>
                            <option
                                value="<?= (int)$item['id'] ?>"
                                <?= (int)$item['id'] === $selectedId ? 'selected' : '' ?>
                            >
                                #<?= (int)$item['id'] ?> —
                                <?= e($item['title']) ?>
                                (<?= e(ucfirst($item['status'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="btn">View Results</button>
            </form>
        </section>

        <?php if ($selectedElection): ?>

            <section class="panel">
                <div class="election-info">
                    <div class="info-top">
                        <div>
                            <h2><?= e($selectedElection['title']) ?></h2>
                            <p>
                                Election #<?= (int)$selectedElection['id'] ?>
                                &nbsp;·&nbsp;
                                <?= e($selectedElection['category'] ?: 'Other') ?>
                            </p>
                        </div>

                        <span class="status <?= e(statusClass($selectedElection['status'])) ?>">
                            <?= e(ucfirst($selectedElection['status'])) ?>
                        </span>
                    </div>

                    <div class="info-meta">
                        <div class="meta-item">
                            Start Date
                            <strong>
                                <?= e(date(
                                    'd M Y, h:i A',
                                    strtotime($selectedElection['start_date'])
                                )) ?>
                            </strong>
                        </div>

                        <div class="meta-item">
                            End Date
                            <strong>
                                <?= e(date(
                                    'd M Y, h:i A',
                                    strtotime($selectedElection['end_date'])
                                )) ?>
                            </strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Votes Cast</div>
                    <div class="stat-number"><?= $totalVotes ?></div>
                    <div class="stat-help">All recorded votes for this election</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Approved Candidates</div>
                    <div class="stat-number"><?= $approvedCandidateCount ?></div>
                    <div class="stat-help">Candidates included in the results</div>
                </div>

                <div class="stat-card">
                    <div class="stat-label">Highest Vote Count</div>
                    <div class="stat-number"><?= $leaderVotes ?></div>
                    <div class="stat-help">Votes received by the current leader(s)</div>
                </div>
            </section>

            <section class="result-banner <?= e($winnerClass) ?>">
                <h3>
                    <?= $selectedElection['status'] === 'completed'
                        ? 'Final Result'
                        : 'Election Standing' ?>
                </h3>

                <p><?= e($winnerMessage) ?></p>

                <?php if ($selectedElection['status'] !== 'completed'): ?>
                    <p style="margin-top:7px;font-size:11px;">
                        This is the current standing, not a final result.
                    </p>
                <?php endif; ?>
            </section>

            <section class="panel">
                <div class="panel-header">
                    <h2>Candidate-wise Results</h2>
                    <p>Vote counts and percentages for approved candidates.</p>
                </div>

                <?php if (!empty($results)): ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Candidate</th>
                                    <th>Votes</th>
                                    <th>Vote Percentage</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                $rank = 0;
                                $previousVotes = null;
                                $position = 0;
                                ?>

                                <?php foreach ($results as $candidate): ?>
                                    <?php
                                    $position++;

                                    if (
                                        $previousVotes === null ||
                                        $candidate['vote_count'] !== $previousVotes
                                    ) {
                                        $rank = $position;
                                    }

                                    $previousVotes = $candidate['vote_count'];
                                    ?>

                                    <tr>
                                        <td>
                                            <span class="rank"><?= $rank ?></span>
                                        </td>

                                        <td>
                                            <div class="candidate-name">
                                                <?= e($candidate['candidate_name']) ?>
                                            </div>
                                            <div class="candidate-email">
                                                <?= e($candidate['candidate_email']) ?>
                                            </div>
                                        </td>

                                        <td>
                                            <span class="vote-number">
                                                <?= (int)$candidate['vote_count'] ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="progress">
                                                <div
                                                    class="progress-fill"
                                                    style="width:<?= min(
                                                        100,
                                                        max(0, $candidate['percentage'])
                                                    ) ?>%;"
                                                ></div>
                                            </div>

                                            <span class="percentage">
                                                <?= number_format(
                                                    $candidate['percentage'],
                                                    2
                                                ) ?>%
                                            </span>
                                        </td>
                                    </tr>

                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="table-note">
                        Percentages use the total recorded votes for this election
                        as the denominator. Candidates are ordered by vote count.
                        Equal vote counts share the same rank.
                    </div>

                <?php else: ?>

                    <div class="empty-state">
                        <h3>No approved candidates</h3>
                        <p>
                            Approve candidates for this election to display
                            candidate-wise results.
                        </p>
                        <br>
                        <a href="manage_candidates.php" class="btn btn-light">
                            Manage Candidates
                        </a>
                    </div>

                <?php endif; ?>
            </section>

            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:20px;">
                <a href="manage_elections.php" class="btn btn-light">
                    ← Back to Elections
                </a>

                <a
                    href="results.php?election_id=<?= (int)$selectedId ?>"
                    class="btn"
                >
                    Refresh Results
                </a>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</main>

<footer class="footer">
    © <?= date('Y') ?> VotePortal · Online Voting System
</footer>

</body>
</html>
