
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

$userId = (int)$_SESSION['user_id'];

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function formatDate($value) {
    if (empty($value)) {
        return 'Not specified';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('d M Y, h:i A', $timestamp) : 'Not specified';
}

if (empty($_SESSION['vote_csrf'])) {
    $_SESSION['vote_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['vote_csrf'];

$electionId = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);

if (!$electionId && isset($_POST['election_id'])) {
    $electionId = filter_var(
        $_POST['election_id'],
        FILTER_VALIDATE_INT
    );
}

$electionId = (int)$electionId;

if ($electionId <= 0) {
    header("Location: dashboard.php");
    exit;
}

$error = '';
$success = '';
$alreadyVoted = false;
$election = null;
$candidates = [];
$selectedCandidateId = 0;

/* Get election */
$stmt = $conn->prepare(
    "SELECT id, title, description, category, instructions,
            start_date, end_date, status
     FROM elections
     WHERE id = ?
     LIMIT 1"
);

if (!$stmt) {
    die("Unable to load election. Please check the database configuration.");
}

$stmt->bind_param("i", $electionId);
$stmt->execute();
$electionResult = $stmt->get_result();
$election = $electionResult->fetch_assoc();
$stmt->close();

if (!$election) {
    http_response_code(404);
    die(
        '<p style="font-family:Arial;padding:30px;">'
        . 'Election not found. <a href="dashboard.php">Return to dashboard</a>'
        . '</p>'
    );
}

/* Check whether this voter has already voted */
function hasVoted($conn, $userId, $electionId) {
    $stmt = $conn->prepare(
        "SELECT id
         FROM votes
         WHERE voter_id = ? AND election_id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("ii", $userId, $electionId);
    $stmt->execute();
    $stmt->store_result();

    $found = $stmt->num_rows > 0;
    $stmt->close();

    return $found;
}

$alreadyVoted = hasVoted($conn, $userId, $electionId);

$now = time();
$startTime = strtotime($election['start_date']);
$endTime = strtotime($election['end_date']);

$votingOpen = (
    $election['status'] === 'active' &&
    $startTime !== false &&
    $endTime !== false &&
    $now >= $startTime &&
    $now <= $endTime
);

/* Submit vote */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_vote'])) {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($postedToken) ||
        !hash_equals($_SESSION['vote_csrf'] ?? '', $postedToken)
    ) {
        $error = "Your session has expired. Please refresh the page and try again.";
    } elseif (!$votingOpen) {
        $error = "Voting is not currently open for this election.";
    } elseif ($alreadyVoted) {
        $error = "You have already voted in this election. You cannot vote again.";
    } else {
        $selectedCandidateId = filter_var(
            $_POST['candidate_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if (!$selectedCandidateId || $selectedCandidateId <= 0) {
            $error = "Please select a candidate before submitting your vote.";
        } else {
            /*
             * Validate the selected candidate.
             * votes.candidate_id stores candidates.id in this project.
             */
            $candidateCheck = $conn->prepare(
                "SELECT id
                 FROM candidates
                 WHERE id = ?
                   AND election_id = ?
                   AND status = 'approved'
                 LIMIT 1"
            );

            if (!$candidateCheck) {
                $error = "Unable to validate the candidate. Please try again.";
            } else {
                $candidateCheck->bind_param(
                    "ii",
                    $selectedCandidateId,
                    $electionId
                );

                $candidateCheck->execute();
                $candidateCheck->store_result();

                $validCandidate = $candidateCheck->num_rows === 1;
                $candidateCheck->close();

                if (!$validCandidate) {
                    $error = "The selected candidate is not available for this election.";
                } else {
                    /*
                     * Re-check inside a transaction before inserting.
                     * A UNIQUE(voter_id, election_id) index is also
                     * recommended to prevent simultaneous duplicate votes.
                     */
                    try {
                        $conn->begin_transaction();

                        $duplicateCheck = $conn->prepare(
                            "SELECT id
                             FROM votes
                             WHERE voter_id = ?
                               AND election_id = ?
                             LIMIT 1"
                        );

                        if (!$duplicateCheck) {
                            throw new Exception("Could not verify existing vote.");
                        }

                        $duplicateCheck->bind_param(
                            "ii",
                            $userId,
                            $electionId
                        );

                        $duplicateCheck->execute();
                        $duplicateCheck->store_result();

                        $duplicateExists = $duplicateCheck->num_rows > 0;
                        $duplicateCheck->close();

                        if ($duplicateExists) {
                            $conn->rollback();
                            $alreadyVoted = true;
                            $error = "You have already voted in this election.";
                        } else {
                            // Re-check that the election is still open.
                            $freshCheck = $conn->prepare(
                                "SELECT id
                                 FROM elections
                                 WHERE id = ?
                                   AND status = 'active'
                                   AND start_date <= NOW()
                                   AND end_date >= NOW()
                                 LIMIT 1"
                            );

                            if (!$freshCheck) {
                                throw new Exception("Could not verify election status.");
                            }

                            $freshCheck->bind_param("i", $electionId);
                            $freshCheck->execute();
                            $freshCheck->store_result();

                            $stillOpen = $freshCheck->num_rows === 1;
                            $freshCheck->close();

                            if (!$stillOpen) {
                                $conn->rollback();
                                $error = "Voting has closed for this election.";
                            } else {
                                $insert = $conn->prepare(
                                    "INSERT INTO votes
                                        (voter_id, election_id, candidate_id, voted_at)
                                     VALUES (?, ?, ?, NOW())"
                                );

                                if (!$insert) {
                                    throw new Exception("Could not prepare vote submission.");
                                }

                                $insert->bind_param(
                                    "iii",
                                    $userId,
                                    $electionId,
                                    $selectedCandidateId
                                );

                                if (!$insert->execute()) {
                                    $dbError = $insert->errno;
                                    $insert->close();

                                    if ($dbError === 1062) {
                                        $conn->rollback();
                                        $alreadyVoted = true;
                                        $error = "A vote has already been recorded for you in this election.";
                                    } else {
                                        throw new Exception("Vote could not be saved.");
                                    }
                                } else {
                                    $insert->close();
                                    $conn->commit();

                                    $alreadyVoted = true;
                                    $success = "Your vote has been submitted successfully!";
                                    $_SESSION['vote_csrf'] = bin2hex(random_bytes(32));
                                    $csrfToken = $_SESSION['vote_csrf'];
                                }
                            }
                        }
                    } catch (Throwable $ex) {
                        try {
                            $conn->rollback();
                        } catch (Throwable $ignored) {
                            // Transaction may already have ended.
                        }

                        error_log("VotePortal vote submission error: " . $ex->getMessage());

                        if ($error === '') {
                            $error = "Something went wrong while submitting your vote. Please try again.";
                        }
                    }
                }
            }
        }
    }
}

/* Load approved candidates */
$stmt = $conn->prepare(
    "SELECT
        c.id AS candidate_id,
        c.manifesto,
        u.name,
        u.email,
        u.profile_image
     FROM candidates c
     INNER JOIN users u ON u.id = c.user_id
     WHERE c.election_id = ?
       AND c.status = 'approved'
     ORDER BY u.name ASC"
);

if ($stmt) {
    $stmt->bind_param("i", $electionId);
    $stmt->execute();
    $candidateResult = $stmt->get_result();

    while ($row = $candidateResult->fetch_assoc()) {
        $candidates[] = $row;
    }

    $stmt->close();
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
    --primary: #C2410C;
    --dark: #9A3412;
    --accent: #EA580C;
    --bg: #FFF7ED;
    --border: #FED7AA;
    --text: #292524;
    --muted: #78716C;
    --soft: #FFEDD5;
    --green: #166534;
    --green-bg: #DCFCE7;
    --red: #991B1B;
    --red-bg: #FEE2E2;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
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
    gap: 15px;
    border-bottom: 1px solid var(--border);
}

.logo {
    color: var(--dark);
    font-size: 25px;
    font-weight: bold;
}

.logo span {
    color: var(--accent);
}

.back-link {
    color: var(--primary);
    font-size: 13px;
    font-weight: bold;
}

.container {
    max-width: 1100px;
    margin: 32px auto;
    padding: 0 20px;
}

.page-header {
    background: linear-gradient(120deg, white, #FFF0DF);
    border: 1px solid var(--border);
    border-radius: 17px;
    padding: 30px;
    margin-bottom: 24px;
}

.eyebrow {
    color: var(--primary);
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1.3px;
    margin-bottom: 12px;
}

.page-header h1 {
    font-size: 30px;
    margin-bottom: 10px;
    overflow-wrap: anywhere;
}

.page-header p {
    color: var(--muted);
    font-size: 14px;
    line-height: 1.8;
}

.meta {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 18px;
}

.meta span {
    background: white;
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 8px 12px;
    color: var(--dark);
    font-size: 12px;
    font-weight: bold;
}

.alert {
    padding: 15px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.7;
}

.alert.error {
    color: var(--red);
    background: var(--red-bg);
    border: 1px solid #FECACA;
}

.alert.success {
    color: var(--green);
    background: var(--green-bg);
    border: 1px solid #BBF7D0;
}

.section-heading {
    margin: 25px 0 17px;
}

.section-heading h2 {
    font-size: 23px;
    margin-bottom: 7px;
}

.section-heading p {
    font-size: 13px;
    color: var(--muted);
    line-height: 1.7;
}

.candidate-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.candidate-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 22px;
    display: flex;
    flex-direction: column;
    transition: .2s;
}

.candidate-card:has(input:checked) {
    border: 2px solid var(--accent);
    background: #FFFCF8;
    box-shadow: 0 5px 20px rgba(194,65,12,.08);
}

.candidate-card:hover {
    box-shadow: 0 8px 22px rgba(154,52,18,.07);
}

.candidate-select {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    cursor: pointer;
    flex: 1;
}

.candidate-select input {
    width: 18px;
    height: 18px;
    margin-top: 7px;
    accent-color: var(--primary);
    flex-shrink: 0;
}

.candidate-photo {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    border: 2px solid var(--border);
    object-fit: cover;
    background: var(--soft);
    flex-shrink: 0;
}

.avatar-placeholder {
    width: 68px;
    height: 68px;
    border-radius: 50%;
    background: var(--soft);
    border: 2px solid var(--border);
    color: var(--primary);
    display: grid;
    place-items: center;
    font-size: 25px;
    font-weight: bold;
    flex-shrink: 0;
}

.candidate-info {
    min-width: 0;
}

.candidate-info h3 {
    font-size: 17px;
    margin: 3px 0 6px;
    overflow-wrap: anywhere;
}

.candidate-email {
    color: var(--muted);
    font-size: 11px;
    overflow-wrap: anywhere;
}

.manifesto {
    margin-top: 18px;
    padding-top: 15px;
    border-top: 1px solid #F5E9DE;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.manifesto strong {
    color: var(--text);
    display: block;
    margin-bottom: 5px;
}

.vote-panel {
    background: white;
    border: 1px solid var(--border);
    border-radius: 15px;
    padding: 24px;
    margin-top: 25px;
}

.vote-panel h3 {
    font-size: 18px;
    margin-bottom: 10px;
}

.vote-panel p {
    color: var(--muted);
    font-size: 13px;
    line-height: 1.8;
    margin-bottom: 18px;
}

.confirm-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.7;
    cursor: pointer;
}

.confirm-row input {
    width: 17px;
    height: 17px;
    margin-top: 2px;
    accent-color: var(--primary);
    flex-shrink: 0;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--primary);
    background: var(--primary);
    color: white;
    padding: 13px 19px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
}

