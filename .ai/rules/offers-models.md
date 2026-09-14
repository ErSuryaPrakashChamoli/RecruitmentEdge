---
paths:
  - 'app/Services/OfferService.php,app/Filament/Resources/Offers/**,app/Models/OfferStatusHistory.php'
---

# Offers Models

## Offers: create via OfferService::create(); release needs offers.release in the service
Create offers only through OfferService::create() so the initial (null → Draft) status history row is written. OfferService::moveTo() to Released throws unless the acting employee's user has offers.release — UI hiding alone is not the guard. OfferStatusHistory is append-only (updating/deleting throws). Lapsed Released offers are expired by the daily offers:expire-lapsed command through moveTo(), so they also get history rows.
