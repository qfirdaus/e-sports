<?php
/**
 * Settings Page - Comprehensive UI/UX Design
 * Tournament Management System Settings
 */
require_once __DIR__ . '/../config.php';

// Check access before rendering page
if (!defined('SKIP_AUTH_CHECK')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../config/rbac.php';
    
    // Session should already be started in config.php, but ensure it's started
    if (session_status() === PHP_SESSION_NONE) {
        Session::start();
    }
    
    $auth = getAuth();
    $rbac = getRBAC();
    
    // Check access to settings page (requires ADMIN)
    $rbac->requirePageAccess('pages/settings.php');
    
    // If we reach here and user doesn't have access, stop execution
    if (!$rbac->hasPageAccess('pages/settings.php')) {
        // Access denied - redirect or show error
        if (!headers_sent()) {
            header('Location: ' . url('pages/access-denied.php'));
            exit;
        } else {
            // Headers already sent - output redirect and stop
            $deniedUrl = url('pages/access-denied.php');
            echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($deniedUrl) . '"></head><body><script>window.location.href="' . htmlspecialchars($deniedUrl) . '";</script></body></html>';
            exit;
        }
    }
}

$page_title = 'Settings';

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/settings.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/settings.css'); ?>">
<div class="w-100 px-3 settings-view">
    <!-- Page Header -->
    <div class="row mb-30">
        <div class="col-12">
            <div class="page-heading">
                <div class="row align-items-center">
                    <div class="col">
                        <div class="settings-eyebrow">SAM 2026 &middot; SYSTEM CONFIGURATION</div><h3>System Settings</h3><p class="settings-description">Configure and manage championship system settings.</p>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="button button-outline button-secondary mr-10" onclick="resetAllSettings()">
                            <i class="zmdi zmdi-refresh mr-5"></i> Reset
                        </button>
                        <button type="button" class="button button-primary" onclick="saveAllSettings(this)">
                            <i class="zmdi zmdi-save mr-5"></i> Save All
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Navigation Tabs -->
    <div class="row mb-30">
        <div class="col-12">
            <div class="box">
                <div class="box-body">
                    <ul class="nav nav-tabs mb-15" id="settingsTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <a class="nav-link active" id="general-tab" data-bs-toggle="tab" href="#general" role="tab">
                                <i class="zmdi zmdi-settings mr-5"></i> General
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" id="tournament-tab" data-bs-toggle="tab" href="#tournament" role="tab">
                                <i class="zmdi zmdi-trophy mr-5"></i> Championship
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" id="registration-tab" data-bs-toggle="tab" href="#registration" role="tab">
                                <i class="zmdi zmdi-account-add mr-5"></i> Registration
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" id="notification-tab" data-bs-toggle="tab" href="#notification" role="tab">
                                <i class="zmdi zmdi-notifications mr-5"></i> Notifications
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" id="user-tab" data-bs-toggle="tab" href="#user" role="tab">
                                <i class="zmdi zmdi-accounts mr-5"></i> Users & Access
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" id="display-tab" data-bs-toggle="tab" href="#display" role="tab">
                                <i class="zmdi zmdi-palette mr-5"></i> Display
                            </a>
                        </li>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" id="system-tab" data-bs-toggle="tab" href="#system" role="tab">
                                <i class="zmdi zmdi-devices mr-5"></i> System
                            </a>
                        </li>
                    </ul>

                    <!-- Settings Content -->
                    <div class="tab-content" id="settingsTabContent">
        
        <!-- Tab 1: General Settings -->
        <div class="tab-pane fade show active" id="general" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-info-outline me-2"></i> System Information</h4>
                        </div>
                        <div class="box-body">
                            <form id="generalSettingsForm">
                                <div class="mb-3">
                                    <label for="siteName" class="form-label">System Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="siteName" name="siteName" 
                                           value="<?php echo SITE_NAME; ?>" required>
                                    <div class="form-text">Name displayed in the header and page title</div>
                                </div>

                                <div class="mb-3">
                                    <label for="siteFullName" class="form-label">Full Name</label>
                                    <input type="text" class="form-control" id="siteFullName" name="siteFullName" 
                                           value="<?php echo SITE_FULL_NAME; ?>">
                                    <div class="form-text">Full system name (e.g. Sukan Asasi Malaysia)</div>
                                </div>

                                <div class="mb-3">
                                    <label for="siteDescription" class="form-label">System Description</label>
                                    <textarea class="form-control" id="siteDescription" name="siteDescription" rows="3"><?php echo SITE_DESCRIPTION; ?></textarea>
                                    <div class="form-text">A short description of the system</div>
                                </div>

                                <div class="mb-3">
                                    <label for="siteEmail" class="form-label">System Email <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="siteEmail" name="siteEmail" 
                                           placeholder="admin@sam2026.gov.my" required>
                                    <div class="form-text">Primary email address for official communication</div>
                                </div>

                                <div class="mb-3">
                                    <label for="sitePhone" class="form-label">System Phone</label>
                                    <input type="tel" class="form-control" id="sitePhone" name="sitePhone" 
                                           placeholder="03-12345678">
                                </div>

                                <div class="mb-3">
                                    <label for="siteAddress" class="form-label">System Address</label>
                                    <textarea class="form-control" id="siteAddress" name="siteAddress" rows="3" 
                                              placeholder="Enter the office address"></textarea>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-globe me-2"></i> Locale & Time Zone</h4>
                        </div>
                        <div class="box-body">
                            <form id="localeSettingsForm">
                                <div class="mb-3">
                                    <label for="timezone" class="form-label">Time Zone <span class="text-danger">*</span></label>
                                    <select class="form-select" id="timezone" name="timezone" required>
                                        <option value="Asia/Kuala_Lumpur" selected>Asia/Kuala_Lumpur (GMT+8)</option>
                                        <option value="UTC">UTC (GMT+0)</option>
                                        <option value="Asia/Singapore">Asia/Singapore (GMT+8)</option>
                                        <option value="Asia/Jakarta">Asia/Jakarta (GMT+7)</option>
                                        <option value="Asia/Bangkok">Asia/Bangkok (GMT+7)</option>
                                    </select>
                                    <div class="form-text">Time zone for all dates and times in the system</div>
                                </div>

                                <div class="mb-3">
                                    <label for="language" class="form-label">Interface Language</label>
                                    <select class="form-select" id="language" name="language">
                                        <option value="en" selected>English</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="dateFormat" class="form-label">Date Format</label>
                                    <select class="form-select" id="dateFormat" name="dateFormat">
                                        <option value="d/m/Y" selected>DD/MM/YYYY (e.g. 30/01/2026)</option>
                                        <option value="Y-m-d">YYYY-MM-DD (e.g. 2026-01-30)</option>
                                        <option value="d M Y">DD MMM YYYY (e.g. 30 Jan 2026)</option>
                                        <option value="j F Y">D MMMM YYYY (e.g. 30 January 2026)</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="timeFormat" class="form-label">Time Format</label>
                                    <select class="form-select" id="timeFormat" name="timeFormat">
                                        <option value="H:i" selected>24-hour (e.g. 14:30)</option>
                                        <option value="h:i A">12-hour (e.g. 2:30 PM)</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="currency" class="form-label">Currency</label>
                                    <select class="form-select" id="currency" name="currency">
                                        <option value="MYR" selected>MYR (RM)</option>
                                        <option value="USD">USD ($)</option>
                                        <option value="SGD">SGD (S$)</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 2: Tournament Settings -->
        <div class="tab-pane fade" id="tournament" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-trophy me-2"></i> Championship Details</h4>
                        </div>
                        <div class="box-body">
                            <form id="tournamentSettingsForm">
                                <div class="mb-3">
                                    <label for="tournamentName" class="form-label">Championship Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="tournamentName" name="tournamentName" 
                                           value="Sukan Asasi Malaysia 2026" required>
                                </div>

                                <div class="mb-3">
                                    <label for="tournamentEdition" class="form-label">Championship Edition</label>
                                    <input type="text" class="form-control" id="tournamentEdition" name="tournamentEdition" 
                                           value="9th Edition" placeholder="e.g. 9th Edition">
                                </div>

                                <div class="mb-3">
                                    <label for="tournamentStartDate" class="form-label">Start Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="tournamentStartDate" name="tournamentStartDate" 
                                           value="2026-01-30" required>
                                </div>

                                <div class="mb-3">
                                    <label for="tournamentEndDate" class="form-label">End Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" id="tournamentEndDate" name="tournamentEndDate" 
                                           value="2026-02-01" required>
                                </div>

                                <div class="mb-3">
                                    <label for="tournamentStatus" class="form-label">Championship Status</label>
                                    <select class="form-select" id="tournamentStatus" name="tournamentStatus">
                                        <option value="upcoming" selected>Upcoming</option>
                                        <option value="ongoing">Ongoing</option>
                                        <option value="completed">Completed</option>
                                        <option value="cancelled">Cancelled</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-pin me-2"></i> Location & Venue</h4>
                        </div>
                        <div class="box-body">
                            <form id="venueSettingsForm">
                                <div class="mb-3">
                                    <label for="mainVenue" class="form-label">Main Venue <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="mainVenue" name="mainVenue" 
                                           value="UPNM Kem Sungai Besi" required>
                                </div>

                                <div class="mb-3">
                                    <label for="venueAddress" class="form-label">Venue Address</label>
                                    <textarea class="form-control" id="venueAddress" name="venueAddress" rows="3" 
                                              placeholder="Enter the full venue address"></textarea>
                                </div>

                                <div class="mb-3">
                                    <label for="venueCity" class="form-label">City</label>
                                    <input type="text" class="form-control" id="venueCity" name="venueCity" 
                                           value="Kuala Lumpur">
                                </div>

                                <div class="mb-3">
                                    <label for="venueState" class="form-label">State</label>
                                    <select class="form-select" id="venueState" name="venueState">
                                        <option value="Kuala Lumpur" selected>Kuala Lumpur</option>
                                        <option value="Selangor">Selangor</option>
                                        <option value="Putrajaya">Putrajaya</option>
                                        <!-- Add more states -->
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="venueCapacity" class="form-label">Venue Capacity</label>
                                    <input type="number" class="form-control" id="venueCapacity" name="venueCapacity" 
                                           placeholder="e.g. 5000" min="0">
                                    <div class="form-text">Total spectator capacity (if applicable)</div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Registration Settings -->
        <div class="tab-pane fade" id="registration" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-calendar me-2"></i> Registration Period</h4>
                        </div>
                        <div class="box-body">
                            <form id="registrationPeriodForm">
                                <div class="mb-3">
                                    <label for="regOpenDate" class="form-label">Registration Opening Date <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="regOpenDate" name="regOpenDate" required>
                                </div>

                                <div class="mb-3">
                                    <label for="regCloseDate" class="form-label">Registration Closing Date <span class="text-danger">*</span></label>
                                    <input type="datetime-local" class="form-control" id="regCloseDate" name="regCloseDate" required>
                                </div>

                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="regAutoClose" name="regAutoClose" checked>
                                        <i class="lever"></i>
                                        <span class="text">Close registration automatically after the deadline</span>
                                    </label>
                                </div>

                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="regAllowLate" name="regAllowLate">
                                        <i class="lever"></i>
                                        <span class="text">Allow late registration (subject to approval)</span>
                                    </label>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-money me-2"></i> Fees & Payments</h4>
                        </div>
                        <div class="box-body">
                            <form id="feeSettingsForm">
                                <div class="mb-3">
                                    <label for="regFeePerContingent" class="form-label">Contingent Registration Fee</label>
                                    <div class="input-group">
                                        <span class="input-group-text">RM</span>
                                        <input type="number" class="form-control" id="regFeePerContingent" name="regFeePerContingent" 
                                               placeholder="0.00" step="0.01" min="0">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="regFeePerAthlete" class="form-label">Athlete Registration Fee</label>
                                    <div class="input-group">
                                        <span class="input-group-text">RM</span>
                                        <input type="number" class="form-control" id="regFeePerAthlete" name="regFeePerAthlete" 
                                               placeholder="0.00" step="0.01" min="0">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="regFeeRequired" name="regFeeRequired">
                                        <i class="lever"></i>
                                        <span class="text">Registration fee required</span>
                                    </label>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-alert-triangle me-2"></i> Limits & Restrictions</h4>
                        </div>
                        <div class="box-body">
                            <form id="limitSettingsForm">
                                <div class="mb-3">
                                    <label for="maxContingents" class="form-label">Maximum Contingents</label>
                                    <input type="number" class="form-control" id="maxContingents" name="maxContingents" 
                                           placeholder="e.g. 50" min="1">
                                    <div class="form-text">Leave blank for no limit</div>
                                </div>

                                <div class="mb-3">
                                    <label for="maxAthletesPerContingent" class="form-label">Maximum Athletes per Contingent</label>
                                    <input type="number" class="form-control" id="maxAthletesPerContingent" name="maxAthletesPerContingent" 
                                           placeholder="e.g. 100" min="1">
                                </div>

                                <div class="mb-3">
                                    <label for="maxSportsPerContingent" class="form-label">Maximum Sports per Contingent</label>
                                    <input type="number" class="form-control" id="maxSportsPerContingent" name="maxSportsPerContingent" 
                                           placeholder="e.g. 20" min="1">
                                </div>

                                <div class="mb-3">
                                    <label for="minAthletesPerContingent" class="form-label">Minimum Athletes per Contingent</label>
                                    <input type="number" class="form-control" id="minAthletesPerContingent" name="minAthletesPerContingent" 
                                           placeholder="e.g. 10" min="1">
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-file-text me-2"></i> Requirements & Documents</h4>
                        </div>
                        <div class="box-body">
                            <form id="requirementSettingsForm">
                                <div class="mb-3">
                                    <label for="requiredDocuments" class="form-label">Required Documents</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="reqDoc1" name="requiredDocuments[]" value="surat_rasmi" checked>
                                        <label class="form-check-label" for="reqDoc1">Official Institution Letter</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="reqDoc2" name="requiredDocuments[]" value="senarai_atlet">
                                        <label class="form-check-label" for="reqDoc2">Athlete List</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="reqDoc3" name="requiredDocuments[]" value="sijil_kesihatan">
                                        <label class="form-check-label" for="reqDoc3">Health Certificate</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="reqDoc4" name="requiredDocuments[]" value="foto">
                                        <label class="form-check-label" for="reqDoc4">Athlete Photo</label>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="registrationTerms" class="form-label">Registration Terms & Conditions</label>
                                    <textarea class="form-control" id="registrationTerms" name="registrationTerms" rows="5" 
                                              placeholder="Enter the registration terms and conditions..."></textarea>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 4: Notification Settings -->
        <div class="tab-pane fade" id="notification" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-email me-2"></i> Email Notifications</h4>
                        </div>
                        <div class="box-body">
                            <form id="emailNotificationForm">
                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="emailEnabled" name="emailEnabled" checked>
                                        <i class="lever"></i>
                                        <span class="text">Enable email notifications</span>
                                    </label>
                                </div>

                                <div class="mb-3">
                                    <label for="smtpHost" class="form-label">SMTP Host</label>
                                    <input type="text" class="form-control" id="smtpHost" name="smtpHost" 
                                           placeholder="smtp.gmail.com">
                                </div>

                                <div class="mb-3">
                                    <label for="smtpPort" class="form-label">SMTP Port</label>
                                    <input type="number" class="form-control" id="smtpPort" name="smtpPort" 
                                           value="587" placeholder="587">
                                </div>

                                <div class="mb-3">
                                    <label for="smtpUsername" class="form-label">SMTP Username</label>
                                    <input type="text" class="form-control" id="smtpUsername" name="smtpUsername">
                                </div>

                                <div class="mb-3">
                                    <label for="smtpPassword" class="form-label">SMTP Password</label>
                                    <input type="password" class="form-control" id="smtpPassword" name="smtpPassword">
                                </div>

                                <div class="mb-3">
                                    <label for="emailFrom" class="form-label">Sender Email</label>
                                    <input type="email" class="form-control" id="emailFrom" name="emailFrom" 
                                           placeholder="noreply@sam2026.gov.my">
                                </div>

                                <div class="mb-3">
                                    <label for="emailFromName" class="form-label">Sender Name</label>
                                    <input type="text" class="form-control" id="emailFromName" name="emailFromName" 
                                           value="SAM 2026">
                                </div>
                                <div class="mb-3">
                                    <button type="button" class="button button-outline button-info" onclick="testSmtp(this)">
                                        <i class="zmdi zmdi-email mr-5"></i> Test SMTP
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-phone me-2"></i> SMS Notifications</h4>
                        </div>
                        <div class="box-body">
                            <form id="smsNotificationForm">
                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="smsEnabled" name="smsEnabled">
                                        <i class="lever"></i>
                                        <span class="text">Enable SMS notifications</span>
                                    </label>
                                </div>

                                <div class="mb-3">
                                    <label for="smsProvider" class="form-label">SMS Provider</label>
                                    <select class="form-select" id="smsProvider" name="smsProvider">
                                        <option value="">Select a provider...</option>
                                        <option value="twilio">Twilio</option>
                                        <option value="nexmo">Vonage (Nexmo)</option>
                                        <option value="custom">Custom</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="smsApiKey" class="form-label">API Key</label>
                                    <input type="text" class="form-control" id="smsApiKey" name="smsApiKey">
                                </div>

                                <div class="mb-3">
                                    <label for="smsSenderId" class="form-label">Sender ID</label>
                                    <input type="text" class="form-control" id="smsSenderId" name="smsSenderId" 
                                           placeholder="SAM2026">
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-notifications me-2"></i> Notification Types</h4>
                        </div>
                        <div class="box-body">
                            <form id="notificationTypesForm">
                                <div class="mb-3">
                                    <label class="form-label">Enable notifications for:</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notifRegistration" name="notificationTypes[]" value="registration" checked>
                                        <label class="form-check-label" for="notifRegistration">New Registration</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notifResults" name="notificationTypes[]" value="results" checked>
                                        <label class="form-check-label" for="notifResults">Competition Results</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notifSchedule" name="notificationTypes[]" value="schedule" checked>
                                        <label class="form-check-label" for="notifSchedule">Schedule Changes</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="notifReminder" name="notificationTypes[]" value="reminder">
                                        <label class="form-check-label" for="notifReminder">Reminder</label>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 5: User & Access Settings -->
        <div class="tab-pane fade" id="user" role="tabpanel">
            <?php require_once __DIR__ . '/../includes/rbac_management_ui.php'; ?>
            
            <div class="row">
                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-shield-security me-2"></i> Security</h4>
                        </div>
                        <div class="box-body">
                            <form id="securitySettingsForm">
                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="twoFactorAuth" name="twoFactorAuth">
                                        <i class="lever"></i>
                                        <span class="text">Enable Two-Factor Authentication (2FA)</span>
                                    </label>
                                </div>

                                <div class="mb-3">
                                    <label for="sessionTimeout" class="form-label">Session Timeout (minutes)</label>
                                    <input type="number" class="form-control" id="sessionTimeout" name="sessionTimeout" 
                                           value="30" min="5" max="480">
                                </div>

                                <div class="mb-3">
                                    <label for="passwordMinLength" class="form-label">Minimum Password Length</label>
                                    <input type="number" class="form-control" id="passwordMinLength" name="passwordMinLength" 
                                           value="8" min="6" max="20">
                                </div>

                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="passwordRequireUppercase" name="passwordRequireUppercase" checked>
                                        <i class="lever"></i>
                                        <span class="text">Require an uppercase letter</span>
                                    </label>
                                </div>

                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="passwordRequireNumber" name="passwordRequireNumber" checked>
                                        <i class="lever"></i>
                                        <span class="text">Require a number</span>
                                    </label>
                                </div>

                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="passwordRequireSpecial" name="passwordRequireSpecial">
                                        <i class="lever"></i>
                                        <span class="text">Require a special character</span>
                                    </label>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-lock me-2"></i> Access Permissions</h4>
                        </div>
                        <div class="box-body">
                            <form id="permissionsForm">
                                <div class="mb-3">
                                    <label class="form-label">Contingent Permissions:</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="permViewResults" name="permissions[]" value="view_results" checked>
                                        <label class="form-check-label" for="permViewResults">View Results</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="permEditOwnData" name="permissions[]" value="edit_own_data" checked>
                                        <label class="form-check-label" for="permEditOwnData">Edit Own Details</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="permUploadDocuments" name="permissions[]" value="upload_documents" checked>
                                        <label class="form-check-label" for="permUploadDocuments">Upload Documents</label>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Judge Permissions:</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="permEnterResults" name="permissions[]" value="enter_results" checked>
                                        <label class="form-check-label" for="permEnterResults">Enter Results</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="permViewSchedule" name="permissions[]" value="view_schedule" checked>
                                        <label class="form-check-label" for="permViewSchedule">View Schedule</label>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 6: Display Settings -->
        <div class="tab-pane fade" id="display" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-palette me-2"></i> Logo & Icons</h4>
                        </div>
                        <div class="box-body">
                            <form id="logoSettingsForm">
                                <div class="mb-3">
                                    <label for="headerLogo" class="form-label">Header Logo</label>
                                    <input type="file" class="form-control" id="headerLogo" name="headerLogo" accept="image/*">
                                    <input type="hidden" id="headerLogoPath" name="headerLogoPath" value="<?php echo htmlspecialchars((string)app_setting('logoSettingsForm.headerLogoPath', LOGO_HEADER), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="form-text">Logo displayed in the header (recommended: 180 × 180 px)</div>
                                    <div class="mt-2">
                                        <img id="headerLogoPreview" src="<?php echo logo(LOGO_HEADER); ?>" alt="Current Logo" class="img-thumbnail" style="max-height: 60px;">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="favicon" class="form-label">Favicon</label>
                                    <input type="file" class="form-control" id="favicon" name="favicon" accept="image/*">
                                    <input type="hidden" id="faviconPath" name="faviconPath" value="<?php echo htmlspecialchars((string)app_setting('logoSettingsForm.faviconPath', LOGO_FAVICON), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="form-text">Browser tab icon (recommended: 32 × 32 px or 16 × 16 px)</div>
                                    <div class="mt-2">
                                        <img id="faviconPreview" src="<?php echo logo(LOGO_FAVICON); ?>" alt="Current Favicon" class="img-thumbnail" style="max-height: 40px;">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="backgroundImage" class="form-label">Background Image</label>
                                    <input type="file" class="form-control" id="backgroundImage" name="backgroundImage" accept="image/*">
                                    <input type="hidden" id="backgroundImagePath" name="backgroundImagePath" value="<?php echo htmlspecialchars((string)app_setting('logoSettingsForm.backgroundImagePath', ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="form-text">Background image for all pages</div>
                                    <?php $bgPath = (string)app_setting('logoSettingsForm.backgroundImagePath', ''); ?>
                                    <?php if ($bgPath !== ''): ?>
                                    <div class="mt-2">
                                        <img id="backgroundImagePreview" src="<?php echo url('assets/img/backgrounds/' . $bgPath); ?>" alt="Current Background" class="img-thumbnail" style="max-height: 60px;">
                                    </div>
                                    <?php else: ?>
                                    <div class="mt-2">
                                        <img id="backgroundImagePreview" src="" alt="Current Background" class="img-thumbnail d-none" style="max-height: 60px;">
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-palette me-2"></i> Theme & Colours</h4>
                        </div>
                        <div class="box-body">
                            <form id="themeSettingsForm">
                                <div class="mb-3">
                                    <label for="primaryColor" class="form-label">Primary Colour</label>
                                    <input type="color" class="form-control form-control-color" id="primaryColor" name="primaryColor" 
                                           value="#0d6efd" title="Select a primary colour">
                                </div>

                                <div class="mb-3">
                                    <label for="themeMode" class="form-label">Theme Mode</label>
                                    <select class="form-select" id="themeMode" name="themeMode">
                                        <option value="light" selected>Light</option>
                                        <option value="dark">Dark</option>
                                        <option value="auto">Auto (System Default)</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="navbarStyle" class="form-label">Navigation Bar Style</label>
                                    <select class="form-select" id="navbarStyle" name="navbarStyle">
                                        <option value="dark" selected>Dark</option>
                                        <option value="light">Light</option>
                                        <option value="primary">Primary Colour</option>
                                    </select>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 7: System Settings -->
        <div class="tab-pane fade" id="system" role="tabpanel">
            <div class="row">
                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-download me-2"></i> Backup & Export</h4>
                        </div>
                        <div class="box-body">
                            <form id="backupSettingsForm">
                                <div class="mb-3">
                                    <label for="autoBackup" class="form-label">Automatic Backup</label>
                                    <select class="form-select" id="autoBackup" name="autoBackup">
                                        <option value="disabled">Inactive</option>
                                        <option value="daily" selected>Daily</option>
                                        <option value="weekly">Weekly</option>
                                        <option value="monthly">Monthly</option>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="backupRetention" class="form-label">Backup Retention (days)</label>
                                    <input type="number" class="form-control" id="backupRetention" name="backupRetention" 
                                           value="30" min="1" max="365">
                                </div>

                                <div class="mb-3">
                                    <button type="button" class="button button-outline button-primary mr-10" onclick="createBackup(this)">
                                        <i class="zmdi zmdi-save mr-5"></i> Buat Backup Sekarang
                                    </button>
                                    <button type="button" class="button button-outline button-success" onclick="exportData(this)">
                                        <i class="zmdi zmdi-download mr-5"></i> Export Data
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-wrench me-2"></i> Maintenance Mode</h4>
                        </div>
                        <div class="box-body">
                            <form id="maintenanceForm">
                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="maintenanceMode" name="maintenanceMode">
                                        <i class="lever"></i>
                                        <span class="text">Enable Maintenance Mode</span>
                                    </label>
                                    <div class="form-text">The system will be unavailable to regular users</div>
                                </div>

                                <div class="mb-3">
                                    <label for="maintenanceMessage" class="form-label">Maintenance Message</label>
                                    <textarea class="form-control" id="maintenanceMessage" name="maintenanceMessage" rows="3" 
                                              placeholder="The system is undergoing maintenance. Please try again later."></textarea>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="box mb-30">
                        <div class="box-head">
                            <h4 class="title"><i class="zmdi zmdi-format-list-bulleted me-2"></i> Log & Audit</h4>
                        </div>
                        <div class="box-body">
                            <form id="logSettingsForm">
                                <div class="mb-3">
                                    <label class="adomx-switch">
                                        <input type="checkbox" id="enableAuditLog" name="enableAuditLog" checked>
                                        <i class="lever"></i>
                                        <span class="text">Enable Audit Logging</span>
                                    </label>
                                </div>

                                <div class="mb-3">
                                    <label for="logRetention" class="form-label">Log Retention (days)</label>
                                    <input type="number" class="form-control" id="logRetention" name="logRetention" 
                                           value="90" min="1" max="365">
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Log Types to Record:</label>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="logLogin" name="logTypes[]" value="login" checked>
                                        <label class="form-check-label" for="logLogin">Sign-In / Sign-Out</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="logDataChange" name="logTypes[]" value="data_change" checked>
                                        <label class="form-check-label" for="logDataChange">Data Changes</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="logSettings" name="logTypes[]" value="settings" checked>
                                        <label class="form-check-label" for="logSettings">Settings Changes</label>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <button type="button" class="button button-outline button-info mr-10" onclick="viewLogs(this)">
                                        <i class="zmdi zmdi-format-list-bulleted mr-5"></i> View Log
                                    </button>
                                    <button type="button" class="button button-outline button-warning" onclick="clearLogs(this)">
                                        <i class="zmdi zmdi-delete mr-5"></i> Clear Log
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>

