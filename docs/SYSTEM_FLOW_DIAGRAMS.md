# SAM 2026 - System Flow Diagrams

## Visual Flow Representations

### 1. Application Request Flow

```
┌─────────────────────────────────────────────────────────────┐
│                    User Browser Request                      │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                      index.php                               │
│  • Redirects to pages/dashboard.php                         │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    config.php                                │
│  • BASE_URL detection                                        │
│  • Site constants                                            │
│  • Navigation setup                                          │
│  • Session initialization                                    │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                  auth.php (Session)                          │
│  • Session::start()                                          │
│  • Session configuration                                      │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                database.php                                  │
│  • Database::getInstance()                                 │
│  • PDO connection                                            │
│  • Singleton pattern                                         │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    rbac.php                                  │
│  • RBAC initialization                                       │
│  • Check database tables                                     │
│  • Load access rules                                         │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                   layout.php                                │
│  • requirePageAccess() check                                 │
│  • Load header.php                                           │
│  • Load sidebar.php                                          │
│  • Render content                                            │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    Page Content                              │
│  • Dashboard/Contingent/Sports/etc.                         │
└─────────────────────────────────────────────────────────────┘
```

### 2. Authentication Flow

```
┌─────────────────────────────────────────────────────────────┐
│              User Login Request                               │
│         POST: email + password                               │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│              Auth::login(email, password)                     │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
                    ┌───────┴───────┐
                    │               │
                    ▼               ▼
        ┌──────────────────┐  ┌──────────────────┐
        │ User Exists?     │  │ Account Active?  │
        └────────┬─────────┘  └────────┬─────────┘
                 │                      │
                 ▼                      ▼
        ┌──────────────────┐  ┌──────────────────┐
        │ Account Locked?  │  │ Password Valid?  │
        └────────┬─────────┘  └────────┬─────────┘
                 │                      │
                 │                      ▼
                 │              ┌───────────────┐
                 │              │ Increment      │
                 │              │ Login Attempts │
                 │              └───────┬─────────┘
                 │                      │
                 │                      ▼
                 │              ┌───────────────┐
                 │              │ Lock Account?  │
                 │              │ (if >= 5)      │
                 │              └───────┬─────────┘
                 │                      │
                 └──────────────────────┘
                            │
                            ▼
                ┌───────────────────────┐
                │   Create Session     │
                │  • Regenerate ID     │
                │  • Set user_id       │
                │  • Set user_role     │
                │  • Set user_name     │
                └───────────┬───────────┘
                            │
                            ▼
                ┌───────────────────────┐
                │  Reset Login Attempts│
                │  Update Last Login   │
                │  Log Activity        │
                └───────────┬───────────┘
                            │
                            ▼
                ┌───────────────────────┐
                │   Redirect Based on   │
                │        Role           │
                │  • ADMIN → dashboard │
                │  • JUDGE → results    │
                │  • CONTINGENT → ...   │
                └───────────────────────┘
```

### 3. Page Access Control Flow

```
┌─────────────────────────────────────────────────────────────┐
│              Page Request (e.g., pages/contingent.php)        │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│         RBAC::requirePageAccess($pagePath)                  │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
                    ┌───────┴───────┐
                    │               │
                    ▼               ▼
        ┌──────────────────┐  ┌──────────────────┐
        │  Public Page?    │  │  User Logged In?  │
        └────────┬─────────┘  └────────┬─────────┘
                 │                      │
        ┌────────┴────────┐    ┌────────┴────────┐
        │                 │    │                  │
        ▼                 ▼    ▼                  ▼
    ┌────────┐      ┌────────┐  ┌──────────────┐  ┌──────────────┐
    │ Allow  │      │ Deny   │  │ Redirect to  │  │ Check Access  │
    │ Access │      │ Access │  │ Login Page    │  │ Rules         │
    └────────┘      └────────┘  └──────────────┘  └───────┬───────┘
                                                             │
                                                             ▼
                                                    ┌─────────────────┐
                                                    │ Database RBAC?  │
                                                    └────────┬─────────┘
                                                             │
                                    ┌────────────────────────┼────────────────────────┐
                                    │                        │                        │
                                    ▼                        ▼                        ▼
                            ┌───────────────┐      ┌───────────────┐      ┌───────────────┐
                            │ Query         │      │ Query         │      │ Static Config  │
                            │ user_roles    │      │ page_role_    │      │ Check          │
                            │               │      │ access        │      │                │
                            └───────┬───────┘      └───────┬───────┘      └───────┬───────┘
                                    │                      │                      │
                                    └──────────────────────┼──────────────────────┘
                                                           │
                                                           ▼
                                            ┌───────────────────────────┐
                                            │   Access Decision         │
                                            │  • Allowed → Render Page  │
                                            │  • Denied → Access Denied │
                                            │  • Auth Required → Login   │
                                            └───────────────────────────┘
```

### 4. Contingent Registration Flow

