<?php
session_start();
require_once '../config/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$error = "";
$success = "";

$title = "";
$description = "";
$category = "Student Council";
$instructions = "";
$start_date = "";
$end_date = "";

/* All available election categories and posts */
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

/* Save election */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? 'Other';
    $instructions = trim($_POST['instructions'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $action = $_POST['action'] ?? '';

    if ($title === '' || $start_date === '' || $end_date === '') {
        $error = "Please fill in the title and both dates.";
    } elseif (!in_array($category, $categories, true)) {
        $error = "Please select a valid category.";
    } elseif (!in_array($action, ['draft', 'create'], true)) {
        $error = "Invalid action. Please try again.";
    } elseif (
        strtotime($start_date) === false ||
        strtotime($end_date) === false ||
        strtotime($end_date) <= strtotime($start_date)
    ) {
        $error = "End date and time must be after the start date and time.";
    } else {
        if ($action === 'draft') {
            $status = 'draft';
        } else {
            $now = time();

            if (strtotime($start_date) > $now) {
                $status = 'upcoming';
            } elseif (strtotime($end_date) >= $now) {
                $status = 'active';
            } else {
                $status = 'completed';
            }
        }

        $start_db = date('Y-m-d H:i:s', strtotime($start_date));
        $end_db = date('Y-m-d H:i:s', strtotime($end_date));
        $admin_id = (int) $_SESSION['user_id'];

        $stmt = $conn->prepare(
            "INSERT INTO elections
            (title, description, category, instructions,
             start_date, end_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$stmt) {
            $error = "Unable to prepare query. Check your database columns.";
        } else {
            $stmt->bind_param(
                "sssssssi",
                $title,
                $description,
                $category,
                $instructions,
                $start_db,
                $end_db,
                $status,
                $admin_id
            );

            if ($stmt->execute()) {
                $success = ($action === 'draft')
                    ? "Election saved as draft successfully!"
                    : "Election created successfully!";

                $title = "";
                $description = "";
                $category = "Student Council";
                $instructions = "";
                $start_date = "";
                $end_date = "";
            } else {
                $error = "Unable to save election. Check your database columns and try again.";
            }

            $stmt->close();
        }
    }
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Election | VotePortal</title>

<style>
:root {
    --orange: #C2410C;
    --orange-dark: #9A3412;
    --orange-bright: #EA580C;
    --cream: #FFF7ED;
    --cream-deep: #FFEDD5;
    --ink: #292524;
    --muted: #78716C;
    --line: #F0DFCF;
    --white: #FFFFFF;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
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
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    background: white;
    border-bottom: 1px solid var(--line);
}

.logo {
    font-size: 23px;
    font-weight: 800;
    color: var(--orange-dark);
    text-decoration: none;
}

.logo span {
    color: var(--orange-bright);
}

.nav-actions {
    display: flex;
    align-items: center;
    gap: 20px;
}

.nav-actions a {
    text-decoration: none;
    font-size: 13px;
    font-weight: 700;
    color: var(--orange-dark);
}

.nav-actions a:hover {
    color: var(--orange-bright);
}

.container {
    width: min(1180px, calc(100% - 40px));
    margin: 32px auto 48px;
}

/* Header */

.hero {
    position: relative;
    overflow: hidden;
    background: var(--orange-dark);
    color: white;
    border-radius: 18px;
    padding: 36px;
    margin-bottom: 24px;
}

.hero::after {
    content: "";
    position: absolute;
    width: 210px;
    height: 210px;
    border: 36px solid rgba(255,255,255,.08);
    border-radius: 50%;
    right: -50px;
    top: -90px;
    pointer-events: none;
}

.eyebrow {
    color: #FED7AA;
    text-transform: uppercase;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.8px;
    margin-bottom: 9px;
}

.hero h1 {
    font-size: clamp(27px, 3.5vw, 36px);
    line-height: 1.2;
    margin-bottom: 10px;
}

.hero p {
    color: #FFEDD5;
    font-size: 14px;
    max-width: 620px;
}

.hero-tag {
    display: inline-flex;
    margin-top: 18px;
    padding: 7px 11px;
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 999px;
    color: #FFF7ED;
    font-size: 12px;
    font-weight: 700;
    background: rgba(255,255,255,.08);
}

/* Messages */

.message {
    border-radius: 10px;
    padding: 13px 16px;
    margin-bottom: 18px;
    font-size: 14px;
}

.error {
    background: #FEE2E2;
    color: #991B1B;
    border: 1px solid #FECACA;
}

.success {
    background: #DCFCE7;
    color: #166534;
    border: 1px solid #BBF7D0;
}

.message a {
    font-weight: 800;
    color: inherit;
}

/* Main layout */

.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 310px;
    gap: 22px;
    align-items: start;
}

.panel {
    background: white;
    border: 1px solid var(--line);
    border-radius: 15px;
    box-shadow: 0 8px 24px rgba(124, 45, 18, .045);
}

.form-panel {
    padding: 30px;
}

.panel-heading {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 14px;
    padding-bottom: 20px;
    margin-bottom: 22px;
    border-bottom: 1px solid #F5E9DE;
}

.panel-heading h2 {
    font-size: 19px;
    margin-bottom: 5px;
}

.panel-heading p {
    color: var(--muted);
    font-size: 13px;
}

.required-note {
    color: var(--muted);
    font-size: 11px;
    white-space: nowrap;
    padding-top: 4px;
}

/* Form fields */

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 19px 17px;
}

.form-group {
    min-width: 0;
}

.full-width {
    grid-column: 1 / -1;
}

label {
    display: block;
    font-size: 13px;
    font-weight: 750;
    margin-bottom: 8px;
}

.star {
    color: var(--orange-bright);
}

input,
select,
textarea {
    display: block;
    width: 100%;
    min-width: 0;
    border: 1px solid #E7D5C5;
    border-radius: 9px;
    background: #FFFCF9;
    color: var(--ink);
    font-family: inherit;
    font-size: 14px;
    padding: 12px 13px;
    transition: border-color .15s, box-shadow .15s;
}

input,
select {
    min-height: 46px;
}

textarea {
    min-height: 96px;
    resize: vertical;
    line-height: 1.55;
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: var(--orange-bright);
    background: white;
    box-shadow: 0 0 0 3px rgba(234, 88, 12, .12);
}

.field-help {
    color: var(--muted);
    font-size: 11px;
    margin-top: 6px;
}

/* Status preview */

.status-box {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 14px 15px;
    margin-top: 22px;
    background: #FFFAF5;
    border: 1px solid var(--line);
    border-radius: 10px;
}

.status-label {
    font-size: 13px;
    font-weight: 750;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    border-radius: 999px;
    padding: 7px 11px;
    background: var(--cream-deep);
    color: var(--orange-dark);
    font-size: 12px;
    font-weight: 800;
}

.status-dot {
    width: 7px;
    height: 7px;
    background: currentColor;
    border-radius: 50%;
}

.status-badge.active-status {
    background: #DCFCE7;
    color: #166534;
}

.status-badge.error-status {
    background: #FEE2E2;
    color: #991B1B;
}

.status-badge.completed-status {
    background: #E7E5E4;
    color: #57534E;
}

/* Buttons */

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 22px;
    padding-top: 21px;
    border-top: 1px solid #F5E9DE;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 8px;
    min-height: 44px;
    padding: 11px 17px;
    border: 1px solid var(--orange);
    border-radius: 9px;
    background: var(--orange);
    color: white;
    font-family: inherit;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    text-decoration: none;
    transition: transform .15s, background .15s;
}