</div>

<script>
const SETTINGS_API_URL = <?php echo json_encode(url('api/settings.php')); ?>;
const HAS_SWAL = () => !!(window.Swal && typeof window.Swal.fire === 'function');

function uiSuccess(title, text) {
    if (HAS_SWAL()) return Swal.fire({ icon: 'success', title, text });
    if (typeof Toast !== 'undefined' && typeof Toast.success === 'function') {
        Toast.success(text || title || 'Success');
    } else {
        console.log('[SETTINGS][success] ' + (text || title || 'Success'));
    }
    return Promise.resolve();
}

function uiError(title, text) {
    if (HAS_SWAL()) return Swal.fire({ icon: 'error', title, text });
    if (typeof Toast !== 'undefined' && typeof Toast.error === 'function') {
        Toast.error((title ? title + ': ' : '') + (text || 'Error.'));
    } else {
        console.error('[SETTINGS][error] ' + (title ? title + ': ' : '') + (text || 'Error.'));
    }
    return Promise.resolve();
}

function uiInfo(title, text) {
    if (HAS_SWAL()) return Swal.fire({ icon: 'info', title, text });
    if (typeof Toast !== 'undefined' && typeof Toast.info === 'function') {
        Toast.info(text || title || 'Makluman');
    } else {
        console.log('[SETTINGS][info] ' + (text || title || 'Makluman'));
    }
    return Promise.resolve();
}