```
┌─────────────────────────────────────────────────────────────┐
│         User Clicks "Daftar Kontinjen Baru"                 │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│         JavaScript: showRegistrationForm()                  │
│  • Open modal                                               │
│  • Load saved data from localStorage                        │
│  • Reset to step 1                                          │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
                    ┌───────────────┐
                    │   Step 1      │
                    │ Institution   │
                    │ Selection     │
                    └───────┬───────┘
                            │ Validate
                            ▼
                    ┌───────────────┐
                    │   Step 2      │
                    │ Basic Info    │
                    │ • Short Name  │
                    │ • Head Name   │
                    │ • Head Pos    │
                    └───────┬───────┘
                            │ Validate
                            ▼
                    ┌───────────────┐
                    │   Step 3      │
                    │ Officers      │
                    │ • Officer 1   │
                    │ • Officer 2   │
                    └───────┬───────┘
                            │ Validate
                            ▼
                    ┌───────────────┐
                    │   Step 4      │
                    │ Contact       │
                    │ • Office Phone│
                    │ • Fax         │
                    │ • Address     │
                    └───────┬───────┘
                            │ Validate
                            ▼
                    ┌───────────────┐
                    │   Step 5      │
                    │ Review &      │
                    │ Confirm       │
                    └───────┬───────┘
                            │
                            ▼
                ┌───────────────────────┐
                │  submitRegistration() │
                │  • Validate all steps │
                │  • Save to localStorage│
                │  • AJAX POST          │
                └───────────┬───────────┘
                            │
                            ▼
                ┌───────────────────────┐
                │   Backend Processing  │
                │   (Future)            │
                │  • Validate data      │
                │  • Insert to DB       │
                │  • Return response    │
                └───────────────────────┘
```

### 5. RBAC Decision Tree

```
                    ┌─────────────────┐
                    │  Page Request   │
                    └────────┬────────┘
                             │
                             ▼
                    ┌─────────────────┐
                    │ Normalize Path  │
                    └────────┬────────┘
                             │
                             ▼
                    ┌─────────────────┐
                    │  Public Page?   │
                    └────────┬────────┘
                             │
                ┌────────────┴────────────┐
                │                          │
                ▼                          ▼
        ┌───────────────┐        ┌───────────────┐
        │     YES       │        │      NO        │
        │ Allow Access  │        │ Check Auth     │
        └───────────────┘        └────────┬───────┘
                                          │
                                          ▼
                                  ┌───────────────┐
                                  │ User Logged   │
                                  │     In?       │
                                  └────────┬───────┘
                                           │
                          ┌────────────────┴────────────────┐
                          │                                │
                          ▼                                ▼
                  ┌───────────────┐              ┌───────────────┐
                  │      NO       │              │      YES       │
                  │ Redirect to   │              │ Check Access   │
                  │ Login Page    │              │ Rules          │
                  └───────────────┘              └────────┬───────┘
                                                           │
                                                           ▼
                                                  ┌───────────────┐
                                                  │ Database RBAC │
                                                  │   Enabled?    │
                                                  └────────┬───────┘
                                                           │
                              ┌───────────────────────────┼───────────────────────────┐
                              │                           │                           │
                              ▼                           ▼                           ▼
                      ┌───────────────┐         ┌───────────────┐         ┌───────────────┐
                      │      YES      │         │      NO       │         │   FALLBACK    │
                      │ Query DB      │         │ Use Static    │         │ Static Config │
                      │ • user_roles │         │ Config        │         │               │
                      │ • page_access│         │               │         │               │
                      └───────┬───────┘         └───────┬───────┘         └───────┬───────┘
                              │                         │                         │
                              └─────────────────────────┼─────────────────────────┘
                                                        │
                                                        ▼
                                            ┌───────────────────────┐
                                            │   Access Decision    │
                                            │  • Allowed           │
                                            │  • Denied            │
                                            │  • Requires Auth     │
                                            └───────────────────────┘
```

### 6. Database Connection Flow

```
┌─────────────────────────────────────────────────────────────┐
│              getDB() Called                                  │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│      Database::getInstance()                                │
│  • Check if instance exists                                 │
└───────────────────────────┬─────────────────────────────────┘
                            │
                    ┌───────┴───────┐
                    │               │
                    ▼               ▼
        ┌──────────────────┐  ┌──────────────────┐
        │ Instance Exists? │  │ Create New       │
        │ Return Existing  │  │ Instance         │
        └──────────────────┘  └────────┬─────────┘
                                       │
                                       ▼
                            ┌───────────────────────┐
                            │  PDO Connection      │
                            │  • Host: 172.16.2.141 │
                            │  • DB: esportsdb      │
                            │  • Charset: utf8mb4   │
                            │  • Error Mode: Exception│
                            └───────────┬───────────┘
                                        │
                                        ▼
                            ┌───────────────────────┐
                            │  Return Connection    │
                            └───────────────────────┘
```

### 7. Session Management Flow

