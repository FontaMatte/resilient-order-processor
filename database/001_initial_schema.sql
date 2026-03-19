-- ============================================================
-- MIGRATION: Creazione schema iniziale
-- ============================================================
-- Questo file crea tutte le tabelle necessarie al sistema.
-- In un progetto reale useresti un tool di migration (come Doctrine Migrations
-- o Phinx) che tiene traccia di quali migration sono state applicate.
-- Per semplicità, usiamo un singolo file SQL che possiamo eseguire manualmente.

-- ============================================================
-- TABELLA: products
-- ============================================================
-- Rappresenta i prodotti nel nostro catalogo.
--
-- NOTA su DECIMAL(10,2):
--   Non usare MAI FLOAT per i soldi! FLOAT ha errori di arrotondamento.
--   Esempio: 0.1 + 0.2 = 0.30000000000000004 con FLOAT.
--   DECIMAL è un tipo "a precisione esatta": 10 cifre totali, 2 decimali.
--
-- NOTA su CHECK (stock >= 0):
--   Questo è un "vincolo" (constraint) del database. PostgreSQL impedisce
--   fisicamente che stock diventi negativo. È l'ultima linea di difesa:
--   anche se il codice PHP ha un bug, il database protegge i dati.
--   Questo è un principio importante: non fidarti solo del codice applicativo.

