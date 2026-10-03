# Parehas nga Data sa laravel-blade-app (Membership Plans ug uban pa)

Para **magkaparehas ang data** sa laravel-vue-app ug laravel-blade-app (Membership Plans, Members, etc.), gamita ang **usa ra ka database** sa duha ka project.

## 1. Same database (recommended)

1. Ablihi ang **laravel-blade-app** `.env` ug kopyaha ang database settings:
   - `DB_CONNECTION`
   - `DB_HOST`
   - `DB_PORT`
   - `DB_DATABASE`
   - `DB_USERNAME`
   - `DB_PASSWORD`

2. Sa **laravel-vue-app** `.env`, ilisi ang samang DB settings (o i-paste ang bloc nga DB_* gikan sa Blade).

3. Human sa save:
   - Restart kung naa ka `php artisan serve` (Ctrl+C unya run balik).
   - Sa browser, **hard refresh** (Ctrl+F5) o close/reopen tab sa FitGym Admin.
   - Open **Membership Plans** — dapat parehas na ang data sa Blade (same plans, members, revenue).

4. Kung na-edit nimo ang code sa Vue (e.g. Admin.vue), run:
   ```bash
   npm run build
   ```
   o kung dev:
   ```bash
   npm run dev
   ```
   Human ato, refresh ang page.

## 2. Refresh button

Sa **Membership Plans** page sa Vue naa na **"Refresh"** button (tapad sa "Add New Plan"). I-click na kung gusto nimo i-reload ang data gikan sa database (e.g. human ug add/edit sa Blade).

## 3. Kung lahi gihapon

- Susihi nga **usa ra gyud** ang database (same `DB_DATABASE`, same MySQL/MariaDB server).
- Clear browser cache o try Incognito/Private window.
- Check sa browser DevTools (F12) → Network: kung mo-load ba ang `/api/membership-plans` ug `/api/reports/revenue` ug unsay response (200 + JSON array sa plans).
