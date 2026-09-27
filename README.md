# SAM 2026 - Sukan Asasi Malaysia

Sistem Pengurusan Kejohanan Sukan Asasi Malaysia (SAM 2026) - A modular PHP application built with CoreUI Bootstrap admin template. update foldder

## Project Structure

```
sam2026/
├── assets/
│   ├── css/
│   │   └── custom.css          # Custom styles
│   ├── js/
│   │   └── custom.js           # Custom scripts
│   └── img/                    # Images and assets
├── includes/
│   ├── header.php              # HTML head and opening tags
│   ├── sidebar.php             # Sidebar navigation
│   ├── topbar.php              # Top navigation bar
│   ├── footer.php              # Footer and closing tags
│   └── layout.php              # Base layout template
├── pages/
│   ├── users.php               # Users management page
│   ├── settings.php            # Settings page
│   ├── reports.php             # Reports page
│   └── components.php          # UI components showcase
├── config.php                  # Site configuration
└── index.php                   # Dashboard (home page)
```

## Features

- ✅ **CoreUI Bootstrap 4.3.0** - Modern admin template
- ✅ **Modular PHP Structure** - Separated layouts, components, and pages
- ✅ **Responsive Design** - Works on all devices
- ✅ **Sidebar Navigation** - Collapsible sidebar with active state detection
- ✅ **Top Navigation Bar** - Header with breadcrumbs and user menu
- ✅ **Multiple Pages** - Dashboard, Users, Settings, Reports, Components

## Installation

1. Place this project in your XAMPP `htdocs` directory
2. Access via browser: `http://localhost/sam2026/`
3. No additional setup required - CoreUI loads via CDN

## Usage

### Adding a New Page

1. Create a new file in the `pages/` directory (e.g., `pages/newpage.php`)
2. Use this template:

```php
<?php
require_once __DIR__ . '/../config.php';
$page_title = 'New Page';

ob_start();
?>
<div class="container-fluid">
    <div class="row mb-4">
        <div class="col-12">
            <h2 class="mb-0">New Page</h2>
        </div>
    </div>
    <!-- Your content here -->
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
```

3. Add the page to navigation in `config.php`:

```php
[
    'title' => 'New Page',
    'icon' => 'cil-star',
    'url' => 'pages/newpage.php',
    'active' => false
]
```

### Customizing

- **Site Name**: Edit `SITE_NAME` in `config.php`
- **Navigation**: Modify `$nav_items` array in `config.php`
- **Styles**: Edit `assets/css/custom.css`
- **Scripts**: Edit `assets/js/custom.js`

## CoreUI Components

All CoreUI components are available. Visit the Components page to see examples of:
- Buttons
- Forms
- Alerts
- Badges
- Tables
- And more...

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)

## License

This project uses CoreUI which is licensed under MIT.

