---
paths:
  - 'app/Http/Controllers/**,app/Providers/AppServiceProvider.php,config/filesystems.php'
---

# Controllers Providers

## Private disk is served only through files.private (SEC-88-17)
The `local` disk has serve => false (no storage/{path} route). Its temporary URLs (Filament FileUpload previews) are built by PrivateFileController::registerTemporaryUrls(): a 5-minute signed files.private URL bound to the issuing staff user, checked with EnforceStaffAccess + auth:web + EnsureStaffMfa, and audited as private_file_downloaded. The adapter binds the buildTemporaryUrlsUsing closure to itself, so inside it name the class explicitly — `self::` resolves to the adapter and recurses forever. Tests that Storage::fake('local') must call registerTemporaryUrls() again.
