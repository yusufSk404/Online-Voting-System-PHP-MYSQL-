
<?php
session_start();
require_once '../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'candidate'
) {
    header('Location: ../login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function profileUrl($path) {
    $prefix = 'uploads/profiles/';

    if (!is_string($path) || strpos($path, $prefix) !== 0) {
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

$electionId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);

if (!$electionId) {
    $electionId = filter_input(INPUT_POST, 'election_id', FILTER_VALIDATE_INT);
}

if (!$electionId || $electionId < 1) {
    header('Location: dashboard.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$messageType = 'error';
$election = null;
$candidates = [];
$alreadyVoted = false;
$isOpen = false;

try {
    // Fetch election
    $stmt = $conn->prepare(
        "SELECT id, title, category, description, start_date, end_date, status
         FROM elections
         WHERE id = ?"
    );

    $stmt->bind_param('i', $electionId);
    $stmt->execute();
    $election = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$election) {
        exit('Election not found.');
    }

    $startTime = strtotime($election['start_date']);
    $endTime = strtotime($election['end_date']);
    $now = time();

    $isOpen = (
        $election['status'] === 'active' &&
        $startTime !== false &&
        $endTime !== false &&
        $now >= $startTime &&
        $now <= $endTime
    );

    // Submit vote
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        $selectedCandidate = filter_input(
            INPUT_POST,
            'candidate_id',
            FILTER_VALIDATE_INT
        );

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_token'], $token)
        ) {
            throw new Exception('Security check failed. Refresh the page and try again.');
        }

        if (!$isOpen) {
            throw new Exception('This election is not currently open for voting.');
        }

        if (!$selectedCandidate || $selectedCandidate < 1) {
            throw new Exception('Please select a candidate.');
        }

        /*
         * IMPORTANT:
         * candidate_id in votes is assumed to reference candidates.id.
         * The form therefore submits c.id, not c.user_id.
         */
        $stmt = $conn->prepare(
            "SELECT id
             FROM candidates
             WHERE id = ?
               AND election_id = ?
               AND status = 'approved'
             LIMIT 1"
        );

        $stmt->bind_param('ii', $selectedCandidate, $electionId);
        $stmt->execute();
        $validCandidate = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$validCandidate) {
            throw new Exception('Selected candidate is not approved for this election.');
        }

        // Check whether this user has already voted in this election.
        $stmt = $conn->prepare(
            "SELECT id
             FROM votes
             WHERE voter_id = ? AND election_id = ?
             LIMIT 1"
        );

        $stmt->bind_param('ii', $userId, $electionId);
        $stmt->execute();
        $alreadyVoted = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($alreadyVoted) {
            throw new Exception('You have already voted in this election.');
        }

        /*
         * Insert the candidates table ID into votes.candidate_id.
         * The database unique index on (voter_id, election_id)
         * prevents duplicate votes in the same election.
         */
        $stmt = $conn->prepare(
            "INSERT INTO votes (voter_id, election_id, candidate_id)
             VALUES (?, ?, ?)"
        );

        $stmt->bind_param(
            'iii',
            $userId,
            $electionId,
            $selectedCandidate
        );

        $stmt->execute();
        $stmt->close();

        header(
            'Location: vote.php?election_id=' . $electionId . '&voted=1'
        );
        exit;
    }

} catch (mysqli_sql_exception $ex) {
    // Keep technical details in the PHP error log, not on the page.
    error_log(
        'VotePortal vote database error [' . $ex->getCode() . ']: ' .
        $ex->getMessage()
    );

    if ((int) $ex->getCode() === 1062) {
        $message = 'You have already voted in this election.';
    } else {
        $message = 'Unable to save your vote. Check the PHP error log for the database error.';
    }

} catch (Exception $ex) {
    $message = $ex->getMessage();
}

// Confirmation after successful redirect
if (isset($_GET['voted']) && $_GET['voted'] === '1') {
    $message = 'Your vote has been submitted successfully!';
    $messageType = 'success';
}

