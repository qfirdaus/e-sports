<?php
/**
 * Reports Page
 */
require_once __DIR__ . '/../config.php';

$page_title = 'Reports';

ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-0">Reports</h2>
            <p class="text-muted">View and generate reports</p>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Report Filters</strong>
                </div>
                <div class="card-body">
                    <form class="row g-3">
                        <div class="col-md-3">
                            <label for="reportType" class="form-label">Report Type</label>
                            <select class="form-select" id="reportType">
                                <option selected>Select a report type...</option>
                                <option>Contingent Report</option>
                                <option>Athlete Report</option>
                                <option>Results Report</option>
                                <option>Medal Tally Report</option>
                                <option>Venue Report</option>
                                <option>Event Report</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="startDate" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="startDate">
                        </div>
                        <div class="col-md-3">
                            <label for="endDate" class="form-label">Deadline</label>
                            <input type="date" class="form-control" id="endDate">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div>
                                <button type="submit" class="btn btn-primary w-100">Generate Report</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Report Data</strong>
                </div>
                <div class="card-body">
                    <p class="text-muted">Select the filters above and click "Generate Report" to view the data.</p>
                    <div class="text-center py-5">
                        <i class="icon icon-4xl text-muted cil cil-chart" style="font-size: 4rem;"></i>
                        <p class="mt-3">No report data available</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>