```
┌─────────────────────────────────────────────────────────────┐
│              Session::start() Called                         │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
                    ┌───────────────┐
                    │ Already       │
                    │ Started?      │
                    └───────┬───────┘
                            │
                ┌───────────┴───────────┐
                │                       │
                ▼                       ▼
        ┌───────────────┐       ┌───────────────┐
        │      YES      │       │      NO       │
        │     Return    │       │ Configure     │
        └───────────────┘       │ Session        │
                                └───────┬───────┘
                                        │
                                        ▼
                            ┌───────────────────────┐
                            │  Headers Sent?        │
                            └───────┬───────────────┘
                                    │
                        ┌───────────┴───────────┐
                        │                       │
                        ▼                       ▼
                ┌───────────────┐       ┌───────────────┐
                │      YES      │       │      NO        │
                │ Log Error     │       │ Set Cookie    │
                │ Return        │       │ Params         │
                └───────────────┘       │ • Lifetime     │
                                        │ • Path         │
                                        │ • Domain       │
                                        │ • Secure       │
                                        │ • HTTPOnly     │
                                        │ • SameSite     │
                                        └───────┬───────┘
                                                │
                                                ▼
                                    ┌───────────────────────┐
                                    │  session_start()      │
                                    └───────────────────────┘
```

### 8. Navigation Menu Flow

```
┌─────────────────────────────────────────────────────────────┐
│              sidebar.php Loaded                              │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│         Load RBAC Instance                                   │
│  • Get current user                                          │
│  • Get navigation sections                                   │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
                    ┌───────────────┐
                    │ User Logged   │
                    │     In?       │
                    └───────┬───────┘
                            │
                ┌───────────┴───────────┐
                │                       │
                ▼                       ▼
        ┌───────────────┐       ┌───────────────┐
        │      NO       │       │      YES      │
        │ Hide All      │       │ Filter Items   │
        │ Menu Items    │       │ by RBAC        │
        └───────────────┘       └───────┬───────┘
                                        │
                                        ▼
                            ┌───────────────────────┐
                            │  Loop Through Items   │
                            │  • Check visibility   │
                            │  • Check active state │
                            └───────┬───────────────┘
                                    │
                                    ▼
                            ┌───────────────────────┐
                            │  Render Menu          │
                            │  • Dashboard          │
                            │  • Sections           │
                            │  • Logout (if logged) │
                            └───────────────────────┘
```

---

## Data Flow Diagrams

### User Login Data Flow

```
User Input          →  Login Form
    │
    ├─ email        →  Auth::login()
    └─ password     →  Auth::login()
                        │
                        ├─ Query: SELECT * FROM users WHERE email = ?
                        │
                        ├─ Verify: password_verify()
                        │
                        ├─ Check: status, locked_until, login_attempts
                        │
                        ├─ Session: Set user_id, user_role, user_name
                        │
                        └─ Update: last_login, login_attempts = 0
```

### Page Access Data Flow

```
Page Request        →  layout.php
    │
    └─ Path        →  RBAC::requirePageAccess()
                        │
                        ├─ Normalize: page path
                        │
                        ├─ Check: isPublicPage()
                        │
                        ├─ Check: isLoggedIn()
                        │
                        ├─ Query: user_roles (if DB RBAC)
                        │         page_role_access
                        │
                        └─ Decision: Allow / Deny / Redirect
```

---

## Sequence Diagrams

### Login Sequence

```
User          Login Page        Auth Class        Database        Session
 │                │                 │               │               │
 │──POST(email,pass)───────────────>│               │               │
 │                │                 │               │               │
 │                │                 │──SELECT user──>│               │
 │                │                 │<──user data───│               │
 │                │                 │               │               │
 │                │                 │──verify pass──│               │
 │                │                 │               │               │
 │                │                 │──UPDATE login─>│               │
 │                │                 │               │               │
 │                │                 │──createSession─>│               │
 │                │                 │               │               │
 │                │                 │               │──set(user_id)─>│
 │                │                 │               │               │
 │<──redirect─────│                 │               │               │
 │                │                 │               │               │
```

### Page Access Sequence

```
User          Page          layout.php        RBAC          Database
 │             │                │              │               │
 │──GET page───>│               │              │               │
 │             │                │              │               │
 │             │──include──────>│              │               │
 │             │                │              │               │
 │             │                │──requireAccess─>│               │
 │             │                │              │               │
 │             │                │              │──check public─>│
 │             │                │              │<──false───────│
 │             │                │              │               │
 │             │                │              │──check auth───>│
 │             │                │              │<──true─────────│
 │             │                │              │               │
 │             │                │              │──query roles───>│
 │             │                │              │<──roles─────────│
 │             │                │              │               │
 │             │                │              │──check access──>│
 │             │                │              │<──allowed───────│
 │             │                │              │               │
 │             │<──render page──│              │               │
 │             │                │              │               │
```

---

**Document Version**: 1.0  
**Last Updated**: 2026

