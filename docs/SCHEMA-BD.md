# Schema bazei de date

Document comun; fiecare modul își descrie tabelele. Sursa de adevăr sunt migrările din `database/migrations/`.

## Pacienți (`2026_03_01_000001_create_patients_tables.php`)

| Tabelă | Rol | Coloane importante |
|--------|-----|--------------------|
| `patients` | Fișa administrativă | `last_name`, `first_name`, `search_name` (normalizat), `birth_date`, `sex`, `cnp` (criptat), `cnp_index` (HMAC, unic), `citizenship`, `foreign_id_type`, `foreign_id_number` (criptat), `foreign_id_index`, `phone`, `phone_normalized`, `email`, adresă, `allow_phone/sms/email/whatsapp` (operațional), `marketing_consent` + `_at` + `_source`, `admin_notes`, `merged_into_id`, `merged_at` |
| `patient_representatives` | Reprezentanți legali | `relationship`, `document_type`, `document_number` (criptat), `valid_from/until` |
| `care_relations` | Relații explicite de îngrijire | `staff_member_id`, `source`, `reason`, `valid_from/until` |
| `break_glass_accesses` | Acces de urgență | `user_id`, `patient_id`, `reason`, `expires_at` |
| `patient_merges` | Istoricul unirilor | `source_patient_id`, `target_patient_id`, `reason`, `summary` (ce s-a mutat) |

## Programări (`2026_03_02_000001_create_scheduling_tables.php`)

| Tabelă | Rol | Coloane importante |
|--------|-----|--------------------|
| `staff_schedules` | Program săptămânal | `staff_member_id`, `location_id`, `weekday` (1–7), `start_time`, `end_time`, `breaks` (JSON), `valid_from/until` |
| `schedule_exceptions` | Concedii, liber, ore suplimentare, închideri | `staff_member_id` (null = închidere), `location_id`, `kind`, `starts_at`, `ends_at` |
| `appointments` | Programări și solicitări | `patient_id` (null până la identificare), `service_id`, `staff_member_id`, `location_id`, `series_id`, `series_index`, `starts_at`, `ends_at`, `blocked_until` (+pauză), `status`, `source`, `payer_type` (estimat), `request_contact` (criptat), `request_reference`, `request_specialty_id`, `request_preferred_start`, `cancel_reason`, `reschedule_requested_at`, `version` (concurență optimistă) |
| `appointment_resources` | Resursele rezervate | `resource_type` (staff/room/equipment), `resource_id`, `starts_at`, `ends_at` (incl. pauză), `released_at`, `held_until` (ținere temporară) |
| `appointment_events` | Istoric doar-adăugare | `type`, `from_status`, `to_status`, `reason`, `changes` (JSON), `user_id` |
| `appointment_series` | Serii de ședințe | `rule` (JSON), `planned_count`, `status` |
| `waitlist_entries` / `waitlist_offers` | Lista de așteptare și ofertele | `preferences` (zile, interval orar), `priority`, `valid_until`, `status`; oferta: `slot_start/end`, `outcome` |
| `patient_access_tokens` | Linkuri pentru pacient | `token_hash` (SHA-256), `purpose`, `expires_at`, `used_at` |
| `service_records` | **Servicii efectuate** — pivot pentru Rehab, CAS, Billing, Reports | vezi mai jos |

### `service_records` — contract pentru celelalte module

- Creat **doar explicit** prin `App\Modules\Scheduling\Services\ServiceRecordService` (`fromAppointment()` după
  sosirea pacientului sau `manual()` cu motiv obligatoriu). Nicio înregistrare nu apare automat din ora
  programării sau din statusul „finalizată”.
- Coloane: `patient_id`, `appointment_id` (null dacă manual), `service_id`, `staff_member_id`, `location_id`,
  `performed_at`, `quantity`, `payer_type` (`privat` | `cas` | `pachet` | `asigurator`), `insurer_name`,
  `status` (`efectuat` | `anulat`), `cancel_reason`, `corrects_id`, `corrected_by_id`, `manual_reason`, `notes`,
  `recorded_by`, `cancelled_by`, `cancelled_at`.
- Nu se șterge. Corecția = `cancel()` cu motiv (+ opțional înregistrare înlocuitoare legată prin `corrects_id` /
  `corrected_by_id`).
- Evenimentul `ServiceRecordChanged($record, 'recorded'|'cancelled')` se emite după commit.
- Pentru CAS: se citesc doar rândurile `payer_type = cas` și `status = efectuat`; o anulare ulterioară a unui
  serviciu deja raportat trebuie tratată de modulul CAS ca lot de corecție.

### Setări (tabela comună `settings`)

Chei `scheduling.*` și `patients.*` definite în `App\Modules\Scheduling\Services\SchedulingSettings`, editabile în
Administrare → Reguli programări (permisiunea `scheduling.settings`), auditate cu valoarea veche și nouă.