async function uiConfirm(title, text, confirmText) {
    if (HAS_SWAL()) {
        const res = await Swal.fire({
            icon: 'warning',
            title: title || 'Confirmation',
            text: text || '',
            showCancelButton: true,
            confirmButtonText: confirmText || 'Yes',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        });
        return !!(res && res.isConfirmed);
    }
    if (typeof Toast !== 'undefined' && typeof Toast.info === 'function') {
        Toast.info(text || title || 'Confirmation required.');
    }
    return false;
}

function uiLoading(title, text) {
    if (!HAS_SWAL()) return;
    Swal.fire({
        title: title || 'Please wait',
        text: text || 'Processing...',
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        didOpen: () => Swal.showLoading()
    });
}

function uiCloseLoading() {
    if (HAS_SWAL()) Swal.close();
}

function setBtnLoading(btn, loadingText) {
    if (!btn) return () => {};
    const original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> ' + (loadingText || 'Memproses...');
    return () => {
        btn.disabled = false;
        btn.innerHTML = original;
    };
}

function collectFormValues(form) {
    const data = {};
    const fields = form.querySelectorAll('input[name], select[name], textarea[name]');
    fields.forEach(el => {
        if (el.disabled) return;
        const name = el.name;
        if (!name) return;
        if (el.type === 'file') return;

        if (el.type === 'checkbox') {
            if (name.endsWith('[]')) {
                if (!Array.isArray(data[name])) data[name] = [];
                if (el.checked) data[name].push(el.value);
            } else {
                data[name] = !!el.checked;
            }
            return;
        }

        if (el.type === 'radio') {
            if (el.checked) data[name] = el.value;
            return;
        }

        data[name] = el.value;
    });
    return data;
}