.btn:hover {
    background: var(--dark);
}

.btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.btn-light {
    background: white;
    color: var(--primary);
    border-color: var(--border);
}

.btn-light:hover {
    background: var(--soft);
}

.actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.empty {
    background: white;
    border: 1px dashed #FDBA74;
    border-radius: 14px;
    padding: 35px 20px;
    text-align: center;
    color: var(--muted);
    line-height: 1.8;
}

.empty h3 {
    color: var(--text);
    margin-bottom: 8px;
}

.footer {
    text-align: center;
    padding: 25px;
    color: var(--muted);
    font-size: 12px;
    margin-top: 35px;
}

@media (max-width: 650px) {
    .navbar {
        padding: 16px 5%;
    }

    .logo {
        font-size: 22px;
    }

    .container {
        padding: 0 14px;
        margin-top: 20px;
    }

    .page-header {
        padding: 23px 19px;
    }

    .page-header h1 {
        font-size: 25px;
    }

    .candidate-grid {
        grid-template-columns: 1fr;
    }

    .candidate-card {
        padding: 18px;
    }

    .vote-panel {
        padding: 20px;
    }

    .actions .btn {
        width: 100%;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a href="dashboard.php" class="logo">Vote<span>Portal</span></a>
    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
</header>

<main class="container">

    <section class="page-header">
        <div class="eyebrow">YOUR VOICE MATTERS</div>

        <h1><?= h($election['title']) ?></h1>

        <p>
            <?= h($election['description'] ?: 'Review the approved candidates and select your preferred candidate.') ?>
        </p>

        <div class="meta">
            <span>Category: <?= h($election['category'] ?: 'General Election') ?></span>
            <span>Starts: <?= h(formatDate($election['start_date'])) ?></span>
            <span>Ends: <?= h(formatDate($election['end_date'])) ?></span>
        </div>
    </section>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert success">
            <strong>✓ Vote submitted successfully!</strong><br>
            Your vote has been recorded. You cannot vote again in this election.
        </div>
    <?php endif; ?>

    <?php if ($alreadyVoted): ?>

        <section class="empty">
            <h3>✓ Your Vote Has Been Recorded</h3>
            <p>
                You have already submitted your vote for this election.
                The system does not allow another vote in the same election.
            </p>
            <br>
            <a href="dashboard.php" class="btn">Return to Dashboard →</a>
        </section>

    <?php elseif (!$votingOpen): ?>

        <section class="empty">
            <h3>Voting Is Not Currently Open</h3>
            <p>
                This election is not within its active voting period.
                Please check the start date, end date and election status.
            </p>
            <br>
            <a href="dashboard.php" class="btn">Return to Dashboard →</a>
        </section>

    <?php elseif (count($candidates) === 0): ?>

        <section class="empty">
            <h3>No Approved Candidates</h3>
            <p>
                No candidates have been approved for this election yet.
                Please check again later.
            </p>
            <br>
            <a href="dashboard.php" class="btn">Return to Dashboard →</a>
        </section>

    <?php else: ?>

        <section class="section-heading">
            <h2>Select Your Candidate</h2>
            <p>
                Review each candidate's details and select only one candidate.
            </p>
        </section>

        <form method="POST" id="voteForm">
            <input type="hidden" name="election_id" value="<?= $electionId ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="candidate-grid">

                <?php foreach ($candidates as $candidate): ?>
                    <?php
                    $photo = trim((string)($candidate['profile_image'] ?? ''));
                    $initial = strtoupper(substr(
                        (string)($candidate['name'] ?? 'C'),
                        0,
                        1
                    ));
                    ?>

                    <article class="candidate-card">

                        <label class="candidate-select">
                            <input
                                type="radio"
                                name="candidate_id"
                                value="<?= (int)$candidate['candidate_id'] ?>"
                                required
                            >

                            <?php if ($photo !== ''): ?>
                                <img
                                    class="candidate-photo"
                                    src="../<?= h(ltrim($photo, '/')) ?>"
                                    alt="<?= h($candidate['name']) ?>"
                                    onerror="this.style.display='none';this.nextElementSibling.style.display='grid';"
                                >
                                <div class="avatar-placeholder" style="display:none;">
                                    <?= h($initial) ?>
                                </div>
                            <?php else: ?>
                                <div class="avatar-placeholder">
                                    <?= h($initial) ?>
                                </div>
                            <?php endif; ?>

                            <div class="candidate-info">
                                <h3><?= h($candidate['name']) ?></h3>
                                <div class="candidate-email">
                                    <?= h($candidate['email']) ?>
                                </div>
                            </div>
                        </label>

                        <div class="manifesto">
                            <strong>Candidate Manifesto</strong>
                            <?= nl2br(h(
                                $candidate['manifesto']
                                ?: 'No manifesto has been provided.'
                            )) ?>
                        </div>

                    </article>
                <?php endforeach; ?>

            </div>

            <section class="vote-panel">
                <h3>Confirm Your Vote</h3>

                <p>
                    Please review your selection carefully. Once submitted,
                    your vote cannot be changed through this page.
                </p>

                <label class="confirm-row">
                    <input type="checkbox" id="confirmVote" required>
                    <span>
                        I confirm that I have selected my preferred candidate
                        and want to submit this vote.
                    </span>
                </label>

                <div class="actions">
                    <button
                        type="submit"
                        name="submit_vote"
                        class="btn"
                        id="submitButton"
                        disabled
                    >
                        Submit My Vote →
                    </button>

                    <a href="dashboard.php" class="btn btn-light">
                        Cancel
                    </a>
                </div>
            </section>

        </form>

        <script>
        const voteForm = document.getElementById('voteForm');
        const confirmVote = document.getElementById('confirmVote');
        const submitButton = document.getElementById('submitButton');

        function updateSubmitButton() {
            const candidateSelected = document.querySelector(
                'input[name="candidate_id"]:checked'
            );

            submitButton.disabled = !(confirmVote.checked && candidateSelected);
        }

        document.querySelectorAll('input[name="candidate_id"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                document.querySelectorAll('.candidate-card').forEach(function (card) {
                    card.style.borderColor = '';
                });

                const selectedCard = radio.closest('.candidate-card');

                if (selectedCard) {
                    selectedCard.style.borderColor = '#EA580C';
                }

                updateSubmitButton();
            });
        });

        confirmVote.addEventListener('change', updateSubmitButton);

        voteForm.addEventListener('submit', function (event) {
            const candidateSelected = document.querySelector(
                'input[name="candidate_id"]:checked'
            );

            if (!candidateSelected || !confirmVote.checked) {
                event.preventDefault();
                alert('Please select a candidate and confirm your vote.');
                return;
            }

            if (!confirm('Are you sure you want to submit your vote? This action cannot be undone.')) {
                event.preventDefault();
            }
        });
        </script>

    <?php endif; ?>

</main>

<footer class="footer">
    © <?= date('Y') ?> VotePortal | Online Voting System
</footer>

</body>
</html>
