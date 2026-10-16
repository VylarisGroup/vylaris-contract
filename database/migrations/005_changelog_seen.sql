-- Migration 005: Merkt sich je Benutzer die zuletzt angezeigte Programmversion (Änderungsverlauf nach Updates). Rein additiv.
ALTER TABLE users ADD COLUMN last_seen_version VARCHAR(20) NULL DEFAULT NULL;