function applyFormValues(form, values) {
    if (!form || !values || typeof values !== 'object') return;
    const fields = form.querySelectorAll('input[name], select[name], textarea[name]');
    fields.forEach(el => {
        const name = el.name;
        if (!name || !(name in values)) return;
        if (el.type === 'file') return;

        const incoming = values[name];
        if (el.type === 'checkbox') {
            if (name.endsWith('[]')) {
                const arr = Array.isArray(incoming) ? incoming.map(String) : [];
                el.checked = arr.includes(String(el.value));
            } else {
                el.checked = !!incoming;
            }
            return;
        }

        if (el.type === 'radio') {
            el.checked = (String(el.value) === String(incoming));
            return;
        }

        el.value = incoming ?? '';
    });
}

async function loadAllSettings() {
    try {
        const res = await fetch(SETTINGS_API_URL, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) return;
        const json = await res.json();
        if (!json || !json.ok || !json.data || typeof json.data !== 'object') return;

        Object.keys(json.data).forEach(formId => {
            const form = document.getElementById(formId);
            if (!form) return;
            applyFormValues(form, json.data[formId]);
        });
    } catch (e) {
        console.error('Load settings error:', e);
    }
}

// Save all settings
async function saveAllSettings(saveBtn) {
    // Collect all form data
    const allForms = document.querySelectorAll('#settingsTabContent form');
    const allData = {};
    
    allForms.forEach(form => {
        const formId = form.id;
        allData[formId] = collectFormValues(form);
    });
    
    // Show loading
    if (!saveBtn) return;
    const doneBtn = setBtnLoading(saveBtn, 'Saving...');
    uiLoading('Save Settings', 'Saving all settings...');

    try {
        const res = await fetch(SETTINGS_API_URL, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ data: allData })
        });
        const json = await res.json();
        if (!res.ok || !json || !json.ok) {
            let msg = (json && json.error) ? json.error : 'Unable to save settings.';
            if (json && Array.isArray(json.errors) && json.errors.length) {
                msg += ' ' + json.errors.slice(0, 3).join(' | ');
            }
            throw new Error(msg);
        }
        uiCloseLoading();
        await uiSuccess('Success', json.message || 'Settings saved.');
    } catch (err) {
        console.error('Save settings error:', err);
        uiCloseLoading();
        await uiError('Error', (err && err.message) ? err.message : 'Unable to save settings. Please try again.');
    } finally {
        doneBtn();
    }
}

