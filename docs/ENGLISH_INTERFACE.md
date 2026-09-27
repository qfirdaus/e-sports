# English interface

The application UI uses English. `APP_LANGUAGE` is `en`; legacy `sam_lang=ms`
cookies are replaced, and the settings API normalises the interface language to
English when reading or saving settings.

Page text, navigation, form labels, validation, notifications, report headings,
print headings, certificate email messages and CSV instructions are English.
`config/english-labels.php` provides display-only translations for stored
reference labels. Sport/category descriptions have their own translator so that
names of people, teams and institutions are not rewritten. In particular, the
state name Perak is not treated as a silver-medal label.

Database columns, request keys, route paths, stored enum values and existing
user-entered records are unchanged. The bulk team importer accepts English
MANAGER/COACH/ATHLETE row types as well as legacy PENGURUS/JURULATIH/ATLET types.
New CSV templates use English row types.

## Artwork limitation

Malay lettering embedded in the existing banner JPEGs and certificate background
JPEGs is part of the artwork. Those files remain unchanged and need English
artwork replacements for fully English banners and certificates. Official names
and logos remain in their original form.

## Verification

Run `php tests/english_labels_test.php` for display-label regression checks.
PHP syntax and inline JavaScript syntax were checked across the application.
The public home, login, contingent, medal and athlete pages returned HTTP 200 with
English document language, including requests carrying a legacy Malay cookie.
The duplicate, malformed script block on the public athlete page was removed
when checking its rendered JavaScript.

The existing `tests/login_redirect_test.php` cannot run as-is because its
`../../config.php` include points outside the application. Browser visual and
interactive authenticated workflows have not been verified in this environment.
