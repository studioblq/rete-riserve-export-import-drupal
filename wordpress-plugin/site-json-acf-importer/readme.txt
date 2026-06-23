=== Site JSON ACF Importer ===
Contributors: custom
Tags: import, json, acf, drupal
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.5.19
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Importa contenuti JSON da endpoint esterno (es. Drupal) e li salva in WordPress, con mapping campi ACF Pro.

== Installazione ==
1. Copia la cartella site-json-acf-importer in wp-content/plugins/
2. Attiva il plugin da Plugin
3. Vai su Strumenti > Site JSON Importer
4. Configura URL endpoint e opzioni base
5. Esegui "Step 1: Import JSON grezzo"
6. Configura il mapping dalla UI (tipo sorgente -> post type e campi sorgente -> ACF)
7. Esegui "Step 2: Applica mapping ai contenuti importati"
8. Opzionale: attiva la modalita parent a 2 passaggi, indicando campo sorgente parent e campo di merge

== Note ==
- Se ACF Pro non e presente, i contenuti vengono comunque importati su titolo/contenuto/excerpt e metadati.
- Lo Step 1 salva anche il payload grezzo per ogni contenuto, utile per applicare/ripetere mapping senza dover reimportare dal JSON.
- Il plugin usa uuid/nid per aggiornare contenuti gia importati.
- In caso di errore HTTP 403, verifica i permessi dell'endpoint Drupal e usa, se necessario, Bearer token/User-Agent configurabili nel plugin.
- Nel mapping UI puoi scegliere destinazioni WordPress core, campi Yoast SEO e campi ACF.
- Per campi sorgente complessi (array/oggetti), puoi scegliere anche quale elemento interno usare (es: value, [0].url, [*].target_id).
- Nello Step 2 puoi aprire un'anteprima dei dati reali del JSON di test e scorrere i record con i pulsanti Precedente/Successivo.
- Se abiliti il parent linking, lo Step 1 importa prima tutti i contenuti e poi esegue un secondo giro per valorizzare post_parent.

== Changelog ==

= 1.5.19 =
* IMPROVEMENT: Spostata la pagina plugin sotto menu admin condiviso "Area051"
* FIX: Redirect post-salvataggio/import aggiornati verso admin.php?page=site-json-acf-importer

= 1.5.17 =
* FIX: Riattivato il sideload immagini nel flusso di import asincrono (con limiti tempo/tentativi gia presenti)
* FIX: La featured image mappata da field immagine torna a essere importata anche con Importa dati (asincrono)

= 1.5.16 =
* FEATURE: Nuovo target mapping wp:post_date per importare la data pubblicazione WordPress dal campo Drupal
* IMPROVEMENT: Parsing data robusto (datetime/stringa/timestamp unix, anche in millisecondi)

= 1.5.15 =
* FEATURE: Supporto mapping categorie/tag WordPress durante import (target wp:categories e wp:post_tag)
* FEATURE: Creazione automatica termini mancanti e assegnazione al post importato
* FEATURE: Supporto anche a tassonomie custom tramite target avanzato wp:taxonomy:nome_tassonomia

= 1.5.14 =
* FIX CRITICO: assign_parents_second_pass riscritta per usare dati live dall'endpoint Drupal invece di _sjai_raw_item (spesso mancante o non decodificabile)
* FIX: Fallback su _sjai_raw_item ancora presente se endpoint non disponibile
* FIX: Lookup post_id child tramite _sjai_source_nid invece di meta corrotto

= 1.5.13 =
* IMPROVEMENT: Aggiunti log mirati per il second pass parent linking su debug.log con riferimenti, match e post WP trovato

= 1.5.12 =
* FIX CRITICO: Il second pass parent ora scrive anche l'ID WordPress risolto nel campo sorgente del record, non solo post_parent
* IMPROVEMENT: Memorizzato anche `_sjai_parent_wp_id` per debug e verifica rapida

= 1.5.11 =
* FIX: La preview JSON ora mostra il record completo selezionato invece del solo campo specifico

= 1.5.10 =
* FIX CRITICO: Messa in sicurezza serializzazione preview JSON nel blocco script admin (escape di </script>) per evitare pagina admin "sballata" con contenuti HTML dal payload