// Reset all settings
function resetAllSettings() {
    uiConfirm('Reset Settings', 'Reset all settings to their defaults?', 'Reset').then(ok => {
        if (ok) location.reload();
    });
}

async function testSmtp(btn) {
    const smtpHost = (document.getElementById('smtpHost')?.value || '').trim();
    const smtpPort = parseInt(document.getElementById('smtpPort')?.value || '0', 10);
    if (!smtpHost || !smtpPort) {
        uiInfo('Incomplete Information', 'Please enter the SMTP host and port first.');
        return;
    }
    const doneBtn = setBtnLoading(btn, 'Testing...');
    uiLoading('SMTP Test', 'Testing the SMTP connection...');
    try {
        const res = await fetch(SETTINGS_API_URL + '?action=test_smtp', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ smtpHost, smtpPort })
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || 'SMTP test failed.');
        uiCloseLoading();
        await uiSuccess('Success', json.message || 'SMTP tested successfully.');
    } catch (e) {
        uiCloseLoading();
        await uiError('SMTP Test Failed', e.message || 'Unknown error');
    } finally {
        doneBtn();
    }
}

// Create backup
async function createBackup(btn) {
    const doneBtn = setBtnLoading(btn, 'Membuat backup...');
    uiLoading('System Backup', 'Generating the backup file...');
    try {
        const res = await fetch(SETTINGS_API_URL + '?action=create_backup', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || 'Unable to create a backup.');
        if (json.download_url) {
            window.open(json.download_url, '_blank');
        }
        uiCloseLoading();
        await uiSuccess('Success', json.message || 'Backup created successfully.');
    } catch (e) {
        uiCloseLoading();
        await uiError('Backup Failed', e.message || 'Unknown error');
    } finally {
        doneBtn();
    }
}