// Fetch voting status and approved candidates
if ($election) {
    try {
        $stmt = $conn->prepare(
            "SELECT id
             FROM votes
             WHERE voter_id = ? AND election_id = ?
             LIMIT 1"
        );

        $stmt->bind_param('ii', $userId, $electionId);
        $stmt->execute();
        $alreadyVoted = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $conn->prepare(
            "SELECT
                c.id AS candidate_record_id,
                c.user_id AS candidate_user_id,
                c.manifesto,
                u.name,
                u.profile_image
             FROM candidates c
             INNER JOIN users u ON u.id = c.user_id
             WHERE c.election_id = ?
               AND c.status = 'approved'
             ORDER BY u.name ASC"
        );

        $stmt->bind_param('i', $electionId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $candidates[] = $row;
        }

        $stmt->close();

    } catch (mysqli_sql_exception $ex) {
        error_log(
            'VotePortal candidate list database error [' .
            $ex->getCode() . ']: ' . $ex->getMessage()
        );

        if ($message === '') {
            $message = 'Unable to load voting information. Check the PHP error log.';
            $messageType = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cast Your Vote | VotePortal</title>

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
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    background: #FFF9F3;
    border-bottom: 1px solid var(--border);
}

.logo {
    color: var(--primary);
    font-size: 23px;
    font-weight: 850;
    text-decoration: none;
}

.logo span {
    color: #C2410C;
}

.navbar a.back {
    color: var(--primary);
    font-size: 13px;
    font-weight: 750;
    text-decoration: none;
}

.container {
    max-width: 1100px;
    width: calc(100% - 32px);
    margin: 32px auto 45px;
}

.header {
    margin-bottom: 22px;
}

.header small {
    color: #C2410C;
    font-weight: 850;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    font-size: 11px;
}

.header h1 {
    font-size: 30px;
    margin: 5px 0;
}

.header p {
    color: var(--muted);
    font-size: 14px;
}

.election-card {
    background: var(--panel);
    border: 1px solid var(--border);
    padding: 23px;
    border-radius: 14px;
    margin-bottom: 22px;
}

.election-card h2 {
    font-size: 21px;
    margin-bottom: 5px;
}

.election-card p {
    color: #6B5142;
    font-size: 13px;
    margin-top: 6px;
    overflow-wrap: anywhere;
}

.message {
    padding: 13px 16px;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 13px;
    overflow-wrap: anywhere;
}

.message.success {
    background: #DCEBD6;
    border: 1px solid #B8D6AE;
    color: #245B26;
}

.message.error {
    background: #F8DAD4;
    border: 1px solid #EAB9AD;
    color: #991B1B;
}

.info {
    padding: 14px 16px;
    border-radius: 9px;
    background: #F5D9C0;
    border: 1px solid #E8BFA0;
    color: #71300F;
    font-size: 13px;
    margin-bottom: 22px;
}

.section-title {
    font-size: 19px;
    margin-bottom: 14px;
}

.candidate-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
}

.candidate-card {
    background: #FFF9F3;
    border: 1px solid var(--border);
    border-radius: 13px;
    padding: 20px;
    display: flex;
    align-items: flex-start;
    gap: 15px;
    transition: border-color .15s ease, background .15s ease;
    cursor: pointer;
}

.candidate-card:has(input:checked) {
    border: 2px solid var(--primary);
    background: #FCEBDD;
}

.candidate-card input {
    width: 18px;
    height: 18px;
    margin-top: 6px;
    accent-color: var(--primary);
    flex-shrink: 0;
}

.avatar {
    width: 74px;
    height: 74px;
    border-radius: 50%;
    flex-shrink: 0;
    overflow: hidden;
    background: var(--primary);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 25px;
    font-weight: 800;
    border: 3px solid white;
}

.avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.candidate-info {
    flex: 1;
    min-width: 0;
}

.candidate-info h3 {
    font-size: 16px;
    overflow-wrap: anywhere;
}

.candidate-info p {
    font-size: 12px;
    color: var(--muted);
    margin-top: 7px;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.self-label {
    display: inline-block;
    margin-top: 7px;
    padding: 3px 8px;
    background: #F5D5B9;
    color: #71300F;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 850;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-height: 43px;
    padding: 11px 18px;
    background: var(--primary);
    color: white;
    border: 1px solid var(--primary);
    border-radius: 8px;
    text-decoration: none;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
}

.btn:hover {
    background: var(--dark);
}

.btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.actions {
    margin-top: 22px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.btn-light {
    background: #F5D9C0;
    color: #9A3412;
    border-color: #E8BFA0;
}

.btn-light:hover {
    background: #EFCBAA;
}

.empty {
    padding: 35px;
    text-align: center;
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 13px;
    color: var(--muted);
}

footer {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 12px;
}

@media (max-width: 700px) {
    .candidate-grid {
        grid-template-columns: 1fr;
    }

    .header h1 {
        font-size: 25px;
    }

    .navbar {
        padding: 14px 18px;
    }

    .candidate-card {
        padding: 15px;
    }

    .avatar {
        width: 62px;
        height: 62px;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a href="dashboard.php" class="logo">Vote<span>Portal</span></a>
    <a href="dashboard.php" class="back">← Candidate Dashboard</a>
</header>

<main class="container">

    <section class="header">
        <small>Candidate Portal / Election</small>
        <h1>Cast Your Vote</h1>
        <p>Review the approved candidates and select one candidate to vote for.</p>
    </section>

    <?php if ($message !== ''): ?>
        <div class="message <?= e($messageType) ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($election): ?>

        <section class="election-card">
            <h2><?= e($election['title']) ?></h2>

            <?php if (!empty($election['category'])): ?>
                <p><strong>Category:</strong> <?= e($election['category']) ?></p>
            <?php endif; ?>

            <?php if (!empty($election['description'])): ?>
                <p><?= e($election['description']) ?></p>
            <?php endif; ?>

            <p>
                <strong>Voting period:</strong>
                <?= e(date('d M Y, h:i A', strtotime($election['start_date']))) ?>
                to
                <?= e(date('d M Y, h:i A', strtotime($election['end_date']))) ?>
            </p>
        </section>

        <?php if ($alreadyVoted): ?>

            <div class="info">
                You have already submitted your vote for this election.
                Your vote cannot be submitted again.
            </div>

        <?php elseif (!$isOpen): ?>

            <div class="info">
                This election is not currently open for voting.
                Please check its start date, end date and status.
            </div>

        <?php elseif (empty($candidates)): ?>

            <div class="empty">
                <h3>No approved candidates available</h3>
                <p>Voting will be available when candidates are approved.</p>
            </div>

        <?php else: ?>

            <h2 class="section-title">Select a Candidate</h2>

            <div class="info">
                You can vote for any approved candidate, including yourself
                if you are contesting this election.
                You can submit only one vote in this election.
            </div>

            <form method="POST" id="voteForm">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="election_id"
                       value="<?= (int)$electionId ?>">

                <div class="candidate-grid">

                    <?php foreach ($candidates as $candidate): ?>
                        <?php
                            $candidatePhoto = profileUrl(
                                $candidate['profile_image'] ?? ''
                            );

                            $isSelf =
                                (int)$candidate['candidate_user_id'] === $userId;

                            $initial = strtoupper(
                                substr(trim($candidate['name'] ?? 'C'), 0, 1)
                            );

                            if ($initial === '') {
                                $initial = 'C';
                            }
                        ?>

                        <label class="candidate-card">
                            <input
                                type="radio"
                                name="candidate_id"
                                value="<?= (int)$candidate['candidate_record_id'] ?>"
                                required
                            >

                            <div class="avatar">
                                <?php if ($candidatePhoto !== ''): ?>
                                    <img src="<?= e($candidatePhoto) ?>"
                                         alt="Candidate photo">
                                <?php else: ?>
                                    <?= e($initial) ?>
                                <?php endif; ?>
                            </div>

                            <div class="candidate-info">
                                <h3><?= e($candidate['name']) ?></h3>

                                <?php if ($isSelf): ?>
                                    <span class="self-label">Your own candidature</span>
                                <?php endif; ?>

                                <p><?= e(
                                    trim((string)$candidate['manifesto']) !== ''
                                        ? $candidate['manifesto']
                                        : 'No manifesto provided.'
                                ) ?></p>
                            </div>
                        </label>
                    <?php endforeach; ?>

                </div>

                <div class="actions">
                    <button type="submit" class="btn" id="submitVote">
                        Submit Vote
                    </button>

                    <a href="dashboard.php" class="btn btn-light">Cancel</a>
                </div>
            </form>

        <?php endif; ?>

        <div class="actions">
            <a href="dashboard.php" class="btn btn-light">
                ← Back to Dashboard
            </a>
        </div>

    <?php endif; ?>

</main>

<footer>© <?= date('Y') ?> VotePortal · Online Voting System</footer>

<script>
document.getElementById('voteForm')?.addEventListener('submit', function(event) {
    const selected = document.querySelector('input[name="candidate_id"]:checked');

    if (!selected) {
        event.preventDefault();
        alert('Please select a candidate first.');
        return;
    }

    if (!confirm('Are you sure you want to submit your vote? You cannot vote again in this election.')) {
        event.preventDefault();
    }
});
</script>

</body>
</html>
