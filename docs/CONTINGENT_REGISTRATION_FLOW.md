# Pendaftaran Kontinjen - User Input Flow

## Overview
Dokumen ini menerangkan alur lengkap pendaftaran kontinjen untuk SAM 2026, dari mula hingga penghantaran borang.

---

## User Journey Map

### Entry Point
- **Lokasi**: Halaman Kontinjen (`pages/contingent.php`)
- **Trigger**: Klik butang "Daftar Kontinjen Baru"
- **Action**: Membuka modal atau navigasi ke halaman pendaftaran

---

## Step-by-Step Process Flow

### **STEP 1: Pemilihan Institusi (Institution Selection)**

#### Screen Layout
- **Title**: "Langkah 1: Pilih Institusi"
- **Progress Indicator**: Step 1 of 5 (20%)

#### Form Fields

**1.1. Institution/Institusi** ⭐ *Required*
- **Type**: Dropdown/Select
- **Label**: "INSTITUTION/ INSTITUSI*"
- **Placeholder**: "Sila pilih institusi..."
- **Options**: 
  - Pre-populated list of institutions
  - "Please select" as default (disabled)
- **Validation**:
  - Required field
  - Must select from list (cannot be "Please select")
  - Real-time validation on blur/change

**1.2. Contact Information Notice** (Informational)
- **Type**: Alert/Info Box (Red background)
- **Content**: 
  ```
  Jika nama pasukan/IPT/pasukan anda tidak tersenarai, sila hubungi:
  Nama: Mr. Ahmad Fadhil Bin Mohamad Locman
  Tel: 03-88706455 / 013-3236874
  Email: fadhil.locman@mohe.gov.my
  ```
- **Action**: Optional - Link to email or phone

#### Navigation
- **Back Button**: Disabled (first step)
- **Next Button**: 
  - Enabled only when institution is selected
  - Label: "Seterusnya" / "Next"
  - Action: Validate → Proceed to Step 2

#### Validation Rules
- ✅ Institution must be selected
- ❌ Show error: "Sila pilih institusi" if not selected

---

### **STEP 2: Maklumat Asas (Basic Information)**

#### Screen Layout
- **Title**: "Langkah 2: Maklumat Asas"
- **Progress Indicator**: Step 2 of 5 (40%)

#### Form Fields

**2.1. Short Name/Nama Singkatan** ⭐ *Required*
- **Type**: Text Input
- **Label**: "SHORT NAME/ NAMA SINGKATAN*"
- **Placeholder**: "cth: UPNM, UTM, USM"
- **Max Length**: 50 characters
- **Validation**:
  - Required field
  - Minimum 2 characters
  - Maximum 50 characters
  - Alphanumeric and spaces only
  - Real-time validation

**2.2. Head of Delegation Name** ⭐ *Required*
- **Type**: Text Input
- **Label**: "NAME (HEAD OF DELEGATION) / NAMA (KETUA KONTINJEN)*"
- **Placeholder**: "Masukkan nama penuh ketua kontinjen"
- **Max Length**: 100 characters
- **Validation**:
  - Required field
  - Minimum 3 characters
  - Must contain at least one space (full name)
  - Alphabetic characters, spaces, and common name characters only

**2.3. Head of Delegation Position** ⭐ *Required*
- **Type**: Text Input
- **Label**: "POSITION/ JAWATAN*"
- **Placeholder**: "cth: Dekan, Pengarah, Ketua Jabatan"
- **Max Length**: 100 characters
- **Validation**:
  - Required field
  - Minimum 2 characters
  - Alphanumeric, spaces, and common punctuation

#### Navigation
- **Back Button**: 
  - Enabled
  - Label: "Kembali" / "Back"
  - Action: Return to Step 1 (preserve data)
- **Next Button**: 
  - Enabled when all fields are valid
  - Label: "Seterusnya" / "Next"
  - Action: Validate → Proceed to Step 3

#### Validation Rules
- ✅ All three fields must be filled
- ✅ Short name: 2-50 characters
- ✅ Head name: Minimum 3 characters, must be full name
- ✅ Position: Minimum 2 characters
- ❌ Show inline errors below each invalid field

---

### **STEP 3: Maklumat Pegawai (Officer Information)**