CREATE TABLE IF NOT EXISTS products (
    id          SERIAL PRIMARY KEY,
    name        VARCHAR(255) NOT NULL,
    price       DECIMAL(10,2) NOT NULL CHECK (price > 0),
    stock       INTEGER NOT NULL DEFAULT 0 CHECK (stock >= 0),
    created_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at  TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- NOTA su SERIAL:
--   È un tipo PostgreSQL che crea automaticamente una sequenza.
--   Ogni volta che inserisci una riga senza specificare l'id,
--   PostgreSQL assegna il prossimo numero (1, 2, 3...).
--   È l'equivalente di AUTO_INCREMENT in MySQL.

-- NOTA su TIMESTAMP WITH TIME ZONE:
--   A differenza di TIMESTAMP (senza timezone), questo tipo salva
--   anche il fuso orario. Fondamentale quando hai utenti in paesi diversi
--   o server in datacenter sparsi per il mondo.


-- ============================================================
-- TABELLA: orders
-- ============================================================
-- Rappresenta gli ordini nel sistema.
--
-- NOTA su UUID:
--   Non usiamo un ID numerico incrementale per gli ordini.
--   Usiamo un UUID (Universally Unique Identifier), es: "550e8400-e29b-41d4-a716-446655440000".
--   Perché?
--   1. Non è prevedibile: un utente non può indovinare gli ID degli altri ordini
--   2. Può essere generato dal client PRIMA di parlare col server (utile per l'idempotenza)
--   3. È unico a livello globale, anche tra database diversi
--
-- NOTA su gen_random_uuid():
--   È una funzione built-in di PostgreSQL che genera UUID v4 (casuali).
--   In MySQL dovresti usare UUID() che genera UUID v1 (basati sul timestamp, meno sicuri).
--
-- NOTA sull'idempotency_key:
--   Questo campo è FONDAMENTALE per la resilienza del sistema.
--   Problema: se il client manda una richiesta, il server la processa ma la risposta
--   si perde (timeout di rete), il client riprova. Senza idempotency_key, creeresti
--   un DOPPIO ordine. Con l'idempotency_key, il server riconosce: "questo ordine
--   l'ho già processato" e restituisce il risultato originale.
--   Lo vedremo in dettaglio nella Fase 7.

CREATE TABLE IF NOT EXISTS orders (
    id               UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    idempotency_key  VARCHAR(255) UNIQUE,
    product_id       INTEGER NOT NULL REFERENCES products(id),
    quantity         INTEGER NOT NULL CHECK (quantity > 0),
    total_price      DECIMAL(10,2) NOT NULL CHECK (total_price > 0),
    status           VARCHAR(50) NOT NULL DEFAULT 'pending',
    failure_reason   TEXT,
    created_at       TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    updated_at       TIMESTAMP WITH TIME ZONE DEFAULT NOW()
);

-- NOTA su REFERENCES products(id):
--   Questa è una FOREIGN KEY (chiave esterna). Dice a PostgreSQL:
--   "il valore di product_id DEVE esistere nella tabella products".
--   Se provi a inserire un ordine con product_id=999 e il prodotto 999
--   non esiste, PostgreSQL rifiuta l'operazione. Un'altra protezione a livello DB.

-- Indice sullo stato: le query "dammi tutti gli ordini pending" saranno velocissime.
-- Senza indice, PostgreSQL dovrebbe leggere TUTTE le righe (full table scan).
-- Con l'indice, salta direttamente alle righe con quello stato.
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);

-- Indice sull'idempotency_key: la ricerca per chiave di idempotenza deve essere istantanea.
-- UNIQUE sopra crea già un indice implicito, ma lo esplicitiamo per chiarezza.
CREATE INDEX IF NOT EXISTS idx_orders_idempotency ON orders(idempotency_key);


-- ============================================================
-- TABELLA: outbox
-- ============================================================
-- Questa è la tabella più "strana" ma anche la più importante per la resilienza.
--
-- IL PROBLEMA:
--   Quando confermiamo un ordine, dobbiamo fare due cose:
--   1. Aggiornare lo stato dell'ordine nel database (orders.status = 'confirmed')
--   2. Inviare un messaggio a RabbitMQ per processare il pagamento
--
--   Ma cosa succede se il punto 1 riesce e il punto 2 fallisce?
--   L'ordine è "confirmed" ma il pagamento non verrà mai processato.
--   Il sistema è in uno stato INCONSISTENTE.
--
-- LA SOLUZIONE: Transactional Outbox Pattern
--   Invece di inviare il messaggio direttamente a RabbitMQ,
--   scriviamo il messaggio nella tabella outbox NELLA STESSA TRANSAZIONE
--   che aggiorna l'ordine. Dato che sono nella stessa transazione SQL,
--   o riescono entrambe o falliscono entrambe. Poi un processo separato
--   (il "relay") legge la tabella outbox e invia i messaggi a RabbitMQ.
--
--   Questo pattern è usato da aziende come Netflix, Uber, e Stripe.
--   Lo implementeremo nella Fase 6.

CREATE TABLE IF NOT EXISTS outbox (
    id           SERIAL PRIMARY KEY,
    event_type   VARCHAR(100) NOT NULL,
    payload      JSONB NOT NULL,
    processed    BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMP WITH TIME ZONE DEFAULT NOW(),
    processed_at TIMESTAMP WITH TIME ZONE
);

-- NOTA su JSONB:
--   È un tipo PostgreSQL che salva dati JSON in formato binario ottimizzato.
--   "B" sta per Binary. A differenza di JSON (testo), JSONB:
--   - È più veloce da leggere (già parsato)
--   - Supporta indici (puoi cercare dentro il JSON!)
--   - Occupa leggermente più spazio in scrittura
--   MySQL ha JSON ma non ha JSONB. Questo è uno dei vantaggi di PostgreSQL.

-- Indice parziale: indicizza SOLO le righe non ancora processate.
-- Questo è un "partial index", una feature potentissima di PostgreSQL
-- che MySQL non ha. Invece di indicizzare tutte le righe (anche quelle
-- già processate che non ci interessano più), indicizza solo quelle
-- che il relay deve ancora leggere. Meno spazio, più velocità.
CREATE INDEX IF NOT EXISTS idx_outbox_unprocessed
    ON outbox(created_at)
    WHERE processed = FALSE;


-- ============================================================
-- DATI DI TEST
-- ============================================================
-- Inseriamo alcuni prodotti per poter testare il sistema.
-- ON CONFLICT DO NOTHING: se il prodotto esiste già (stesso nome),
-- non fare nulla. Così possiamo rieseguire questo script senza errori.

INSERT INTO products (name, price, stock) VALUES
    ('Pannello Solare 400W', 299.99, 50),
    ('Inverter Ibrido 5kW', 1299.99, 20),
    ('Batteria Accumulo 10kWh', 4999.99, 10),
    ('Pompa di Calore 9kW', 3499.99, 15),
    ('Wallbox Ricarica EV 22kW', 899.99, 30)
ON CONFLICT DO NOTHING;