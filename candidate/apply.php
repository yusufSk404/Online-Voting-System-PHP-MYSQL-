
<?php
// Show errors during development so the page does not fail silently.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_OFF);

session_start();
require_once '../config/db.php';

// Check candidate login.
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'candidate'
) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = 'success';

function h($value)
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatDate($date)
{
    if (empty($date)) {
        return 'Not specified';
    }

    return date('d M Y, h:i A', strtotime($date));
}

function statusClass($status)
{
    switch (strtolower((string)$status)) {
        case 'approved':
        case 'active':
            return 'approved';

        case 'rejected':
            return 'rejected';

        case 'upcoming':
            return 'upcoming';

        case 'completed':
            return 'completed';

        default:
            return 'pending';
    }
}

/*
|--------------------------------------------------------------------------
| Submit candidate application
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $message = "Invalid request. Please refresh the page and try again.";
        $message_type = 'error';
    } else {
        $election_id = filter_input(
            INPUT_POST,
            'election_id',
            FILTER_VALIDATE_INT
        );

        $manifesto = trim($_POST['manifesto'] ?? '');

        if (!$election_id || $manifesto === '') {
            $message = "Please select an election and enter your manifesto.";
            $message_type = 'error';
        } elseif (strlen($manifesto) > 5000) {
            $message = "Manifesto must not exceed 5000 characters.";
            $message_type = 'error';
        } else {
            /*
             * Applications are accepted for elections marked
             * upcoming or active, provided the end date has not passed.
             */
            $check = $conn->prepare(
                "SELECT id
                 FROM elections
                 WHERE id = ?
                   AND status IN ('upcoming', 'active')
                   AND end_date >= NOW()
                 LIMIT 1"
            );

            if (!$check) {
                $message = "Unable to check the election. Please verify your elections table.";
                $message_type = 'error';
            } else {
                $check->bind_param("i", $election_id);
                $check->execute();

                $election_result = $check->get_result();
                $election_exists = $election_result->num_rows > 0;

                $check->close();

                if (!$election_exists) {
                    $message = "This election is not currently accepting applications.";
                    $message_type = 'error';
                } else {
                    // Prevent a duplicate application.
                    $duplicate = $conn->prepare(
                        "SELECT id
                         FROM candidates
                         WHERE user_id = ? AND election_id = ?
                         LIMIT 1"
                    );

                    if (!$duplicate) {
                        $message = "Unable to check previous applications. Please verify your candidates table.";
                        $message_type = 'error';
                    } else {
                        $duplicate->bind_param(
                            "ii",
                            $user_id,
                            $election_id
                        );

                        $duplicate->execute();

                        $already_applied =
                            $duplicate->get_result()->num_rows > 0;

                        $duplicate->close();

                        if ($already_applied) {
                            $message = "You have already applied for this election.";
                            $message_type = 'error';
                        } else {
                            $status = 'pending';

                            $insert = $conn->prepare(
                                "INSERT INTO candidates
                                    (user_id, election_id, manifesto, status)
                                 VALUES (?, ?, ?, ?)"
                            );

                            if (!$insert) {
                                $message = "Unable to prepare your application. Please verify the candidates table columns.";
                                $message_type = 'error';
                            } else {
                                $insert->bind_param(
                                    "iiss",
                                    $user_id,
                                    $election_id,
                                    $manifesto,
                                    $status
                                );

                                if ($insert->execute()) {
                                    $message = "Application submitted successfully! Your application is pending admin approval.";
                                    $message_type = 'success';

                                    // Prevent accidental resubmission on refresh.
                                    $_SESSION['csrf_token'] =
                                        bin2hex(random_bytes(32));
                                } else {
                                    if ($insert->errno === 1062) {
                                        $message = "You have already applied for this election.";
                                    } else {
                                        $message = "Application failed: " .
                                            $insert->error;
                                    }

                                    $message_type = 'error';
                                }

                                $insert->close();
                            }
                        }
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch elections
|--------------------------------------------------------------------------
*/

$available_elections = $conn->query(
    "SELECT id, title, category, description,
            start_date, end_date, status
     FROM elections
     WHERE status IN ('upcoming', 'active')
       AND end_date >= NOW()
     ORDER BY start_date ASC"
);

$election_error = '';

if (!$available_elections) {
    $election_error = $conn->error;
}

/*
|--------------------------------------------------------------------------
| Fetch candidate's applications
|--------------------------------------------------------------------------
*/

$applications_result = false;
$applications_error = '';

$applications_stmt = $conn->prepare(
    "SELECT
        c.id AS application_id,
        c.election_id,
        c.manifesto,
        c.status AS application_status,
        c.applied_at,
        e.title AS election_title,
        e.category,
        e.start_date,
        e.end_date,
        e.status AS election_status
     FROM candidates c
     INNER JOIN elections e ON e.id = c.election_id
     WHERE c.user_id = ?
     ORDER BY c.applied_at DESC"
);

if ($applications_stmt) {
    $applications_stmt->bind_param("i", $user_id);
    $applications_stmt->execute();

    $applications_result = $applications_stmt->get_result();
} else {
    $applications_error = $conn->error;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Apply for Election | VotePortal</title>

    <style>
        :root {
            --page: #FFF8F1;
            --panel: #FFF1E5;
            --panel-alt: #FCE8D8;
            --input-bg: #FFFAF6;
            --border: #F1D7C3;
            --text: #44291E;
            --muted: #896D5D;
            --primary: #9A3412;
            --primary-dark: #7C2D12;
            --success-bg: #E8F5E9;
            --success-text: #246B35;
            --error-bg: #FDECEC;
            --error-text: #A12626;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--page);
            color: var(--text);
            line-height: 1.6;
        }

        .navbar {
            background: var(--panel);
            border-bottom: 1px solid var(--border);
            padding: 17px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .brand {
            color: var(--primary);
            font-size: 25px;
            font-weight: 800;
            text-decoration: none;
        }

        .nav-links {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text);
            font-weight: 600;
            font-size: 14px;
        }

        .nav-links a:hover {
            color: var(--primary);
        }

        .container {
            width: min(1120px, 92%);
            margin: 32px auto 55px;
        }

        .page-heading {
            margin-bottom: 24px;
        }

        .page-heading h1 {
            font-size: clamp(26px, 4vw, 35px);
            margin-bottom: 5px;
        }

        .page-heading p,
        .muted {
            color: var(--muted);
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 26px;
            box-shadow: 0 5px 18px rgba(110, 54, 25, 0.04);
        }

        .panel h2 {
            font-size: 22px;
            margin-bottom: 7px;
        }

        .section-intro {
            color: var(--muted);
            margin-bottom: 22px;
            font-size: 14px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: 10px;
            margin-bottom: 22px;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        .alert.success {
            background: var(--success-bg);
            color: var(--success-text);
        }

        .alert.error {
            background: var(--error-bg);
            color: var(--error-text);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            margin-bottom: 19px;
        }

        .form-group.full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 14px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--input-bg);
            color: var(--text);
            font: inherit;
            outline: none;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(154, 52, 18, 0.10);
        }

        textarea {
            resize: vertical;
            min-height: 145px;
        }

        .help-text {
            color: var(--muted);
            font-size: 12px;
            margin-top: 6px;
        }

        .election-info {
            background: var(--panel-alt);
            padding: 16px;
            border-radius: 11px;
            margin-bottom: 20px;
            display: none;
            overflow-wrap: anywhere;
        }

        .election-info p {
            margin-top: 5px;
            font-size: 14px;
        }

        .btn {
            display: inline-block;
            border: none;
            border-radius: 9px;
            padding: 12px 20px;
            background: var(--primary);
            color: white;
            font-size: 14px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.2s, transform 0.2s;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
        }

        .applications-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .application-card {
            border: 1px solid var(--border);
            background: var(--input-bg);
            border-radius: 13px;
            padding: 20px;
            min-width: 0;
        }

        .application-card h3 {
            font-size: 18px;
            margin-bottom: 10px;
            overflow-wrap: anywhere;
        }

        .meta {
            font-size: 13px;
            color: var(--muted);
            margin: 5px 0;
        }

        .badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
            background: #F5E5D6;
            color: #80512F;
        }

        .badge.approved {
            background: #E0F3E4;
            color: #246B35;
        }

        .badge.rejected {
            background: #FDE3E3;
            color: #A12626;
        }

        .badge.completed {
            background: #E9E5F7;
            color: #5B4594;
        }

        .badge.upcoming {
            background: #FFF0C7;
            color: #855D0B;
        }

        .manifesto {
            margin-top: 14px;
            padding: 12px;
            background: var(--panel);
            border-radius: 9px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            font-size: 14px;
        }

        .empty-state {
            padding: 26px;
            border: 1px dashed var(--border);
            border-radius: 12px;
            text-align: center;
            color: var(--muted);
            background: var(--input-bg);
        }

        .debug-error {
            margin-top: 12px;
            padding: 12px;
            background: #FFF0ED;
            border: 1px solid #E8B7AA;
            border-radius: 8px;
            color: #7F1D1D;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        footer {
            text-align: center;
            color: var(--muted);
            padding: 22px 10px;
            font-size: 13px;
        }

        @media (max-width: 700px) {
            .form-grid,
            .applications-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 19px;
            }

            .navbar {
                padding: 15px 4%;
            }

            .nav-links {
                gap: 12px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <a class="brand" href="dashboard.php">VotePortal</a>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="apply.php">Apply for Election</a>
        <a href="../logout.php">Logout</a>
    </div>
</nav>

<main class="container">

    <header class="page-heading">
        <h1>Candidate Application</h1>
        <p>Apply to participate in an election and track your application status.</p>
    </header>

    <?php if ($message !== ''): ?>
        <div class="alert <?= h($message_type) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Apply for an Election</h2>

        <p class="section-intro">
            Select an available election and explain your goals and plans
            in your manifesto. The administrator will review your application.
        </p>

        <?php if ($election_error !== ''): ?>

            <div class="alert error">
                Could not load elections.
                <div class="debug-error">
                    <?= h($election_error) ?>
                </div>
            </div>

        <?php elseif ($available_elections->num_rows > 0): ?>

            <form method="POST" action="apply.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['csrf_token']) ?>"
                >

                <div class="form-grid">

                    <div class="form-group full">
                        <label for="election_id">Select Election *</label>

                        <select
                            name="election_id"
                            id="election_id"
                            required
                        >
                            <option value="">-- Choose an election --</option>

                            <?php while ($election = $available_elections->fetch_assoc()): ?>

                                <option
                                    value="<?= (int)$election['id'] ?>"
                                    data-category="<?= h($election['category']) ?>"
                                    data-description="<?= h($election['description']) ?>"
                                    data-start="<?= h(formatDate($election['start_date'])) ?>"
                                    data-end="<?= h(formatDate($election['end_date'])) ?>"
                                    <?= (
                                        isset($_POST['election_id']) &&
                                        (int)$_POST['election_id'] === (int)$election['id']
                                    ) ? 'selected' : '' ?>
                                >
                                    <?= h($election['title']) ?>
                                    (<?= h($election['status']) ?>)
                                </option>

                            <?php endwhile; ?>
                        </select>
                    </div>

                    <div class="form-group full">
                        <div class="election-info" id="electionInfo">
                            <strong id="infoTitle"></strong>
                            <p id="infoCategory"></p>
                            <p id="infoDescription"></p>
                            <p><strong>Starts:</strong> <span id="infoStart"></span></p>
                            <p><strong>Ends:</strong> <span id="infoEnd"></span></p>
                        </div>
                    </div>

                    <div class="form-group full">
                        <label for="manifesto">Your Manifesto *</label>

                        <textarea
                            name="manifesto"
                            id="manifesto"
                            maxlength="5000"
                            required
                            placeholder="Explain your goals, plans, priorities, and how you will represent voters..."
                        ><?= h($_POST['manifesto'] ?? '') ?></textarea>

                        <p class="help-text">
                            Maximum 5000 characters. Describe your goals and plans clearly.
                        </p>
                    </div>

                </div>

                <button type="submit" class="btn">
                    Submit Application
                </button>

            </form>

        <?php else: ?>

            <div class="empty-state">
                <h3>No elections available</h3>
                <p>
                    There are currently no upcoming or active elections
                    accepting applications.
                </p>
            </div>

        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>My Applications</h2>

        <p class="section-intro">
            Check the approval status of every election you have applied for.
        </p>

        <?php if ($applications_error !== ''): ?>

            <div class="alert error">
                Could not load your applications.
                <div class="debug-error">
                    <?= h($applications_error) ?>
                </div>
            </div>

        <?php elseif ($applications_result && $applications_result->num_rows > 0): ?>

            <div class="applications-grid">

                <?php while ($application = $applications_result->fetch_assoc()): ?>

                    <article class="application-card">

                        <h3><?= h($application['election_title']) ?></h3>

                        <p class="meta">
                            Category:
                            <?= h($application['category'] ?: 'General') ?>
                        </p>

                        <p class="meta">
                            Applied on:
                            <?= h(formatDate($application['applied_at'])) ?>
                        </p>

                        <p class="meta">
                            Election starts:
                            <?= h(formatDate($application['start_date'])) ?>
                        </p>

                        <p class="meta">
                            Election ends:
                            <?= h(formatDate($application['end_date'])) ?>
                        </p>

                        <p style="margin-top: 12px;">
                            Application:
                            <span class="badge <?= h(statusClass($application['application_status'])) ?>">
                                <?= h($application['application_status']) ?>
                            </span>
                        </p>

                        <p style="margin-top: 8px;">
                            Election:
                            <span class="badge <?= h(statusClass($application['election_status'])) ?>">
                                <?= h($application['election_status']) ?>
                            </span>
                        </p>

                        <div class="manifesto">
                            <strong>Your Manifesto</strong>
                            <p><?= h($application['manifesto']) ?></p>
                        </div>

                        <?php if ($application['application_status'] === 'pending'): ?>

                            <p class="meta" style="margin-top: 12px;">
                                Your application is waiting for admin approval.
                            </p>

                        <?php elseif ($application['application_status'] === 'approved'): ?>

                            <p class="meta" style="margin-top: 12px;">
                                Your application has been approved by the admin.
                            </p>

                        <?php elseif ($application['application_status'] === 'rejected'): ?>

                            <p class="meta" style="margin-top: 12px;">
                                Your application was rejected.
                                Contact the admin if you need clarification.
                            </p>

                        <?php endif; ?>

                    </article>

                <?php endwhile; ?>

            </div>

        <?php else: ?>

            <div class="empty-state">
                <h3>You haven't applied yet</h3>
                <p>Your submitted applications will appear here.</p>
            </div>

        <?php endif; ?>
    </section>

</main>

<footer>
    &copy; <?= date('Y') ?> VotePortal.
    Secure and transparent online voting.
</footer>

<script>
    const electionSelect = document.getElementById('election_id');
    const electionInfo = document.getElementById('electionInfo');

    function showElectionInfo() {
        if (!electionSelect || !electionInfo) {
            return;
        }

        const option = electionSelect.options[electionSelect.selectedIndex];

        if (!option || !option.value) {
            electionInfo.style.display = 'none';
            return;
        }

        document.getElementById('infoTitle').textContent = option.text;

        document.getElementById('infoCategory').textContent =
            'Category: ' + (option.dataset.category || 'General');

        document.getElementById('infoDescription').textContent =
            option.dataset.description || 'No description provided.';

        document.getElementById('infoStart').textContent =
            option.dataset.start || 'Not specified';

        document.getElementById('infoEnd').textContent =
            option.dataset.end || 'Not specified';

        electionInfo.style.display = 'block';
    }

    if (electionSelect) {
        electionSelect.addEventListener('change', showElectionInfo);
        showElectionInfo();
    }
</script>

</body>
</html>

<?php
if ($applications_stmt) {
    $applications_stmt->close();
}

$conn->close();
?>