// Export data
async function exportData(btn) {
    const doneBtn = setBtnLoading(btn, 'Mengeksport...');
    uiLoading('Export Data', 'Generating the export file...');
    try {
        const res = await fetch(SETTINGS_API_URL + '?action=export_data', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || 'Unable to export data.');
        if (json.download_url) {
            window.open(json.download_url, '_blank');
        }
        uiCloseLoading();
        await uiSuccess('Success', json.message || 'Data exported successfully.');
    } catch (e) {
        uiCloseLoading();
        await uiError('Export Failed', e.message || 'Unknown error');
    } finally {
        doneBtn();
    }
}

// View logs
async function viewLogs(btn) {
    const doneBtn = setBtnLoading(btn, 'Memuat log...');
    uiLoading('Log Audit', 'Loading the latest logs...');
    try {
        const res = await fetch(SETTINGS_API_URL + '?action=view_logs', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || 'Unable to fetch logs.');
        const rows = Array.isArray(json.rows) ? json.rows : [];
        if (!rows.length) {
            uiCloseLoading();
            await uiInfo('Log Audit', 'No logs to display.');
            return;
        }
        const safe = (v) => String(v == null ? '' : v)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
        const body = rows.slice(0, 100).map(r => (
            `<tr>
                <td>${safe(r.id)}</td>
                <td>${safe(r.created_at)}</td>
                <td>${safe(r.action)}</td>
                <td>${safe(r.description || '')}</td>
            </tr>`
        )).join('');
        uiCloseLoading();
        if (HAS_SWAL()) {
            await Swal.fire({
                title: 'Audit Log (latest 100 entries)',
                width: '900px',
                html: `<div style="max-height:55vh;overflow:auto;text-align:left">
                    <table class="table table-sm table-bordered mb-0">
                        <thead><tr><th style="width:70px">ID</th><th style="width:170px">Date</th><th style="width:140px">Actions</th><th>Description</th></tr></thead>
                        <tbody>${body}</tbody>
                    </table>
                </div>`,
                showConfirmButton: true,
                confirmButtonText: 'Close'
            });
        } else {
            const preview = rows.slice(0, 20).map(r => `#${r.id} [${r.created_at}] ${r.action} - ${r.description || ''}`).join('\n');
            console.log('[SETTINGS][logs]\\n' + preview);
            await uiInfo('Log Audit', 'The log has been displayed in the browser console.');
        }
    } catch (e) {
        uiCloseLoading();
        await uiError('Unable to View Logs', e.message || 'Unknown error');
    } finally {
        doneBtn();
    }
}

