<?php
/**
 * Components Page
 * Showcase CoreUI components
 */
require_once __DIR__ . '/../config.php';

$page_title = 'Components';

ob_start();
?>
<div class="w-100 px-3">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-0">Components</h2>
            <p class="text-muted">CoreUI component examples</p>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Button</strong>
                </div>
                <div class="card-body">
                    <button class="btn btn-primary me-2">Primary</button>
                    <button class="btn btn-secondary me-2">Secondary</button>
                    <button class="btn btn-success me-2">Success</button>
                    <button class="btn btn-danger me-2">Danger</button>
                    <button class="btn btn-warning me-2">Alert</button>
                    <button class="btn btn-info me-2">Information</button>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Alert</strong>
                </div>
                <div class="card-body">
                    <div class="alert alert-primary" role="alert">Primary alert</div>
                    <div class="alert alert-success" role="alert">Success alert</div>
                    <div class="alert alert-warning" role="alert">Warning alert</div>
                    <div class="alert alert-danger" role="alert">Danger alert</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Form Elements</strong>
                </div>
                <div class="card-body">
                    <form>
                        <div class="mb-3">
                            <label for="exampleInput" class="form-label">Text Input</label>
                            <input type="text" class="form-control" id="exampleInput" placeholder="Enter text">
                        </div>
                        <div class="mb-3">
                            <label for="exampleSelect" class="form-label">Select</label>
                            <select class="form-select" id="exampleSelect">
                                <option>Option 1</option>
                                <option>Option 2</option>
                                <option>Option 3</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="exampleCheck">
                                <label class="form-check-label" for="exampleCheck">Checkbox</label>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">Send</button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card mb-4">
                <div class="card-header">
                    <strong>Badges & Pills</strong>
                </div>
                <div class="card-body">
                    <h5>Badge</h5>
                    <span class="badge bg-primary me-2">Primary</span>
                    <span class="badge bg-secondary me-2">Secondary</span>
                    <span class="badge bg-success me-2">Success</span>
                    <span class="badge bg-danger me-2">Danger</span>
                    <span class="badge bg-warning me-2">Alert</span>
                    
                    <h5 class="mt-4">Pill</h5>
                    <span class="badge rounded-pill bg-primary me-2">Primary</span>
                    <span class="badge rounded-pill bg-secondary me-2">Secondary</span>
                    <span class="badge rounded-pill bg-success me-2">Success</span>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>

