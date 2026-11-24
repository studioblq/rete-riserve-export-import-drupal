=== Area051 WebP Converter ===
Contributors: area051
Tags: webp, images, compression, media
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Converte in WebP compresso le immagini JPEG e PNG della libreria media.

== Descrizione ==
Pannello in Area051 WP > WebP Converter:

* elenco delle immagini della libreria (da convertire, convertite, saltate);
* conversione singola o in blocco dell'originale e di tutte le miniature, con qualità regolabile;
* backup opzionale in wp-content/area051-webp-backup/ con la stessa struttura di cartelle di uploads;
* opzione per mantenere i file originali accanto ai WebP;
* aggiornamento degli indirizzi nei contenuti (pagine, articoli, Divi);
* redirect 301 dai vecchi indirizzi .jpg/.png al WebP;
* ripristino dell'originale per ogni immagine (serve il backup o l'originale mantenuto).

Il redirect funziona quando il server passa a WordPress le richieste di file mancanti (configurazione standard di WordPress su Apache). Su Nginx serve una regola nel server.

== Changelog ==

= 1.1.0 =
* NEW: Conversione e ripristino senza ricaricare la pagina, con icona rotante e riga aggiornata (anteprima, link, peso)
* NEW: Misura massima in larghezza (orizzontali) e altezza (verticali), in proporzione
* FIX: Orientamento EXIF applicato prima della conversione, redirect esatto dalla mappa, blocco contro doppie lavorazioni

= 1.0.0 =
* Prima versione
