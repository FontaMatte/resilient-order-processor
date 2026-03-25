-- ============================================================
-- MIGRATION: Trigger per notifiche outbox in tempo reale
-- ============================================================
-- Quando un nuovo messaggio viene inserito nella tabella outbox,
-- PostgreSQL invia automaticamente una notifica al canale 'new_outbox_message'.
-- L'Outbox Relay ascolta questo canale e processa immediatamente.

-- Funzione trigger: viene eseguita automaticamente dopo ogni INSERT su outbox.
-- NEW è una variabile speciale che contiene la riga appena inserita.
-- pg_notify() invia la notifica sul canale specificato con un payload.
CREATE OR REPLACE FUNCTION notify_new_outbox_message()
RETURNS TRIGGER AS $$
BEGIN
    PERFORM pg_notify('new_outbox_message', NEW.id::text);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Trigger: collega la funzione alla tabella outbox.
-- AFTER INSERT: si attiva DOPO che l'INSERT è completato (e committato).
-- FOR EACH ROW: si attiva per ogni riga inserita.
DROP TRIGGER IF EXISTS outbox_notify_trigger ON outbox;

CREATE TRIGGER outbox_notify_trigger
    AFTER INSERT ON outbox
    FOR EACH ROW
    EXECUTE FUNCTION notify_new_outbox_message();