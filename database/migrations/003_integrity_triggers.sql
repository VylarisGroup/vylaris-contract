-- Migration 003: Integritäts-Trigger (Defense in Depth).
-- Die Unveränderlichkeit wird zusätzlich in den Services erzwungen. Diese Trigger sichern gegen
-- Fehler im Anwendungscode und gegen direkte Schreibzugriffe mit dem Anwendungskonto ab.
-- Hinweis: Erfordert das TRIGGER-Recht. Fehlt es (Shared Hosting), meldet die Systemdiagnose
-- "Integritäts-Trigger nicht aktiv" und die Anwendung verlässt sich auf die Service-Schicht.
-- Grenze: Ein Angreifer mit DDL-Rechten auf der Datenbank kann Trigger entfernen.

CREATE TRIGGER trg_cv_no_update BEFORE UPDATE ON contract_versions FOR EACH ROW
BEGIN
  IF OLD.state = 'locked' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gesperrte Vertragsversion ist unveraenderlich';
  END IF;
  IF NEW.contract_id <> OLD.contract_id OR NEW.version_no <> OLD.version_no THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Vertrags- und Versionsnummer sind unveraenderlich';
  END IF;
END;
-- @@

CREATE TRIGGER trg_cv_no_delete BEFORE DELETE ON contract_versions FOR EACH ROW
BEGIN
  IF OLD.state = 'locked' AND COALESCE(@purge_authorized, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Gesperrte Vertragsversion darf nicht geloescht werden';
  END IF;
END;
-- @@

CREATE TRIGGER trg_doc_no_update BEFORE UPDATE ON documents FOR EACH ROW
BEGIN
  IF NEW.sha256 <> OLD.sha256 OR NEW.storage_key <> OLD.storage_key OR NEW.size_bytes <> OLD.size_bytes
     OR NEW.kind <> OLD.kind OR NEW.contract_id <> OLD.contract_id
     OR NOT (NEW.contract_version_id <=> OLD.contract_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dokumentinhalt und -zuordnung sind unveraenderlich';
  END IF;
END;
-- @@

CREATE TRIGGER trg_doc_no_delete BEFORE DELETE ON documents FOR EACH ROW
BEGIN
  IF COALESCE(@purge_authorized, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dokument darf nur ueber die dokumentierte Loeschprozedur entfernt werden';
  END IF;
END;
-- @@

CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_events FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit-Eintraege sind unveraenderlich';
END;
-- @@

CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_events FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit-Eintraege duerfen nicht geloescht werden';
END;
-- @@

CREATE TRIGGER trg_sigev_no_update BEFORE UPDATE ON signature_evidence FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signaturbelege sind unveraenderlich';
END;
-- @@

CREATE TRIGGER trg_sigev_no_delete BEFORE DELETE ON signature_evidence FOR EACH ROW
BEGIN
  IF COALESCE(@purge_authorized, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signaturbelege duerfen nicht geloescht werden';
  END IF;
END;
-- @@

CREATE TRIGGER trg_sigevt_no_update BEFORE UPDATE ON signature_events FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signaturereignisse sind unveraenderlich';
END;
-- @@

CREATE TRIGGER trg_sigreq_binding BEFORE UPDATE ON signature_requests FOR EACH ROW
BEGIN
  IF NEW.contract_version_id <> OLD.contract_version_id OR NEW.document_id <> OLD.document_id
     OR NEW.document_sha256 <> OLD.document_sha256 OR NEW.mode <> OLD.mode THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signaturanfrage ist an Version und Dokument gebunden';
  END IF;
END;
-- @@

CREATE TRIGGER trg_approval_binding BEFORE UPDATE ON approvals FOR EACH ROW
BEGIN
  IF NEW.contract_version_id <> OLD.contract_version_id OR NEW.version_hash <> OLD.version_hash THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Freigabe ist an eine konkrete Version gebunden';
  END IF;
  IF OLD.status IN ('approved','rejected') AND NEW.status <> OLD.status AND NEW.status <> 'invalidated' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Entschiedene Freigabe darf nur invalidiert werden';
  END IF;
END;
-- @@

CREATE TRIGGER trg_notary_binding BEFORE UPDATE ON notary_cases FOR EACH ROW
BEGIN
  IF OLD.provided_pdf_sha256 IS NOT NULL AND (
       NOT (NEW.provided_pdf_sha256 <=> OLD.provided_pdf_sha256)
       OR NOT (NEW.document_id <=> OLD.document_id)
       OR NEW.contract_version_id <> OLD.contract_version_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bereitgestelltes Notariats-PDF darf nicht ersetzt werden';
  END IF;
END;

CREATE TRIGGER trg_sigevt_no_delete BEFORE DELETE ON signature_events FOR EACH ROW
BEGIN
  IF COALESCE(@purge_authorized, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signaturereignisse sind unveraenderlich';
  END IF;
END;
-- @@

CREATE TRIGGER trg_sigreq_no_delete BEFORE DELETE ON signature_requests FOR EACH ROW
BEGIN
  IF COALESCE(@purge_authorized, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Signaturanfragen duerfen nicht geloescht werden';
  END IF;
END;
-- @@

CREATE TRIGGER trg_approval_no_delete BEFORE DELETE ON approvals FOR EACH ROW
BEGIN
  IF COALESCE(@purge_authorized, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Freigaben duerfen nicht geloescht werden';
  END IF;
END;
-- @@

CREATE TRIGGER trg_notary_no_delete BEFORE DELETE ON notary_cases FOR EACH ROW
BEGIN
  IF COALESCE(@purge_authorized, 0) <> 1 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Notariatsfaelle duerfen nicht geloescht werden';
  END IF;
END;
-- @@
