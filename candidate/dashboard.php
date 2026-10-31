
<?php
session_start();
require_once '../config/db.php';

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'candidate'
) {
    header("Location: ../login.php");
    exit;
}

$userId = (int) $_SESSION['user_id'];
$uploadDir = __DIR__ . '/../uploads/profiles/';
$message = '';
$messageType = 'success';

if (empty($_SESSION['profile_csrf'])) {
    $_SESSION['profile_csrf'] = bin2hex(random_bytes(32));
}

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function electionDate($date) {
    return !empty($date)
        ? date('d M Y, h:i A', strtotime($date))
        : 'Not specified';
}

// Get candidate account
$stmt = $conn->prepare(
    "SELECT id, name, email, profile_image
     FROM users
     WHERE id = ?"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// Upload or remove profile picture
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['profile_csrf'], $token)
    ) {
        $message = 'Invalid request. Please refresh the page.';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'upload') {
            if (
                !isset($_FILES['profile_image']) ||
                $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK
            ) {
                $message = 'Please select a photo to upload.';
                $messageType = 'error';
            } elseif ($_FILES['profile_image']['size'] > 2 * 1024 * 1024) {
                $message = 'Photo size must be 2 MB or less.';
                $messageType = 'error';
            } elseif (!is_uploaded_file($_FILES['profile_image']['tmp_name'])) {
                $message = 'Invalid uploaded file.';
                $messageType = 'error';
            } else {
                $tmpFile = $_FILES['profile_image']['tmp_name'];
                $imageInfo = @getimagesize($tmpFile);
                $mime = $imageInfo['mime'] ?? '';

                $allowedTypes = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!$imageInfo || !isset($allowedTypes[$mime])) {
                    $message = 'Only JPG, PNG and WEBP images are allowed.';
                    $messageType = 'error';
                } else {
                    if (
                        !is_dir($uploadDir) &&
                        !mkdir($uploadDir, 0755, true) &&
                        !is_dir($uploadDir)
                    ) {
                        $message = 'Cannot create the profile upload folder.';
                        $messageType = 'error';
                    }

                    if ($messageType !== 'error') {
                        $fileName = 'profile_' . $userId . '_' .
                            bin2hex(random_bytes(12)) . '.' .
                            $allowedTypes[$mime];

                        $destination = $uploadDir . $fileName;
                        $relativePath = 'uploads/profiles/' . $fileName;

                        if (move_uploaded_file($tmpFile, $destination)) {
                            $update = $conn->prepare(
                                "UPDATE users
                                 SET profile_image = ?
                                 WHERE id = ?"
                            );
                            $update->bind_param("si", $relativePath, $userId);

                            if ($update->execute()) {
                                $oldImage = $user['profile_image'] ?? '';
                                $prefix = 'uploads/profiles/';

                                if (strpos($oldImage, $prefix) === 0) {
                                    $oldName = substr($oldImage, strlen($prefix));

                                    if (
                                        $oldName !== '' &&
                                        basename($oldName) === $oldName &&
                                        $oldName !== $fileName
                                    ) {
                                        $oldPath = $uploadDir . $oldName;

                                        if (is_file($oldPath)) {
                                            @unlink($oldPath);
                                        }
                                    }
                                }

                                $user['profile_image'] = $relativePath;
                                $message = 'Profile picture uploaded successfully!';
                            } else {
                                @unlink($destination);
                                $message = 'Could not save the image in the database.';
                                $messageType = 'error';
                            }

                            $update->close();
                        } else {
                            $message = 'Upload failed. Check folder permissions.';
                            $messageType = 'error';
                        }
                    }
                }
            }
        } elseif ($action === 'remove') {
            $oldImage = $user['profile_image'] ?? '';

            $update = $conn->prepare(
                "UPDATE users
                 SET profile_image = NULL
                 WHERE id = ?"
            );
            $update->bind_param("i", $userId);

            if ($update->execute()) {
                $prefix = 'uploads/profiles/';

                if (strpos($oldImage, $prefix) === 0) {
                    $oldName = substr($oldImage, strlen($prefix));

                    if ($oldName !== '' && basename($oldName) === $oldName) {
                        $oldPath = $uploadDir . $oldName;

                        if (is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                }

                $user['profile_image'] = null;
                $message = 'Profile picture removed successfully.';
            } else {
                $message = 'Could not remove the profile picture.';
                $messageType = 'error';
            }

            $update->close();
        }
    }
}

