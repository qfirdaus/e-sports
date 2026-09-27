# Pendaftaran Kontinjen - Ringkasan Alur Pengguna

## Alur Ringkas

```
[Senarai Kontinjen]
    ↓ Klik "Daftar Kontinjen Baru"
    
[LANGKAH 1: Pilih Institusi]
    • Institution/Institusi (Dropdown) ⭐
    • Notis: Hubungi jika tidak tersenarai
    ↓ Next
    
[LANGKAH 2: Maklumat Asas]
    • Short Name/Nama Singkatan ⭐
    • Head of Delegation Name ⭐
    • Head of Delegation Position ⭐
    ↓ Next
    
[LANGKAH 3: Maklumat Pegawai]
    • Officer 1: Name, Position, Phone, Email ⭐
    • Officer 2: Name, Position, Phone, Email ⭐
    ↓ Next
    
[LANGKAH 4: Maklumat Hubungan]
    • Office Phone ⭐
    • Fax ⭐
    • Office Address ⭐
    ↓ Review
    
[LANGKAH 5: Semak & Sahkan]
    • Semak semua maklumat
    • Checkbox pengesahan ⭐
    ↓ Submit
    
[Berjaya!]
    • Mesej kejayaan
    • Kembali ke senarai
```

## Senarai Medan Wajib (13 medan)

### Langkah 1
1. ✅ Institution/Institusi

### Langkah 2
2. ✅ Short Name/Nama Singkatan
3. ✅ Head of Delegation Name
4. ✅ Head of Delegation Position

### Langkah 3
5. ✅ Officer 1 Name
6. ✅ Officer 1 Position
7. ✅ Officer 1 Mobile Phone
8. ✅ Officer 1 Email
9. ✅ Officer 2 Name
10. ✅ Officer 2 Position
11. ✅ Officer 2 Mobile Phone
12. ✅ Officer 2 Email

### Langkah 4
13. ✅ Office Phone
14. ✅ Fax
15. ✅ Office Address

### Langkah 5
16. ✅ Confirmation Checkbox

## Peraturan Validasi Utama

| Medan | Peraturan |
|-------|-----------|
| **Institution** | Mesti dipilih dari senarai |
| **Short Name** | 2-50 aksara, alphanumeric |
| **Names** | Minimum 3 aksara, nama penuh |
| **Phone (Mobile)** | Format: 01X-XXXXXXX (10-11 digit) |
| **Phone (Office/Fax)** | Format: 0X-XXXXXXX (9-11 digit) |
| **Email** | Format e-mel sah, unik untuk setiap pegawai |
| **Address** | 10-500 aksara |

## Butang Navigasi

| Butang | Lokasi | Tindakan |
|--------|--------|----------|
| **Kembali** | Langkah 2-5 | Kembali ke langkah sebelumnya |
| **Seterusnya** | Langkah 1-4 | Terus ke langkah seterusnya (jika valid) |
| **Semak & Sahkan** | Langkah 4 | Terus ke langkah semakan |
| **Hantar** | Langkah 5 | Hantar borang (jika checkbox dicentang) |
| **Batal** | Langkah 5 | Batal pendaftaran (dengan pengesahan) |

## Mesej Ralat Biasa

- "Sila pilih institusi"
- "Medan ini diperlukan"
- "Format tidak sah"
- "E-mel mesti unik"
- "Sila masukkan nama penuh"
- "Nombor telefon tidak sah"

## Ciri-ciri UI

- ✅ Progress bar (1/5, 2/5, dll.)
- ✅ Validasi masa nyata
- ✅ Mesej ralat inline
- ✅ Auto-save data
- ✅ Responsif (mobile/tablet/desktop)
- ✅ Pengesahan sebelum batal
- ✅ Mesej kejayaan selepas hantar

