
<?php
session_start();
require_once '../config/db.php';

/* ADMIN AUTHENTICATION */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

/* HELPER */
function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/* CSRF TOKEN */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$categories = [
    'Student Council',
    'College Election',
    'University Election',
    'Department Election',
    'Class Election',
    'Batch Coordinator',
    'Class Representative (CR)',
    'Department Representative',
    'Student Representative',
    'Year Representative',
    'Section Representative',
    'Academic Representative',
    'Placement Coordinator',
    'Training and Placement Representative',
    'Career Development Coordinator',
    'Internship Coordinator',
    'Industry Relations Coordinator',
    'Program Coordinator',
    'Course Coordinator',
    'Academic Coordinator',
    'Academic Secretary',
    'Lab Coordinator',
    'Project Coordinator',
    'Research Coordinator',
    'Program Organizer',
    'Event Coordinator',
    'Cultural Coordinator',
    'Fest Coordinator',
    'Technical Fest Coordinator',
    'Workshop Coordinator',
    'Seminar Coordinator',
    'Hospitality Coordinator',
    'Public Relations Coordinator',
    'Media Coordinator',
    'Photography Coordinator',
    'Content Coordinator',
    'Social Media Coordinator',
    'Sports Coordinator',
    'Sports Captain',
    'Club President',
    'Club Vice President',
    'Club Secretary',
    'Club Treasurer',
    'Club Coordinator',
    'Technical Club Representative',
    'Cultural Club Representative',
    'Literary Club Representative',
    'Coding Club Representative',
    'Robotics Club Representative',
    'Student Welfare Coordinator',
    'Discipline Coordinator',
    'Anti-Ragging Representative',
    'Grievance Representative',
    'Women Cell Representative',
    'Environmental Coordinator',
    'Community Service Coordinator',
    'NSS Coordinator',
    'NCC Coordinator',
    'General Secretary',
    'Joint Secretary',
    'President',
    'Vice President',
    'Treasurer',
    'Other'
];

$statuses = ['draft', 'upcoming', 'active', 'completed'];

$error = '';
$success = '';

/* GET ELECTION ID */
$election_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$election_id || $election_id < 1) {
    header("Location: manage_elections.php");
    exit;
}

/* LOAD EXISTING ELECTION */
$stmt = $conn->prepare(
    "SELECT id, title, description, category, instructions,
            start_date, end_date, status
     FROM elections
     WHERE id = ?"
);

$stmt->bind_param("i", $election_id);
$stmt->execute();
$election = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$election) {
    $_SESSION['manage_elections_message'] = 'Election not found.';
    header("Location: manage_elections.php");
    exit;
}

/* FORM VALUES */
$title = $election['title'];
$category = $election['category'] ?: 'Other';
$description = $election['description'] ?? '';
$instructions = $election['instructions'] ?? '';
$start_date = date('Y-m-d\TH:i', strtotime($election['start_date']));
$end_date = date('Y-m-d\TH:i', strtotime($election['end_date']));
$status = $election['status'];

