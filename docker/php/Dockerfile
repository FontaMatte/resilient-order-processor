# Partiamo da un'immagine ufficiale PHP 8.2.
# "cli" significa che è la versione a riga di comando (non PHP-FPM).
# Usiamo questa perché per sviluppo il server built-in di PHP è più che sufficiente.
FROM php:8.2-cli

# Installiamo le dipendenze di sistema necessarie per le estensioni PHP.
# Ogni riga ha un motivo preciso:
#   - libpq-dev: librerie C per connettersi a PostgreSQL (servono a pdo_pgsql)
#   - librabbitmq-dev: librerie C per connettersi a RabbitMQ (servono a amqp)
#   - unzip: serve a Composer per estrarre i pacchetti
#   - git: serve a Composer per scaricare pacchetti da repository Git
RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Installiamo le estensioni PHP necessarie:
#   - pdo: PHP Data Objects, l'interfaccia standard per parlare coi database
#   - pdo_pgsql: il driver PDO specifico per PostgreSQL
#   - sockets: per la comunicazione di rete con RabbitMQ
#   - bcmath: per calcoli precisi su numeri decimali (prezzi, soldi)
RUN docker-php-ext-install pdo pdo_pgsql sockets bcmath pcntl

# Installiamo Composer scaricandolo direttamente.
# Il metodo "COPY --from=composer:2" a volte rompe perché il percorso
# del binario cambia tra versioni dell'immagine.
# Questo metodo è più affidabile: scarica l'installer ufficiale,
# lo verifica, e lo installa in /usr/local/bin.
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# La directory di lavoro dentro il container.
# Ogni comando successivo (e il CMD finale) partirà da qui.
WORKDIR /app

# Esponiamo la porta 8080 (informativo, non apre realmente la porta)
EXPOSE 8080

# Il comando che viene eseguito quando il container parte.
# "-S 0.0.0.0:8080" avvia il server built-in di PHP su tutte le interfacce, porta 8080.
# "-t public" dice che la cartella "public" è la document root (dove sta index.php).
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