= 1.5.9 =
* FIX: Parent linking più robusto con fallback su meta key multipli (_sjai_source_* e _sjai_src_*) e normalizzazione valori numerici/stringa
* IMPROVEMENT: Error reporting parent linking ora include i riferimenti non trovati per debug rapido
* FEATURE: Anteprima JSON estesa a tutti i record (rimosso limite 25 per tipo)
* FEATURE: Anteprima con input numerico + pulsante Vai per saltare direttamente al record desiderato

= 1.5.8 =
* FIX CRITICO: Disabilitato sideload immagini durante sjai_process_batch per evitare fatal timeout GD (30s) su shared hosting
* FIX: Async batch continua a importare contenuti e mapping testuali senza bloccare la risposta JSON

= 1.5.7 =
* FIX CRITICO: Hardened async batch contro timeout hosting: batch size ridotto a 1 e budget tempo per singola request
* FIX: Limitati sideload immagini per request (max 3) con deadline interna per evitare risposte HTML da fatal timeout
* FIX: Ridotto timeout fetch endpoint a 20s e timeout download immagine a 10s per stabilita su shared hosting

= 1.5.6 =
* FIX CRITICO: Riscritta render_async_import_js con heredoc per evitare parse error da escape/quote nel blocco JavaScript inline
* FIX: Rimosso punto fragile a catena di echo che poteva rompere il parsing PHP su alcuni deploy

= 1.5.5 =
* FIX CRITICO: Aggiunta guardia shutdown su endpoint AJAX async per intercettare fatal error e restituire sempre JSON valido
* FIX: Endpoint async ora inizializzano output JSON con helper unico (header, buffer reset, display_errors off)
* FIX: Check nonce esplicito con risposta JSON dedicata (evita output HTML in caso di nonce invalido)

= 1.5.4 =
* FIX CRITICO: Aggiunto header JSON content-type prima di OGNI output per force browser parsing como JSON
* FIX CRITICO: Implementato while(@ob_end_clean()) per clearare TUTTI i buffer precedenti non catturati
* FIX: Aggiunto error suppression operator (@) su funzioni che potrebbero generare warnings (check_ajax_referer, fetch_endpoint_data)
* FIX: Aggiunto transient validation con check is_array prima di usare state
* IMPROVEMENT: Entrambi gli endpoint AJAX (start e process) ora hanno protezione output buffer identica
* IMPROVEMENT: Cambiato Exception a \Throwable nei catch per catturare anche ParseError e TypeError

= 1.5.3 =
* FIX: Aggiunto ob_start/ob_end_clean in ajax_process_batch per evitare output HTML prima del JSON
* FIX: Wrap try-catch attorno al loop di import batch per catturare errori PHP
* FIX: Validazione robusta di payload prima di elaborare dati
* IMPROVEMENT: Errori PHP ora catturati e loggati invece di breakare il JSON

= 1.5.2 =
* FIX CRITICO: Rimosso dataset intero dal transient state - causava JSON parsing error su batch successivi
* REFACTOR: Async ora refetch dati da endpoint ad ogni batch invece di mantenerli in memoria
* FIX: Evitato problema di serializzazione transient con array enormi
* IMPROVEMENT: Ridotto consumo memoria e transient storage per import grandi

= 1.5.1 =
* FIX: Corretto avanzamento batch async con cursore persistente nel transient
* FIX: Evitato reprocessing degli stessi record ai batch successivi
* FIX: Corretto criterio di completamento import async su fine dataset/range

= 1.5.0 =
* FIX: Corretto bug critico: il source_type '0' (valore PHP falsy) non veniva riconosciuto nel type_map, causando import sempre nel post type di default
* FIX: find_existing_post ora cerca anche tra tutti i post_type ('any') come fallback, così il re-import aggiorna correttamente i post già importati con tipo diverso
* FEATURE: Re-import converte automaticamente i post esistenti nel post_type corretto senza creare duplicati

= 1.4.2 =
* FIX: Corretto parse error in class-sjai-importer.php (apice in eccesso nella renderizzazione JS)
* FIX: Ripristinato caricamento pannello plugin senza errore critico

= 1.4.1 =
* FIX: Corretto errore critico nella renderizzazione JavaScript dell'async import
* FIX: Rimosso carattere di escape errato nei dati JavaScript renderizzati

= 1.4.0 =
* Feature: Sistema di import asincrono con progress bar
* Feature: Auto-retry su errori di rete (max 3 tentativi)
* Feature: Persistenza sessione in localStorage (ripresa da interruzione)
* Feature: Metriche real-time (velocità, tempo trascorso, stima tempo rimanente)
* Improvement: UI progress bar con gradient e animazione
