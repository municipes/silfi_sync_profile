# Silfi Sync Profile

## Panoramica

Il modulo **Silfi Sync Profile** per Drupal 10/11 sincronizza automaticamente i dati del profilo utente dal servizio OpenCity quando gli utenti accedono alla pagina di prenotazione appuntamenti. Il modulo è stato progettato per integrarsi seamlessly con il sistema di autenticazione WSO2 e fornire un'esperienza utente fluida mantenendo i dati del profilo aggiornati.

## Caratteristiche

### 🔄 Sincronizzazione Automatica
- **Attivazione automatica**: La sincronizzazione si avvia quando un utente autenticato visita `/servizi/prenotazione-appuntamenti/new`
- **Controllo temporale**: Evita sincronizzazioni eccessive con un cooldown di 30 minuti per utente
- **Processo trasparente**: L'utente non si accorge della sincronizzazione in corso

### 🔐 Integrazione WSO2
- **Token JWT**: Utilizza i token di autenticazione WSO2 per le chiamate API
- **Sicurezza**: Rispetta le configurazioni SSL del modulo WSO2 Auth
- **Dipendenza gestita**: Richiede il modulo wso2_auth per funzionare

### 📊 Aggiornamento Dati Profilo
- **Telefono cellulare**: Sincronizza `field_user_mobilephone`
- **Email**: Sincronizza `field_user_mail`
- **Aggiornamento intelligente**: Modifica solo i campi che sono effettivamente cambiati

### 📝 Logging Completo
- **Canale dedicato**: Utilizza il canale di log `silfi_sync_profile`
- **Livelli appropriati**: Info, warning ed errori per diversi scenari
- **Debug dettagliato**: Tracciamento completo del processo di sincronizzazione

## Requisiti

- **Drupal**: 10.x o 11.1+
- **PHP**: 8.1+ (8.3 raccomandato per Drupal 11)
- **Moduli richiesti**:
  - [wso2_auth](../wso2_auth) - Modulo di autenticazione WSO2

### Campi Utente Richiesti

Il modulo sincronizza i seguenti campi del profilo utente. Assicurati che esistano:

- `field_user_fiscalcode` - Codice fiscale (utilizzato per identificare l'utente nell'API)
- `field_user_mobilephone` - Numero di telefono cellulare
- `field_user_mail` - Indirizzo email

## Installazione

### Via Composer (Raccomandato)

```bash
# Se il modulo è in un repository Git
composer require your-org/silfi_sync_profile

# Attiva il modulo
drush en silfi_sync_profile
```

### Installazione Manuale

1. Scarica o clona il repository nella cartella `modules/custom/`:

```bash
cd /path/to/drupal/modules/custom
git clone https://github.com/your-org/silfi_sync_profile.git
```

2. Attiva il modulo:

```bash
drush en silfi_sync_profile
```

Oppure tramite interfaccia web: Amministrazione › Estendibilità › Installa

## Configurazione

Il modulo **non richiede configurazione** - funziona automaticamente utilizzando:

- Le configurazioni del modulo WSO2 Auth per l'autenticazione
- Le impostazioni SSL ereditate da WSO2 Auth
- I dati di sessione WSO2 esistenti

### Verifica Configurazione

Per verificare che tutto sia configurato correttamente:

1. **Controlla WSO2 Auth**: Assicurati che il modulo wso2_auth sia configurato e funzionante
2. **Verifica campi utente**: Controlla che i campi richiesti esistano nell'entità utente
3. **Test di accesso**: Visita `/servizi/prenotazione-appuntamenti/new` con un utente autenticato

## Funzionamento Tecnico

### Flusso di Sincronizzazione

1. **Intercettazione Richiesta**: Event subscriber intercetta le richieste a `/servizi/prenotazione-appuntamenti/new`
2. **Controlli Preliminari**:
   - Utente autenticato ✓
   - Sincronizzazione non eseguita negli ultimi 30 minuti ✓
3. **Recupero Dati**:
   - Token JWT dalla sessione WSO2
   - Codice fiscale dal profilo utente
4. **Chiamata API**: GET request a OpenCity con headers appropriati
5. **Aggiornamento Profilo**: Sincronizzazione dati se diversi da quelli esistenti
6. **Timestamp**: Memorizzazione timestamp per controllo temporale futuro

### API OpenCity

**Endpoint**: `https://api.055055.it:8243/opencity/1.0/utente/{codice_fiscale}`

**Headers**:
- `accept: application/json`
- `X-JWT-Assertion: dsfdsf`
- `Authorization: Bearer {wso2_token}`

**Mappatura Dati**:
```php
// Risposta OpenCity → Campo Drupal
$data['personaOpencity']['cellulare'] → field_user_mobilephone
$data['personaOpencity']['email'] → field_user_mail
```

## Monitoraggio e Debug

### Log Drupal

Il modulo utilizza il canale di log `silfi_sync_profile`. Per monitorare l'attività:

```bash
# Via Drush
drush watchdog-show --filter=silfi_sync_profile

# O controlla i log via interfaccia web
# Amministrazione › Rapporti › Log eventi recenti
```

### Livelli di Log

- **Info**: Sincronizzazioni riuscite, aggiornamenti campi
- **Warning**: Token mancanti, codice fiscale non disponibile, risposte API non riuscite
- **Error**: Errori HTTP, problemi di parsing JSON, errori di salvataggio

## Sviluppo e Personalizzazione

### Estendere la Sincronizzazione

Per sincronizzare campi aggiuntivi, modifica il metodo `updateUserProfile()` in `ProfileSyncService.php`:

```php
// Esempio: aggiungere sincronizzazione data di nascita
if (isset($persona_data['dataNascita']) &&
    $user->hasField('field_user_birthdate')) {
    $user->set('field_user_birthdate', $persona_data['dataNascita']);
    $updated = TRUE;
}
```

### Test Personalizzati

Per testare il modulo in un ambiente diverso, modifica l'URL base in `ProfileSyncService.php`:

```php
// Per ambiente di staging
protected $apiBaseUrl = 'https://api-staging.055055.it:8243/opencity/1.0';
```

## Compatibilità

- ✅ Drupal 10.x
- ✅ Drupal 11.1+
- ✅ PHP 8.1, 8.2, 8.3
- ✅ MySQL 5.7+, MariaDB 10.3+
- ✅ PostgreSQL 10+

## Licenza

Questo modulo è rilasciato sotto licenza [GNU General Public License v3](https://www.gnu.org/licenses/gpl-3.0.html).

## Changelog

### 1.0.0
- Prima release
- Sincronizzazione automatica su path specifico
- Integrazione completa con WSO2 Auth
- Supporto per Drupal 10 e 11.1+
- Sistema di logging completo
- Rate limiting con cooldown di 30 minuti