// Clear logs
async function clearLogs(btn) {
    const ok = await uiConfirm('Clear Log', 'Clear old logs according to the retention period?', 'Continue');
    if (!ok) return;

    const doneBtn = setBtnLoading(btn, 'Membersihkan...');
    uiLoading('Clear Log', 'Deleting old logs...');
    try {
        const retention = parseInt(document.getElementById('logRetention')?.value || '90', 10);
        const res = await fetch(SETTINGS_API_URL + '?action=clear_logs', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ days: retention })
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.error || 'Unable to clear logs.');
        uiCloseLoading();
        await uiSuccess('Success', (json.message || 'Logs cleared successfully.') + ` (deleted: ${json.deleted || 0})`);
    } catch (e) {
        uiCloseLoading();
        await uiError('Unable to Clear Logs', e.message || 'Unknown error');
    } finally {
        doneBtn();
    }
}

function uploadSettingsAssetXhr(assetType, file) {
    return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append('asset_type', assetType);
        fd.append('asset_file', file);

        const xhr = new XMLHttpRequest();
        xhr.open('POST', SETTINGS_API_URL + '?action=upload_asset');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.responseType = 'json';

        xhr.upload.onprogress = function(ev) {
            if (!ev.lengthComputable || !HAS_SWAL()) return;
            const pct = Math.max(0, Math.min(100, Math.round((ev.loaded / ev.total) * 100)));
            const container = Swal.getHtmlContainer();
            if (!container) return;
            const bar = container.querySelector('#settingsUploadBar');
            const text = container.querySelector('#settingsUploadPct');
            if (bar) bar.style.width = pct + '%';
            if (text) text.textContent = pct + '%';
        };

        xhr.onload = function() {
            const status = xhr.status || 0;
            const res = xhr.response || {};
            if (status >= 200 && status < 300 && res && res.ok) {
                resolve(res);
                return;
            }
            reject(new Error((res && res.error) ? res.error : 'Upload failed.'));
        };
        xhr.onerror = function() { reject(new Error('Network error while uploading.')); };
        xhr.send(fd);
    });
}

