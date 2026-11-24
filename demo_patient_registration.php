<?php
/**
 * demo_patient_registration.php
 *
 * Example Production ERP Patient Registration Form demonstrating the drop-in
 * integration of `aadhaar_sync_widget.php` and `aadhaar_sync.js`.
 */

// Simulated existing ERP patient submission handler
$savedMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_registration'])) {
    $patientName   = htmlspecialchars($_POST['patient_name'] ?? '');
    $patientDob    = htmlspecialchars($_POST['patient_dob'] ?? '');
    $patientGender = htmlspecialchars($_POST['patient_gender'] ?? '');
    $aadhaarNo     = htmlspecialchars($_POST['patient_aadhaar'] ?? '');
    $mobile        = htmlspecialchars($_POST['contact_no'] ?? '');
    $address       = htmlspecialchars($_POST['patient_address'] ?? '');
    $photoLength   = strlen($_POST['photo_base64'] ?? '');

    $savedMessage = "Successfully registered patient <strong>{$patientName}</strong> (DOB: {$patientDob}, Aadhaar: {$aadhaarNo}). Encoded photo received ({$photoLength} bytes).";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production ERP &mdash; Patient Registration</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light py-4">

<div class="container" style="max-width: 960px;">
    
    <!-- ERP Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 pb-2 border-bottom">
        <div>
            <h4 class="fw-bold mb-0 text-primary">🏥 Hospital Care ERP</h4>
            <span class="text-muted small">Outpatient Registration &amp; Electronic Health Records</span>
        </div>
        <span class="badge bg-success py-2 px-3">System Online &bull; v4.8</span>
    </div>

    <?php if ($savedMessage): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
            <?= $savedMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        
        <!-- Left Column: Primary Registration Form -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h5 class="card-title fw-bold mb-0">Patient Personal Details</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="" id="patient-registration-form">
                        
                        <div class="row g-3">
                            
                            <!-- Photo Preview Container -->
                            <div class="col-12 d-flex align-items-center gap-3 p-3 bg-light rounded border">
                                <img id="patient_photo_preview" src="images/default_user.svg" alt="Patient Photo" 
                                     class="rounded border bg-white shadow-sm" style="width: 80px; height: 100px; object-fit: cover;">
                                <div>
                                    <h6 class="fw-bold mb-1">Cardholder Photo</h6>
                                    <p class="text-muted small mb-0">Will be extracted automatically from the secure chip codestream.</p>
                                    <input type="hidden" id="patient_photo_base64" name="photo_base64">
                                </div>
                            </div>

                            <!-- Full Name -->
                            <div class="col-md-12">
                                <label for="patient_name" class="form-label fw-semibold small">Full Name as per Aadhaar <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="patient_name" name="patient_name" required placeholder="e.g. Ramesh Kumar">
                            </div>

                            <!-- Date of Birth (HTML5 YYYY-MM-DD) -->
                            <div class="col-md-6">
                                <label for="patient_dob" class="form-label fw-semibold small">Date of Birth <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="patient_dob" name="patient_dob" required>
                            </div>

                            <!-- Gender -->
                            <div class="col-md-6">
                                <label for="patient_gender" class="form-label fw-semibold small">Gender <span class="text-danger">*</span></label>
                                <select class="form-select" id="patient_gender" name="patient_gender" required>
                                    <option value="">Select Gender</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <!-- Aadhaar Number (Masked) -->
                            <div class="col-md-6">
                                <label for="patient_aadhaar" class="form-label fw-semibold small">Aadhaar Reference / Masked No.</label>
                                <input type="text" class="form-control font-monospace" id="patient_aadhaar" name="patient_aadhaar" placeholder="XXXX XXXX 1234">
                            </div>

                            <!-- Mobile Number -->
                            <div class="col-md-6">
                                <label for="contact_no" class="form-label fw-semibold small">Mobile / Contact Number</label>
                                <input type="text" class="form-control" id="contact_no" name="contact_no" placeholder="98XXXXXXXX">
                            </div>

                            <!-- Residential Address -->
                            <div class="col-12">
                                <label for="patient_address" class="form-label fw-semibold small">Full Residential Address</label>
                                <textarea class="form-control" id="patient_address" name="patient_address" rows="3" placeholder="House, Street, Area, City, State, PIN"></textarea>
                            </div>

                            <!-- Submit Action -->
                            <div class="col-12 mt-4 pt-2 border-top d-flex justify-content-end gap-2">
                                <button type="reset" class="btn btn-outline-secondary px-4">Clear Form</button>
                                <button type="submit" name="submit_registration" class="btn btn-primary px-4 fw-semibold">Save Patient Record</button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Right Column: Drop-in Plug-and-Play Scanner Component -->
        <div class="col-lg-5">
            <?php
            // DROP-IN INTEGRATION: Include the standalone widget component here
            include __DIR__ . '/aadhaar_sync_widget.php';
            ?>
        </div>

    </div>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>