#### Screen Layout
- **Title**: "Langkah 3: Maklumat Pegawai"
- **Progress Indicator**: Step 3 of 5 (60%)
- **Subtitle**: "Sila isi maklumat untuk dua (2) pegawai"

#### Form Fields - Officer 1

**3.1. Officer 1 Name** ⭐ *Required*
- **Type**: Text Input
- **Label**: "NAME OFFICER 1/ NAMA PEGAWAI 1*"
- **Placeholder**: "Masukkan nama penuh pegawai 1"
- **Max Length**: 100 characters
- **Validation**:
  - Required field
  - Minimum 3 characters
  - Must be full name format

**3.2. Officer 1 Position** ⭐ *Required*
- **Type**: Text Input
- **Label**: "POSITION OFFICER 1/ JAWATAN PEGAWAI 1*"
- **Placeholder**: "cth: Penolong Pendaftar, Setiausaha"
- **Max Length**: 100 characters
- **Validation**:
  - Required field
  - Minimum 2 characters

**3.3. Officer 1 Mobile Phone** ⭐ *Required*
- **Type**: Tel Input
- **Label**: "MOBILE PHONE OFFICER 1/ TEL. BIMBIT PEGAWAI 1*"
- **Placeholder**: "cth: 012-3456789"
- **Pattern**: Malaysian phone format
- **Validation**:
  - Required field
  - Format: 01X-XXXXXXX or 01XXXXXXXXX
  - Must start with 01
  - 10-11 digits total
  - Real-time format validation

**3.4. Officer 1 Email** ⭐ *Required*
- **Type**: Email Input
- **Label**: "EMAIL OFFICER 1/ EMEL PEGAWAI 1*"
- **Placeholder**: "pegawai1@example.com"
- **Validation**:
  - Required field
  - Valid email format
  - Real-time email validation

#### Form Fields - Officer 2

**3.5. Officer 2 Name** ⭐ *Required*
- **Type**: Text Input
- **Label**: "NAME OFFICER 2/ NAMA PEGAWAI 2*"
- **Placeholder**: "Masukkan nama penuh pegawai 2"
- **Max Length**: 100 characters
- **Validation**: Same as Officer 1 Name

**3.6. Officer 2 Position** ⭐ *Required*
- **Type**: Text Input
- **Label**: "POSITION OFFICER 2/ JAWATAN PEGAWAI 2*"
- **Placeholder**: "cth: Penolong Pendaftar, Setiausaha"
- **Max Length**: 100 characters
- **Validation**: Same as Officer 1 Position

**3.7. Officer 2 Mobile Phone** ⭐ *Required*
- **Type**: Tel Input
- **Label**: "MOBILE PHONE OFFICER 2/ TEL. BIMBIT PEGAWAI 2*"
- **Placeholder**: "cth: 012-3456789"
- **Validation**: Same as Officer 1 Mobile Phone

**3.8. Officer 2 Email** ⭐ *Required*
- **Type**: Email Input
- **Label**: "EMAIL OFFICER 2/ EMEL PEGAWAI 2*"
- **Placeholder**: "pegawai2@example.com"
- **Validation**: 
  - Same as Officer 1 Email
  - Must be different from Officer 1 email

#### Navigation
- **Back Button**: 
  - Enabled
  - Label: "Kembali" / "Back"
  - Action: Return to Step 2 (preserve data)
- **Next Button**: 
  - Enabled when all 8 fields are valid
  - Label: "Seterusnya" / "Next"
  - Action: Validate → Proceed to Step 4

#### Validation Rules
- ✅ All 8 fields must be filled
- ✅ Phone numbers: Valid Malaysian mobile format
- ✅ Emails: Valid format and unique (Officer 1 ≠ Officer 2)
- ✅ Names: Full name format (minimum 3 chars)
- ❌ Show inline errors with specific messages

---

### **STEP 4: Maklumat Hubungan (Contact Details)**

#### Screen Layout
- **Title**: "Langkah 4: Maklumat Hubungan"
- **Progress Indicator**: Step 4 of 5 (80%)

#### Form Fields