/* UPDATE ELECTION */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Security validation failed. Please reload the page and try again.';
    } else {

        $title = trim($_POST['title'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $status = trim($_POST['status'] ?? '');

        /* VALIDATE REQUIRED FIELDS */
        if (
            $title === '' ||
            $category === '' ||
            $description === '' ||
            $start_date === '' ||
            $end_date === ''
        ) {
            $error = 'Please fill in all required fields.';
        } elseif (strlen($title) > 255) {
            $error = 'Election title cannot exceed 255 characters.';
        } elseif (!in_array($category, $categories, true)) {
            $error = 'Please select a valid election category.';
        } elseif (!in_array($status, $statuses, true)) {
            $error = 'Please select a valid election status.';
        } else {

            /* VALIDATE DATE FORMAT */
            $startObj = DateTime::createFromFormat('Y-m-d\TH:i', $start_date);
            $endObj = DateTime::createFromFormat('Y-m-d\TH:i', $end_date);

            $validStart = $startObj &&
                $startObj->format('Y-m-d\TH:i') === $start_date;

            $validEnd = $endObj &&
                $endObj->format('Y-m-d\TH:i') === $end_date;

            if (!$validStart || !$validEnd) {
                $error = 'Please enter valid start and end dates.';
            } elseif ($endObj <= $startObj) {
                $error = 'End date and time must be after the start date and time.';
            } elseif (
                in_array($status, ['upcoming', 'active'], true) &&
                $endObj <= new DateTime()
            ) {
                $error = 'An upcoming or active election must have an end date in the future.';
            } elseif (
                $status === 'active' &&
                $startObj > new DateTime()
            ) {
                $error = 'An active election cannot have a future start date. Choose Upcoming instead.';
            } else {

                $startSQL = $startObj->format('Y-m-d H:i:s');
                $endSQL = $endObj->format('Y-m-d H:i:s');

                /*
                 * Update the existing row only.
                 * Election ID, candidates and votes are preserved.
                 */
                $update = $conn->prepare(
                    "UPDATE elections
                     SET title = ?,
                         description = ?,
                         category = ?,
                         instructions = ?,
                         start_date = ?,
                         end_date = ?,
                         status = ?
                     WHERE id = ?"
                );

                if (!$update) {
                    $error = 'Unable to prepare the update. Check your database columns.';
                } else {

                    $update->bind_param(
                        "sssssssi",
                        $title,
                        $description,
                        $category,
                        $instructions,
                        $startSQL,
                        $endSQL,
                        $status,
                        $election_id
                    );

                    if ($update->execute()) {
                        $update->close();

                        $_SESSION['manage_elections_message'] =
                            'Election updated successfully. Election ID and existing votes have been preserved.';

                        header("Location: manage_elections.php");
                        exit;

                    } else {
                        $error = 'Unable to update the election. Please check your database status options.';
                        $update->close();
                    }
                }
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

<title>Edit Election | VotePortal</title>

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
    background: white;
    border-bottom: 1px solid var(--line);
    padding: 17px 5%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.logo {
    text-decoration: none;
    font-size: 23px;
    font-weight: 800;
    color: var(--orange-dark);
}

.logo span {
    color: var(--bright);
}

.nav-links {
    display: flex;
    flex-wrap: wrap;
    gap: 18px;
}

.nav-links a {
    color: var(--orange-dark);
    text-decoration: none;
    font-weight: 700;
    font-size: 13px;
}

.container {
    width: calc(100% - 32px);
    max-width: 1100px;
    margin: 32px auto 45px;
}

.heading {
    margin-bottom: 22px;
}

.eyebrow {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 800;
    color: var(--orange);
    margin-bottom: 7px;
}

.heading h1 {
    font-size: clamp(25px, 4vw, 33px);
    margin-bottom: 7px;
}

.heading p {
    color: var(--muted);
    font-size: 14px;
}

.election-id {
    display: inline-block;
    margin-top: 12px;
    background: #FFF0E3;
    color: var(--orange-dark);
    padding: 7px 11px;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 800;
}

.card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 15px;
    box-shadow: 0 8px 25px rgba(124,45,18,.04);
    overflow: hidden;
}

.card-header {
    padding: 22px 25px;
    border-bottom: 1px solid #F5E9DE;
}

.card-header h2 {
    font-size: 18px;
    margin-bottom: 5px;
}

.card-header p {
    color: var(--muted);
    font-size: 12px;
}

.form-body {
    padding: 25px;
}

.alert {
    padding: 13px 15px;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 13px;
}

.alert.error {
    background: #FEE2E2;
    border: 1px solid #FECACA;
    color: #991B1B;
}

.notice {
    background: #FFF7ED;
    border: 1px solid #FED7AA;
    padding: 14px 16px;
    border-radius: 9px;
    font-size: 12px;
    color: #9A3412;
    margin-bottom: 22px;
}

.notice strong {
    display: block;
    margin-bottom: 4px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 20px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.field.full {
    grid-column: 1 / -1;
}

label {
    font-size: 12px;
    font-weight: 750;
}

.required {
    color: #DC2626;
}

input,
select,
textarea {
    width: 100%;
    min-width: 0;
    border: 1px solid #E7D5C5;
    border-radius: 9px;
    background: white;
    color: var(--ink);
    padding: 12px 13px;
    font-family: inherit;
    font-size: 13px;
    outline: none;
}

input,
select {
    min-height: 45px;
}

textarea {
    min-height: 115px;
    resize: vertical;
}

input:focus,
select:focus,
textarea:focus {
    border-color: var(--bright);
    box-shadow: 0 0 0 3px rgba(234,88,12,.10);
}

.help {
    color: var(--muted);
    font-size: 11px;
}

.actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 27px;
    padding-top: 22px;
    border-top: 1px solid #F5E9DE;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    min-height: 43px;
    padding: 11px 18px;
    border: 1px solid var(--orange);
    border-radius: 8px;
    background: var(--orange);
    color: white;
    font-size: 13px;
    font-weight: 750;
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
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

.footer {
    padding: 24px 15px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
}

@media (max-width: 650px) {
    .navbar {
        padding: 15px 18px;
        align-items: flex-start;
        flex-direction: column;
    }

    .container {
        margin-top: 23px;
    }

    .form-body {
        padding: 18px;
    }

    .card-header {
        padding: 18px;
    }

    .form-grid {
        grid-template-columns: 1fr;
        gap: 17px;
    }

    .field.full {
        grid-column: auto;
    }

    .actions {
        flex-direction: column;
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

    <nav class="nav-links">
        <a href="manage_elections.php">← Manage Elections</a>
        <a href="dashboard.php">Dashboard</a>
    </nav>
</header>

<main class="container">

    <section class="heading">
        <div class="eyebrow">Admin Workspace / Election Settings</div>
        <h1>Edit Election</h1>
        <p>Correct the election details and save your changes.</p>

        <span class="election-id">
            Editing Election #<?php echo (int)$election_id; ?>
        </span>
    </section>

    <?php if ($error !== ''): ?>
        <div class="alert error"><?php echo e($error); ?></div>
    <?php endif; ?>

    <section class="card">

        <div class="card-header">
            <h2>Election Information</h2>
            <p>Update the details below and save when you're finished.</p>
        </div>

        <div class="form-body">

            <div class="notice">
                <strong>Existing records are protected</strong>
                Changes update this election's existing database row. The election ID, candidate records and votes are not deleted or recreated.
            </div>

            <form method="POST"
                  action="edit_election.php?id=<?php echo (int)$election_id; ?>">

                <input type="hidden" name="csrf_token"
                       value="<?php echo e($_SESSION['csrf_token']); ?>">

                <div class="form-grid">

                    <div class="field full">
                        <label for="title">
                            Election Title <span class="required">*</span>
                        </label>

                        <input
                            type="text"
                            id="title"
                            name="title"
                            maxlength="255"
                            required
                            value="<?php echo e($title); ?>"
                            placeholder="Enter election title"
                        >
                    </div>

                    <div class="field">
                        <label for="category">
                            Category / Post <span class="required">*</span>
                        </label>

                        <select id="category" name="category" required>
                            <?php foreach ($categories as $item): ?>
                                <option
                                    value="<?php echo e($item); ?>"
                                    <?php echo $category === $item ? 'selected' : ''; ?>
                                >
                                    <?php echo e($item); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field">
                        <label for="status">
                            Election Status <span class="required">*</span>
                        </label>

                        <select id="status" name="status" required>
                            <?php foreach ($statuses as $item): ?>
                                <option
                                    value="<?php echo e($item); ?>"
                                    <?php echo $status === $item ? 'selected' : ''; ?>
                                >
                                    <?php echo e(ucfirst($item)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <span class="help">
                            Choose Active only when the election should accept votes and its dates permit voting.
                        </span>
                    </div>

                    <div class="field full">
                        <label for="description">
                            Description <span class="required">*</span>
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            required
                            placeholder="Describe the purpose of this election"
                        ><?php echo e($description); ?></textarea>
                    </div>

                    <div class="field full">
                        <label for="instructions">Voting Instructions</label>

                        <textarea
                            id="instructions"
                            name="instructions"
                            placeholder="Enter voting instructions for voters"
                        ><?php echo e($instructions); ?></textarea>

                        <span class="help">
                            Optional instructions displayed by pages that support this field.
                        </span>
                    </div>

                    <div class="field">
                        <label for="start_date">
                            Start Date & Time <span class="required">*</span>
                        </label>

                        <input
                            type="datetime-local"
                            id="start_date"
                            name="start_date"
                            required
                            value="<?php echo e($start_date); ?>"
                        >
                    </div>

                    <div class="field">
                        <label for="end_date">
                            End Date & Time <span class="required">*</span>
                        </label>

                        <input
                            type="datetime-local"
                            id="end_date"
                            name="end_date"
                            required
                            value="<?php echo e($end_date); ?>"
                        >

                        <span class="help">
                            The end date must be later than the start date.
                        </span>
                    </div>

                </div>

                <div class="actions">
                    <button type="submit" class="btn">
                        Save Changes
                    </button>

                    <a href="manage_elections.php" class="btn btn-light">
                        Cancel
                    </a>
                </div>

            </form>

        </div>
    </section>

</main>

<footer class="footer">
    © 2026 VotePortal · Online Voting System
</footer>

</body>
</html>