// Resolve profile picture URL
$profileImageUrl = '';
$profileImage = $user['profile_image'] ?? '';
$prefix = 'uploads/profiles/';

if (is_string($profileImage) && strpos($profileImage, $prefix) === 0) {
    $imageFileName = substr($profileImage, strlen($prefix));

    if (
        $imageFileName !== '' &&
        basename($imageFileName) === $imageFileName
    ) {
        $imagePath = $uploadDir . $imageFileName;

        if (is_file($imagePath)) {
            $profileImageUrl = '../uploads/profiles/' .
                rawurlencode($imageFileName);
        } else {
            $message = 'Your saved photo file could not be found. Please upload it again.';
            $messageType = 'error';
        }
    }
}

$name = $user['name'] ?? 'Candidate';
$email = $user['email'] ?? '';
$initial = strtoupper(substr(trim($name), 0, 1));

if ($initial === '') {
    $initial = 'C';
}

// Candidate application statistics
$totalApplications = 0;
$approvedApplications = 0;
$pendingApplications = 0;
$rejectedApplications = 0;

$stmt = $conn->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'approved'), 0) AS approved,
            COALESCE(SUM(status = 'pending'), 0) AS pending,
            COALESCE(SUM(status = 'rejected'), 0) AS rejected
     FROM candidates
     WHERE user_id = ?"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($stats) {
    $totalApplications = (int)$stats['total'];
    $approvedApplications = (int)$stats['approved'];
    $pendingApplications = (int)$stats['pending'];
    $rejectedApplications = (int)$stats['rejected'];
}