**4.1. Office Phone** ⭐ *Required*
- **Type**: Tel Input
- **Label**: "OFFICE PHONE/ TEL. PEJABAT*"
- **Placeholder**: "cth: 03-12345678"
- **Pattern**: Malaysian landline format
- **Validation**:
  - Required field
  - Format: 0X-XXXXXXX or 0XXXXXXXXX
  - Must start with 0 (not 01)
  - 9-11 digits total
  - Real-time format validation

**4.2. Fax** ⭐ *Required*
- **Type**: Tel Input
- **Label**: "FAX/ FAKS*"
- **Placeholder**: "cth: 03-12345679"
- **Pattern**: Malaysian fax format
- **Validation**:
  - Required field
  - Format: 0X-XXXXXXX or 0XXXXXXXXX
  - Must start with 0
  - 9-11 digits total
  - Can be same format as office phone

**4.3. Office Address** ⭐ *Required*
- **Type**: Textarea
- **Label**: "OFFICE ADDRESS/ ALAMAT PEJABAT*"
- **Placeholder**: "Masukkan alamat pejabat lengkap"
- **Rows**: 3-4 lines
- **Max Length**: 500 characters
- **Validation**:
  - Required field
  - Minimum 10 characters
  - Maximum 500 characters
  - Character counter visible

#### Navigation
- **Back Button**: 
  - Enabled
  - Label: "Kembali" / "Back"
  - Action: Return to Step 3 (preserve data)
- **Next Button**: 
  - Enabled when all 3 fields are valid
  - Label: "Semak & Sahkan" / "Review & Confirm"
  - Action: Validate → Proceed to Step 5

#### Validation Rules
- ✅ All 3 fields must be filled
- ✅ Office phone: Valid landline format
- ✅ Fax: Valid fax format
- ✅ Address: Minimum 10 characters, maximum 500
- ❌ Show inline errors with format examples

---

### **STEP 5: Semak & Sahkan (Review & Confirmation)**

#### Screen Layout
- **Title**: "Langkah 5: Semak & Sahkan"
- **Progress Indicator**: Step 5 of 5 (100%)
- **Subtitle**: "Sila semak semua maklumat sebelum menghantar"

#### Review Sections

**5.1. Maklumat Institusi**
- Institution name (from dropdown)
- Short name
- **Edit Button**: Return to Step 1

**5.2. Ketua Kontinjen**
- Name
- Position
- **Edit Button**: Return to Step 2

**5.3. Pegawai 1**
- Name
- Position
- Mobile phone
- Email
- **Edit Button**: Return to Step 3 (scroll to Officer 1 section)

**5.4. Pegawai 2**
- Name
- Position
- Mobile phone
- Email
- **Edit Button**: Return to Step 3 (scroll to Officer 2 section)

**5.5. Maklumat Hubungan**
- Office phone
- Fax
- Office address
- **Edit Button**: Return to Step 4

#### Confirmation Checkbox
- **Type**: Checkbox
- **Label**: "Saya mengesahkan bahawa semua maklumat yang diberikan adalah benar dan tepat*"
- **Required**: Yes
- **Validation**: Must be checked to submit

#### Navigation
- **Back Button**: 
  - Enabled
  - Label: "Kembali" / "Back"
  - Action: Return to Step 4
- **Submit Button**: 
  - Enabled when checkbox is checked
  - Label: "Hantar Pendaftaran" / "Submit Registration"
  - Style: Primary button (blue)
  - Action: Show loading state → Submit form
- **Cancel Button**: 
  - Enabled
  - Label: "Batal" / "Cancel"
  - Style: Secondary button (grey)
  - Action: Show confirmation dialog → Return to list page

---

## Validation Summary

### Real-time Validation
- Fields validated on blur/change
- Inline error messages appear below invalid fields
- Success indicators (green checkmark) for valid fields
- Next button enabled only when current step is valid

### Step Validation
- Cannot proceed to next step with invalid fields
- Error summary shown at top if validation fails
- Scroll to first error field automatically

### Final Validation
- All steps must be completed
- Confirmation checkbox must be checked
- Final validation before submission

---

## Error Handling & Messages

### Common Error Messages

**Institution:**
- "Sila pilih institusi" (if not selected)

**Short Name:**
- "Nama singkatan diperlukan"
- "Nama singkatan mesti antara 2-50 aksara"

**Names:**
- "Nama diperlukan"
- "Nama mesti sekurang-kurangnya 3 aksara"
- "Sila masukkan nama penuh"