async function uploadSettingsAsset(assetType, fileInputId, hiddenFieldId, previewId) {
    const input = document.getElementById(fileInputId);
    if (!input || !input.files || !input.files[0]) return;
    const file = input.files[0];

    if (HAS_SWAL()) {
        Swal.fire({
            title: 'Upload Assets',
            html: `<div class="text-start small mb-2">Uploading file...</div>
                   <div class="progress" style="height:14px">
                     <div id="settingsUploadBar" class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width:0%"></div>
                   </div>
                   <div class="small mt-2">Progress: <span id="settingsUploadPct">0%</span></div>`,
            allowOutsideClick: false,
            allowEscapeKey: false,
            showConfirmButton: false
        });
    }

    try {
        const json = await uploadSettingsAssetXhr(assetType, file);
        const hidden = document.getElementById(hiddenFieldId);
        if (hidden) hidden.value = json.filename || '';
        const preview = document.getElementById(previewId);
        if (preview && json.url) {
            preview.src = json.url;
            preview.classList.remove('d-none');
        }
        uiCloseLoading();
        await uiSuccess('Success', json.message || 'File uploaded successfully.');
    } catch (e) {
        uiCloseLoading();
        await uiError('Upload Failed', e.message || 'Unknown error');
    } finally {
        input.value = '';
    }
}

// Initialize tabs
document.addEventListener('DOMContentLoaded', function() {
    // Tab switching is handled by CoreUI
    loadAllSettings();
    const headerLogo = document.getElementById('headerLogo');
    const favicon = document.getElementById('favicon');
    const bg = document.getElementById('backgroundImage');
    if (headerLogo) headerLogo.addEventListener('change', function(){ uploadSettingsAsset('headerLogo', 'headerLogo', 'headerLogoPath', 'headerLogoPreview'); });
    if (favicon) favicon.addEventListener('change', function(){ uploadSettingsAsset('favicon', 'favicon', 'faviconPath', 'faviconPreview'); });
    if (bg) bg.addEventListener('change', function(){ uploadSettingsAsset('backgroundImage', 'backgroundImage', 'backgroundImagePath', 'backgroundImagePreview'); });
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