// Recent applications
$stmt = $conn->prepare(
    "SELECT c.status, c.applied_at, e.title, e.category,
            e.start_date, e.end_date
     FROM candidates c
     JOIN elections e ON e.id = c.election_id
     WHERE c.user_id = ?
     ORDER BY c.applied_at DESC
     LIMIT 5"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$applications = $stmt->get_result();
$stmt->close();

// Elections accepting candidate applications
$stmt = $conn->prepare(
    "SELECT e.id, e.title, e.category, e.description,
            e.start_date, e.end_date
     FROM elections e
     WHERE e.status IN ('upcoming', 'active')
       AND e.end_date >= NOW()
       AND NOT EXISTS (
           SELECT 1
           FROM candidates c
           WHERE c.election_id = e.id
             AND c.user_id = ?
       )
     ORDER BY e.start_date ASC
     LIMIT 5"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$availableElections = $stmt->get_result();
$stmt->close();

// Elections currently open for voting
$stmt = $conn->prepare(
    "SELECT
        e.id,
        e.title,
        e.category,
        e.description,
        e.start_date,
        e.end_date,
        EXISTS (
            SELECT 1
            FROM votes v
            WHERE v.voter_id = ?
              AND v.election_id = e.id
        ) AS already_voted
     FROM elections e
     WHERE e.status = 'active'
       AND e.start_date <= NOW()
       AND e.end_date >= NOW()
     ORDER BY e.end_date ASC"
);
$stmt->bind_param("i", $userId);
$stmt->execute();
$votingElections = $stmt->get_result();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate Dashboard | VotePortal</title>

    <style>
        :root {
            --cream: #fff8f0;
            --peach: #f9e4d7;
            --peach-light: #fff0e7;
            --terracotta: #a64b35;
            --terracotta-dark: #853923;
            --text: #3d302b;
            --muted: #796b64;
            --border: #efd3c4;
            --red: #b83232;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--cream);
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .navbar {
            background: #fffaf6;
            border-bottom: 1px solid var(--border);
            padding: 16px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .brand {
            color: var(--terracotta);
            font-size: 25px;
            font-weight: 800;
        }

        .nav-links {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        .nav-links a {
            font-size: 14px;
            font-weight: 600;
        }

        .nav-links a:hover {
            color: var(--terracotta);
        }

        .logout {
            background: var(--terracotta);
            color: white;
            padding: 10px 16px;
            border-radius: 9px;
        }

        .container {
            max-width: 1180px;
            width: 92%;
            margin: 30px auto;
        }

        .welcome {
            margin-bottom: 24px;
        }

        .welcome h1 {
            font-size: clamp(25px, 4vw, 34px);
            margin-bottom: 8px;
        }

        .welcome p {
            color: var(--muted);
            line-height: 1.6;
        }

        .notice {
            padding: 13px 16px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .notice.success {
            background: #e5f4e9;
            color: #205f39;
        }

        .notice.error {
            background: #ffebeb;
            color: #9d2929;
        }

        .profile-card {
            background: var(--peach);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 26px;
            display: flex;
            align-items: center;
            gap: 26px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .avatar {
            width: 125px;
            height: 125px;
            border-radius: 50%;
            border: 4px solid #fffaf6;
            background: var(--terracotta);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            flex-shrink: 0;
            font-size: 44px;
            font-weight: bold;
            box-shadow: 0 4px 15px #8c503020;
        }

        .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .profile-details {
            flex: 1;
            min-width: 220px;
        }

        .profile-details h2 {
            font-size: 24px;
            margin-bottom: 7px;
        }

        .profile-details p {
            color: var(--muted);
            margin-bottom: 6px;
            overflow-wrap: anywhere;
        }

        .profile-label {
            display: inline-block;
            margin-top: 8px;
            background: #fff6ef;
            color: var(--terracotta-dark);
            padding: 6px 11px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: bold;
        }

        .photo-actions {
            margin-top: 15px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .file-input {
            display: none;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 9px;
            padding: 11px 15px;
            font-size: 13px;
            font-weight: bold;
            cursor: pointer;
            transition: .2s;
        }

        .btn-primary {
            background: var(--terracotta);
            color: white;
        }

        .btn-primary:hover {
            background: var(--terracotta-dark);
        }

        .btn-light {
            background: #fffaf6;
            color: var(--terracotta-dark);
            border: 1px solid var(--border);
        }

        .btn-danger {
            background: #fff0ee;
            color: var(--red);
            border: 1px solid #f2c7c1;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--peach-light);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 21px;
        }

        .stat-card p {
            font-size: 13px;
            color: var(--muted);
            margin-bottom: 13px;
        }

        .stat-card h3 {
            font-size: 29px;
            color: var(--terracotta-dark);
        }

        .section {
            margin-bottom: 28px;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .section-heading h2 {
            font-size: 21px;
        }

        .panel {
            background: #fffaf6;
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 20px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 550px;
        }

        th, td {
            text-align: left;
            padding: 14px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .status {
            display: inline-block;
            border-radius: 30px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .status.approved {
            color: #21633d;
            background: #e2f4e7;
        }

        .status.pending {
            color: #895c0c;
            background: #fff0cb;
        }

        .status.rejected {
            color: #a12d2d;
            background: #ffe3e3;
        }

        .status.voted {
            color: #21633d;
            background: #e2f4e7;
        }

        .election-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .election-card {
            background: var(--peach-light);
            border: 1px solid var(--border);
            border-radius: 15px;
            padding: 20px;
        }

        .election-card h3 {
            font-size: 18px;
            margin-bottom: 8px;
        }

        .category {
            color: var(--terracotta);
            font-size: 12px;
            font-weight: bold;
            margin-bottom: 12px;
        }

        .election-card p {
            font-size: 14px;
            line-height: 1.6;
            color: var(--muted);
            margin-bottom: 15px;
            overflow-wrap: anywhere;
        }

        .empty {
            color: var(--muted);
            text-align: center;
            padding: 25px 10px;
            line-height: 1.6;
        }

        footer {
            text-align: center;
            padding: 22px;
            color: var(--muted);
            font-size: 13px;
            border-top: 1px solid var(--border);
            margin-top: 35px;
        }

        @media (max-width: 800px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .election-grid {
                grid-template-columns: 1fr;
            }

            .profile-card {
                align-items: flex-start;
            }
        }

        @media (max-width: 480px) {
            .container {
                width: 94%;
                margin-top: 22px;
            }

            .navbar {
                padding: 15px 4%;
            }

            .nav-links {
                gap: 13px;
            }

            .profile-card {
                padding: 20px;
                gap: 18px;
            }

            .avatar {
                width: 100px;
                height: 100px;
                font-size: 36px;
            }

            .stats {
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-card h3 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <a href="dashboard.php" class="brand">VotePortal</a>

    <div class="nav-links">
        <a href="dashboard.php">Dashboard</a>
        <a href="apply.php">Apply for Election</a>
        <a href="../logout.php" class="logout">Logout</a>
    </div>
</nav>

<main class="container">

    <?php if ($message !== ''): ?>
        <div class="notice <?= h($messageType) ?>">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <div class="welcome">
        <h1>Candidate Dashboard</h1>
        <p>Manage your profile, track your applications, explore elections and cast your vote.</p>
    </div>

    <!-- Candidate profile -->
    <section class="profile-card">

        <div class="avatar">
            <?php if ($profileImageUrl !== ''): ?>
                <img
                    src="<?= h($profileImageUrl) ?>"
                    alt="Candidate profile picture"
                    onerror="this.style.display='none'; this.parentElement.textContent='<?= h($initial) ?>';"
                >
            <?php else: ?>
                <?= h($initial) ?>
            <?php endif; ?>
        </div>

        <div class="profile-details">
            <h2><?= h($name) ?></h2>
            <p><?= h($email) ?></p>
            <span class="profile-label">Candidate Account</span>

            <form
                method="POST"
                enctype="multipart/form-data"
                class="photo-actions"
                id="photoForm"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($_SESSION['profile_csrf']) ?>"
                >
                <input type="hidden" name="action" value="upload">

                <input
                    type="file"
                    name="profile_image"
                    id="profileImageInput"
                    class="file-input"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    required
                >

                <button type="button" class="btn btn-primary" id="choosePhotoBtn">
                    <?= $profileImageUrl !== '' ? 'Change Picture' : 'Add Profile Picture' ?>
                </button>

                <button type="submit" class="btn btn-light" id="uploadBtn" hidden>
                    Upload Photo
                </button>
            </form>

            <?php if ($profileImageUrl !== ''): ?>
                <form method="POST" class="photo-actions" id="removePhotoForm">
                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= h($_SESSION['profile_csrf']) ?>"
                    >
                    <input type="hidden" name="action" value="remove">

                    <button
                        type="submit"
                        class="btn btn-danger"
                        onclick="return confirm('Are you sure you want to remove your profile picture?')"
                    >
                        Remove Photo
                    </button>
                </form>
            <?php endif; ?>

            <p style="font-size:12px; margin-top:10px;">
                Accepted formats: JPG, PNG, WEBP. Maximum size: 2 MB.
            </p>
        </div>
    </section>

    <!-- Application statistics -->
    <section class="stats">
        <div class="stat-card">
            <p>Total Applications</p>
            <h3><?= $totalApplications ?></h3>
        </div>

        <div class="stat-card">
            <p>Approved</p>
            <h3><?= $approvedApplications ?></h3>
        </div>

        <div class="stat-card">
            <p>Pending</p>
            <h3><?= $pendingApplications ?></h3>
        </div>

        <div class="stat-card">
            <p>Rejected</p>
            <h3><?= $rejectedApplications ?></h3>
        </div>
    </section>

    <!-- Elections currently open for voting -->
    <section class="section">
        <div class="section-heading">
            <h2>Available Elections for Voting</h2>
        </div>

        <div class="election-grid">
            <?php if ($votingElections->num_rows > 0): ?>

                <?php while ($voteElection = $votingElections->fetch_assoc()): ?>
                    <article class="election-card">

                        <div class="category">
                            <?= h($voteElection['category'] ?: 'General Election') ?>
                        </div>

                        <h3><?= h($voteElection['title']) ?></h3>

                        <p>
                            <?= h(
                                $voteElection['description']
                                    ?: 'Participate in this election by casting your vote.'
                            ) ?>
                        </p>

                        <p>
                            Voting closes:
                            <strong>
                                <?= h(electionDate($voteElection['end_date'])) ?>
                            </strong>
                        </p>

                        <?php if ((int)$voteElection['already_voted'] === 1): ?>

                            <span class="status voted">Vote Submitted</span>

                        <?php else: ?>

                            <a
                                href="vote.php?election_id=<?= (int)$voteElection['id'] ?>"
                                class="btn btn-primary"
                            >
                                Vote Now →
                            </a>

                        <?php endif; ?>

                    </article>
                <?php endwhile; ?>

            <?php else: ?>

                <div class="panel empty" style="grid-column:1/-1;">
                    No elections are currently open for voting.
                    Please check again when an election becomes active.
                </div>

            <?php endif; ?>
        </div>
    </section>

    <!-- Recent applications -->
    <section class="section">
        <div class="section-heading">
            <h2>My Recent Applications</h2>
            <a href="apply.php" class="btn btn-primary">Apply Now</a>
        </div>

        <div class="panel table-wrap">
            <?php if ($applications->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Election</th>
                            <th>Category</th>
                            <th>Applied On</th>
                            <th>Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while ($app = $applications->fetch_assoc()): ?>
                            <tr>
                                <td><?= h($app['title']) ?></td>
                                <td><?= h($app['category'] ?: 'General') ?></td>
                                <td>
                                    <?= !empty($app['applied_at'])
                                        ? h(date('d M Y', strtotime($app['applied_at'])))
                                        : '—' ?>
                                </td>
                                <td>
                                    <span class="status <?= h($app['status']) ?>">
                                        <?= h($app['status']) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="empty">
                    You haven't applied for any elections yet.<br>
                    Click "Apply Now" to get started.
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Elections available for candidate applications -->
    <section class="section">
        <div class="section-heading">
            <h2>Available Elections</h2>
            <a href="apply.php" class="btn btn-light">View All</a>
        </div>

        <div class="election-grid">
            <?php if ($availableElections->num_rows > 0): ?>

                <?php while ($election = $availableElections->fetch_assoc()): ?>
                    <article class="election-card">

                        <div class="category">
                            <?= h($election['category'] ?: 'General Election') ?>
                        </div>

                        <h3><?= h($election['title']) ?></h3>

                        <p>
                            <?php
                            $description = $election['description'] ?? '';
                            echo h(
                                strlen($description) > 130
                                    ? substr($description, 0, 130) . '...'
                                    : ($description ?: 'No description available.')
                            );
                            ?>
                        </p>

                        <p>
                            Application deadline:
                            <strong>
                                <?= h(electionDate($election['end_date'])) ?>
                            </strong>
                        </p>

                        <a href="apply.php" class="btn btn-primary">
                            Apply for Election
                        </a>

                    </article>
                <?php endwhile; ?>

            <?php else: ?>

                <div class="panel empty" style="grid-column:1/-1;">
                    No new elections are available for application right now.
                </div>

            <?php endif; ?>
        </div>
    </section>

</main>

<footer>
    © <?= date('Y') ?> VotePortal. Secure and transparent voting.
</footer>

<script>
    const photoInput = document.getElementById('profileImageInput');
    const chooseButton = document.getElementById('choosePhotoBtn');
    const uploadButton = document.getElementById('uploadBtn');
    const photoForm = document.getElementById('photoForm');

    chooseButton.addEventListener('click', function () {
        photoInput.click();
    });

    photoInput.addEventListener('change', function () {
        if (!photoInput.files.length) {
            uploadButton.hidden = true;
            return;
        }

        const file = photoInput.files[0];
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];

        if (!allowedTypes.includes(file.type)) {
            alert('Please select a JPG, PNG or WEBP image.');
            photoInput.value = '';
            uploadButton.hidden = true;
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            alert('Image size must be 2 MB or less.');
            photoInput.value = '';
            uploadButton.hidden = true;
            return;
        }

        chooseButton.textContent = file.name;
        uploadButton.hidden = false;
    });

    photoForm.addEventListener('submit', function (event) {
        if (!photoInput.files.length) {
            event.preventDefault();
            alert('Please select a profile picture first.');
        }
    });
</script>

</body>
</html>