.btn:hover {
    background: var(--orange-dark);
    transform: translateY(-1px);
}

.btn-secondary {
    background: white;
    color: var(--orange-dark);
}

.btn-secondary:hover {
    background: #FFF3E8;
}

.btn-quiet {
    border-color: #E7D5C5;
    background: white;
    color: #57534E;
}

.btn-quiet:hover {
    background: #FAFAF9;
}

/* Live summary */

.preview-panel {
    overflow: hidden;
}

.preview-top {
    padding: 22px 22px 18px;
    border-bottom: 1px solid var(--line);
}

.preview-top h2 {
    font-size: 18px;
    margin-bottom: 5px;
}

.preview-top p {
    color: var(--muted);
    font-size: 12px;
}

.preview-content {
    padding: 20px 22px 22px;
}

.preview-title {
    padding: 17px;
    background: #FFF0E3;
    border-radius: 11px;
    border-left: 4px solid var(--orange-bright);
    margin-bottom: 17px;
}

.mini-label {
    color: var(--orange-dark);
    font-size: 10px;
    font-weight: 850;
    letter-spacing: 1.2px;
    margin-bottom: 7px;
}

.preview-title h3 {
    font-size: 17px;
    line-height: 1.45;
    overflow-wrap: anywhere;
}

.preview-row {
    display: grid;
    grid-template-columns: 76px minmax(0, 1fr);
    gap: 10px;
    padding: 12px 0;
    border-bottom: 1px solid #F3E8DE;
}