**Phone Numbers:**
- "Nombor telefon diperlukan"
- "Format telefon tidak sah. Contoh: 012-3456789"
- "Nombor telefon mesti bermula dengan 01 (bimbit) atau 0X (pejabat)"

**Email:**
- "Alamat e-mel diperlukan"
- "Format e-mel tidak sah"
- "E-mel pegawai 1 dan 2 mesti berbeza"

**Address:**
- "Alamat pejabat diperlukan"
- "Alamat mesti sekurang-kurangnya 10 aksara"
- "Alamat tidak boleh melebihi 500 aksara"

---

## Navigation Flow

```
[Contingent List Page]
         ↓
    [Click "Daftar Kontinjen Baru"]
         ↓
[Step 1: Institution Selection]
    ← Back (disabled) | Next →
         ↓
[Step 2: Basic Information]
    ← Back | Next →
         ↓
[Step 3: Officer Information]
    ← Back | Next →
         ↓
[Step 4: Contact Details]
    ← Back | Review →
         ↓
[Step 5: Review & Confirm]
    ← Back | Submit | Cancel
         ↓
[Success Page / Return to List]
```

---

## UI/UX Considerations

### Progress Indicator
- Visual progress bar showing current step (1/5, 2/5, etc.)
- Completed steps marked with checkmark
- Current step highlighted
- Future steps greyed out

### Data Persistence
- Form data saved to sessionStorage/localStorage
- Data preserved when navigating back/forward
- Data cleared only on successful submission or explicit cancel

### Responsive Design
- Mobile: Single column layout
- Tablet: Two columns for officer fields
- Desktop: Optimal spacing and layout

### Accessibility
- All fields have proper labels
- Required fields marked with asterisk (*)
- Error messages associated with fields (aria-describedby)
- Keyboard navigation support
- Focus management between steps

### Loading States
- Loading spinner on submit button during submission
- Disable form during submission
- Success/error feedback after submission

---

## Success Flow

### After Successful Submission

1. **Success Message**
   - Green alert/success notification
   - Message: "Pendaftaran kontinjen berjaya dihantar!"
   - Show registration reference number (if applicable)

2. **Next Actions**
   - Button: "Lihat Senarai Kontinjen" → Return to list
   - Button: "Daftar Kontinjen Lain" → Start new registration
   - Auto-redirect to list after 5 seconds (optional)

3. **Data Clearing**
   - Clear all form data from storage
   - Reset form state

---

## Cancel Flow

### Cancel Confirmation
- Dialog: "Adakah anda pasti mahu membatalkan pendaftaran?"
- Options:
  - "Ya, Batal" → Clear data → Return to list
  - "Tidak, Teruskan" → Stay on current step

### Data Handling on Cancel
- Clear sessionStorage/localStorage
- Return to contingent list page
- No data saved

---

## Edge Cases

### Institution Not in List
- User sees contact information notice
- Can contact administrator
- Cannot proceed without selecting from list
- Future: Option to request new institution addition

### Duplicate Email
- Officer 1 and Officer 2 cannot have same email
- Real-time validation shows error
- Error message: "E-mel ini telah digunakan untuk pegawai lain"

### Invalid Phone Format
- Real-time formatting as user types
- Auto-format: 0123456789 → 012-3456789
- Validation on blur

### Session Timeout
- Warning if form idle for 30 minutes
- Option to save draft (if implemented)
- Auto-save to localStorage every 30 seconds (optional)

---

## Form State Management

### Data Structure
```javascript
{
  step: 1-5,
  institution: "",
  shortName: "",
  headName: "",
  headPosition: "",
  officer1: {
    name: "",
    position: "",
    phone: "",
    email: ""
  },
  officer2: {
    name: "",
    position: "",
    phone: "",
    email: ""
  },
  officePhone: "",
  fax: "",
  officeAddress: "",
  confirmed: false
}
```

### Auto-save
- Save to localStorage on every field change
- Restore data if user returns to form
- Clear on successful submission

---

## Summary

**Total Steps**: 5
**Total Required Fields**: 13
**Optional Fields**: 0
**Estimated Completion Time**: 5-10 minutes
**Validation Points**: Real-time + Step validation + Final validation

