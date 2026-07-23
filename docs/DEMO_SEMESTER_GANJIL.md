# Demo Semester Ganjil

This guide prepares only the initial live-demo state:

- Academic year: `2026/2027`
- Semester: `1` / `Ganjil`
- Active status: active

The demo seeder intentionally does not create semester genap, academic year `2027/2028`, promotion records, fake DOCX templates, or PDF files.

## Environment Requirements

Run this only on a safe local, testing, or approved demo database. The seeder refuses to run outside `local`, `testing`, or `demo` environments.

Do not run this against production data. The seeder does not truncate, delete, reset, migrate, or promote records.

## Required Password Variables

Set these before running the seeder:

```bash
DEMO_ADMIN_PASSWORD=...
DEMO_BUDI_PASSWORD=...
DEMO_ANI_PASSWORD=...
DEMO_YUSUF_PASSWORD=...
```

The seeder never prints plaintext passwords.

## Safe Seeding Command

From the project root:

```bash
php artisan db:seed --class=DemoSemesterGanjilSeeder
```

`DatabaseSeeder` does not run this seeder automatically.

## Demo Usernames

- Admin: `demo_admin_sdit`
- Budi Santoso: `demo_budi`
- Ani Rahmawati: `demo_ani`
- Yusuf Hidayat: `demo_yusuf`

## Seeded Scenario

- School profile: `SDIT Al Hidayah`, created only if no school profile already exists.
- Academic year: `2026/2027`, semester ganjil, active.
- Classes: `5A` and `5B`.
- Budi Santoso: wali kelas `5A`, pengajar regular mandatory subjects in `5A`.
- Ani Rahmawati: wali kelas `5B`, pengajar regular mandatory subjects in `5B`.
- Yusuf Hidayat: non-wali demo teacher for `PAI` and `Bahasa Sunda` in `5A` and `5B`.
- Students:
  - `5A`: Ahmad Fauzan, Siti Aisyah, Rina Putri.
  - `5B`: Dimas Pratama.
- Learning components: one LM and one TP for each seeded subject.
- Grades:
  - Ahmad has mostly complete `Matematika` data.
  - Siti has partial `Matematika` data.
  - Rina has incomplete grade/supporting data.
  - `Bahasa Indonesia 5A`, `PAI`, and `Bahasa Sunda` are ready for live input.
- Supporting data: sample attendance, extracurricular, capaian kompetensi, and notes for selected students only.

## Live Demo Order

1. Log in as admin `demo_admin_sdit`.
2. Open the dashboard and confirm the active context is `2026/2027` semester ganjil.
3. Open school profile and confirm the school identity is present.
4. Open academic year detail for `2026/2027` and confirm `Lanjutkan ke Semester Genap` is visible.
5. Do not click transition buttons until those workflows are separately verified for the demo.
6. Log in as Budi `demo_budi`.
7. Use the visible role switch to enter `Pengajar`.
8. Open score input and show `Matematika 5A`.
9. Edit or complete Siti/Rina scores during the live demo.
10. Switch Budi to `Wali Kelas`.
11. Open attendance, extracurricular, capaian kompetensi, notes, and report workflow for `5A`.
12. Log in as Ani `demo_ani`.
13. Show Ani can access her own `5B` pengajar assignments and cannot act as wali kelas for `5A`.
14. Log in as Yusuf `demo_yusuf`.
15. Show Yusuf can teach non-wali specialist/local subjects across `5A` and `5B`, without a wali kelas role.

## Cross-Class Authorization Demo

Use `5B` and Dimas Pratama as the class boundary. Ani is wali kelas for `5B`; Budi is wali kelas for `5A`. Budi should not access `5B` wali report actions, and Ani should not access `5A` wali report actions.

## DOCX Requirement

The seeder does not create a fake DOCX file. Before demonstrating DOCX generation, upload and activate a valid `UTS` report template for the active academic year and class.

## PDF Requirement

PDF behavior depends on LibreOffice availability:

- If LibreOffice is available, PDF controls should remain usable.
- If LibreOffice is unavailable, PDF controls should show the Phase 1A unavailable state.

The seeder does not install LibreOffice and does not generate PDFs.

## Transition Buttons

The `Lanjutkan ke Semester Genap` button appears on the academic-year detail page when `2026/2027` semester ganjil is active.

After a separately verified semester-genap transition, the `Buat Tahun Ajaran Berikutnya` action is expected from the semester-genap academic-year detail page. This seeder intentionally does not create that state.

## Targeted Cleanup Guidance

No cleanup command is implemented in this phase. If a local demo database must be cleaned manually, target only records with these stable demo identifiers:

- Usernames: `demo_admin_sdit`, `demo_budi`, `demo_ani`, `demo_yusuf`
- Teacher NUPTK: `900000001`, `900000002`, `900000003`
- Student NIS: `2605001`, `2605002`, `2605003`, `2605101`
- Student NISN: `9000000001`, `9000000002`, `9000000003`, `9000000101`
- Academic year: `2026/2027` semester `1` with description `Demo - Tahun Ajaran 2026/2027 Semester Ganjil`
- Extracurricular: `Pramuka Demo`

Do not remove non-demo records by name alone.