.preview-row:last-of-type {
    border-bottom: 0;
}

.preview-row span {
    color: var(--muted);
    font-size: 12px;
}

.preview-row strong {
    font-size: 12px;
    line-height: 1.5;
    text-align: right;
    overflow-wrap: anywhere;
}

.preview-status {
    justify-self: end;
    display: inline-block;
    background: var(--cream-deep);
    color: var(--orange-dark);
    border-radius: 999px;
    padding: 4px 8px;
}

.preview-status.active-status {
    background: #DCFCE7;
    color: #166534;
}

.preview-status.error-status {
    background: #FEE2E2;
    color: #991B1B;
}

.preview-status.completed-status {
    background: #E7E5E4;
    color: #57534E;
}

.preview-note {
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid #F3E8DE;
}

.preview-note h4 {
    font-size: 12px;
    margin-bottom: 5px;
}

.preview-note p {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.6;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.preview-hint {
    display: flex;
    gap: 9px;
    margin-top: 18px;
    padding: 12px;
    border-radius: 9px;
    background: #FFFAF5;
    color: #7C2D12;
    font-size: 11px;
    line-height: 1.55;
}

.footer {
    padding: 25px 15px;
    text-align: center;
    color: var(--muted);
    font-size: 12px;
}

/* Tablet */

@media (max-width: 900px) {
    .layout {
        grid-template-columns: minmax(0, 1fr);
    }

    .preview-panel {
        order: 2;
    }

    .preview-content {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0 22px;
    }

    .preview-title,
    .preview-row,
    .preview-note,
    .preview-hint {
        min-width: 0;
    }

    .preview-title,
    .preview-hint {
        grid-column: 1 / -1;
    }
}

/* Mobile */

@media (max-width: 560px) {
    .navbar {
        min-height: 62px;
        padding: 13px 17px;
    }

    .logo {
        font-size: 21px;
    }

    .nav-actions {
        gap: 10px;
    }

    .nav-actions a {
        font-size: 12px;
    }

    .container {
        width: calc(100% - 26px);
        margin: 20px auto 30px;
    }

    .hero {
        border-radius: 13px;
        padding: 23px 20px;
        margin-bottom: 16px;
    }

    .hero h1 {
        font-size: 27px;
    }

    .layout {
        gap: 16px;
    }

    .form-panel {
        padding: 19px 16px;
    }

    .panel-heading {
        flex-direction: column;
        gap: 5px;
        padding-bottom: 16px;
        margin-bottom: 18px;
    }

    .form-grid {
        grid-template-columns: minmax(0, 1fr);
        gap: 17px;
    }

    .full-width {
        grid-column: auto;
    }

    .required-note {
        white-space: normal;
    }

    .actions {
        flex-direction: column;
    }

    .actions .btn {
        width: 100%;
    }

    .preview-content {
        display: block;
        padding: 17px;
    }

    .preview-top {
        padding: 19px 17px 15px;
    }

    .status-box {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>

<header class="navbar">
    <a class="logo" href="dashboard.php">
        Vote<span>Portal</span>
    </a>

    <nav class="nav-actions">
        <a href="manage_elections.php">Manage Elections</a>
        <a href="dashboard.php">← Dashboard</a>
    </nav>
</header>

<main class="container">

    <section class="hero">
        <div class="eyebrow">Admin workspace · Elections</div>
        <h1>Create a new election</h1>
        <p>
            Set up election details, select a position, define the voting
            schedule, and review everything before saving.
        </p>
        <span class="hero-tag">● Election setup</span>
    </section>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?php echo e($success); ?>
            &nbsp;
            <a href="manage_elections.php">View elections →</a>
        </div>
    <?php endif; ?>

    <div class="layout">

        <section class="panel form-panel">

            <div class="panel-heading">
                <div>
                    <h2>Election information</h2>
                    <p>Enter the basic details and voting period.</p>
                </div>

                <span class="required-note">
                    <span style="color:#EA580C">*</span>
                    Required fields
                </span>
            </div>

            <form method="POST" id="electionForm">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="title">
                            Election title <span class="star">*</span>
                        </label>

                        <input
                            type="text"
                            id="title"
                            name="title"
                            maxlength="200"
                            required
                            placeholder="e.g. Batch Coordinator Election 2026"
                            value="<?php echo e($title); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="category">
                            Category / Position <span class="star">*</span>
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

                        <p class="field-help">
                            Choose the post or election type.
                        </p>
                    </div>

                    <div class="form-group full-width">
                        <label for="description">Description</label>

                        <textarea
                            id="description"
                            name="description"
                            maxlength="2000"
                            placeholder="Explain the purpose of this election..."
                        ><?php echo e($description); ?></textarea>

                        <p class="field-help">
                            Describe the role, responsibilities, or election purpose.
                        </p>
                    </div>

                    <div class="form-group full-width">
                        <label for="instructions">
                            Voter instructions & rules
                        </label>

                        <textarea
                            id="instructions"
                            name="instructions"
                            maxlength="3000"
                            placeholder="Mention eligibility, voting rules, and important instructions..."
                        ><?php echo e($instructions); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="start_date">
                            Start date & time <span class="star">*</span>
                        </label>

                        <input
                            type="datetime-local"
                            id="start_date"
                            name="start_date"
                            required
                            value="<?php
                                echo e(
                                    $start_date
                                        ? date('Y-m-d\TH:i', strtotime($start_date))
                                        : ''
                                );
                            ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="end_date">
                            End date & time <span class="star">*</span>
                        </label>

                        <input
                            type="datetime-local"
                            id="end_date"
                            name="end_date"
                            required
                            value="<?php
                                echo e(
                                    $end_date
                                        ? date('Y-m-d\TH:i', strtotime($end_date))
                                        : ''
                                );
                            ?>"
                        >

                        <p class="field-help">
                            End date must be later than start date.
                        </p>
                    </div>

                </div>

                <div class="status-box">
                    <span class="status-label">
                        Election status preview
                    </span>

                    <span class="status-badge" id="statusPreview">
                        <span class="status-dot"></span>
                        <span id="statusText">Select dates</span>
                    </span>
                </div>

                <div class="actions">

                    <button
                        type="submit"
                        name="action"
                        value="draft"
                        class="btn btn-secondary"
                    >
                        Save as Draft
                    </button>

                    <button
                        type="submit"
                        name="action"
                        value="create"
                        class="btn"
                    >
                        Create Election →
                    </button>

                    <a href="dashboard.php" class="btn btn-quiet">
                        Cancel
                    </a>

                </div>

            </form>
        </section>

        <aside class="panel preview-panel">

            <div class="preview-top">
                <h2>Live summary</h2>
                <p>Your election details appear here as you type.</p>
            </div>

            <div class="preview-content">

                <div class="preview-title">
                    <div class="mini-label">ELECTION TITLE</div>
                    <h3 id="summaryTitle">Your election title</h3>
                </div>

                <div>
                    <div class="preview-row">
                        <span>Position</span>
                        <strong id="summaryCategory">Student Council</strong>
                    </div>

                    <div class="preview-row">
                        <span>Starts</span>
                        <strong id="summaryStart">Not selected</strong>
                    </div>

                    <div class="preview-row">
                        <span>Ends</span>
                        <strong id="summaryEnd">Not selected</strong>
                    </div>

                    <div class="preview-row">
                        <span>Status</span>
                        <strong class="preview-status" id="summaryStatus">
                            Select dates
                        </strong>
                    </div>
                </div>

                <div class="preview-note">
                    <h4>Description</h4>
                    <p id="summaryDescription">No description added.</p>
                </div>

                <div class="preview-note">
                    <h4>Voter instructions</h4>
                    <p id="summaryInstructions">No instructions added.</p>
                </div>

                <div class="preview-hint">
                    <span aria-hidden="true">ⓘ</span>
                    <span>
                        Save as Draft to keep the election in draft status
                        until you are ready.
                    </span>
                </div>

            </div>
        </aside>

    </div>
</main>

<footer class="footer">
    © 2026 VotePortal · Online Voting System
</footer>

<script>
const titleInput = document.getElementById('title');
const categoryInput = document.getElementById('category');
const descriptionInput = document.getElementById('description');
const instructionsInput = document.getElementById('instructions');
const startInput = document.getElementById('start_date');
const endInput = document.getElementById('end_date');

function formatDate(value) {
    if (!value) {
        return 'Not selected';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Not selected';
    }

    return date.toLocaleString([], {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function updatePreview() {
    document.getElementById('summaryTitle').textContent =
        titleInput.value.trim() || 'Your election title';

    document.getElementById('summaryCategory').textContent =
        categoryInput.value;

    document.getElementById('summaryStart').textContent =
        formatDate(startInput.value);

    document.getElementById('summaryEnd').textContent =
        formatDate(endInput.value);

    document.getElementById('summaryDescription').textContent =
        descriptionInput.value.trim() || 'No description added.';

    document.getElementById('summaryInstructions').textContent =
        instructionsInput.value.trim() || 'No instructions added.';

    const badge = document.getElementById('statusPreview');
    const statusText = document.getElementById('statusText');
    const summaryStatus = document.getElementById('summaryStatus');

    let status = 'Select dates';
    let stateClass = '';

    if (startInput.value && endInput.value) {
        const start = new Date(startInput.value);
        const end = new Date(endInput.value);
        const now = new Date();

        if (
            Number.isNaN(start.getTime()) ||
            Number.isNaN(end.getTime()) ||
            end <= start
        ) {
            status = 'Check dates';
            stateClass = 'error-status';
        } else if (start > now) {
            status = 'Upcoming';
        } else if (end >= now) {
            status = 'Active';
            stateClass = 'active-status';
        } else {
            status = 'Completed';
            stateClass = 'completed-status';
        }
    }

    badge.className = 'status-badge' +
        (stateClass ? ' ' + stateClass : '');

    summaryStatus.className = 'preview-status' +
        (stateClass ? ' ' + stateClass : '');

    statusText.textContent = status;
    summaryStatus.textContent = status;
}

[
    titleInput,
    categoryInput,
    descriptionInput,
    instructionsInput,
    startInput,
    endInput
].forEach(function(field) {
    field.addEventListener('input', updatePreview);
    field.addEventListener('change', updatePreview);
});

document.getElementById('electionForm').addEventListener(
    'submit',
    function(event) {
        if (
            startInput.value &&
            endInput.value &&
            new Date(endInput.value) <= new Date(startInput.value)
        ) {
            event.preventDefault();

            alert(
                'End date and time must be after the start date and time.'
            );

            endInput.focus();
        }
    }
);

updatePreview();
</script>

</body>
</html>