# Production perf checklist — frontend rendering optimization

Deployment steps (not committed app behavior). Run on the deploy host after pulling this branch.

## Build & Laravel caches
- [ ] `npm ci && npm run build` — deploy the fingerprinted `public/build` assets
- [ ] `php artisan optimize` (caches config + routes) — or individually `config:cache` + `route:cache`
- [ ] `php artisan view:cache` (compiles Blade; already verified to compile clean on this branch)
- [ ] `APP_DEBUG=false` in the production `.env`
- [ ] After ANY `.env`/config edit: `php artisan config:clear` then re-cache

## Asset caching
- [ ] Confirm the web server serves `public/build/assets/*` (hashed filenames) with long-lived `Cache-Control: public, max-age=31536000, immutable` — filenames change on rebuild, so caching is safe

## Runtime smoke test (browser — the checks the build session could not perform)
Open DevTools → Network (JS filter), throttle to "Fast 3G" to make chunk loads visible:
- [ ] **Login page** requests the app.js chunk only — NOT the Chart.js (`auto-*.js`) or Leaflet (`leaflet-src-*.js`) chunks
- [ ] **Dashboard** requests app.js + Chart.js, NOT Leaflet; all charts render; charts appear just after first paint (lazy)
- [ ] **GIS Analytics** requests app.js + Leaflet chunks (+ markercluster/heat), NOT Chart.js; map renders
- [ ] **Senior profile** with coordinates: mini-map renders (Leaflet chunk requested)
- [ ] **Section switches** (Dashboard→Seniors→GIS→Risk→Dashboard) issue XHR/`fetch`, NOT full-document loads; no white flash
- [ ] Navigate Dashboard→GIS→Dashboard twice: no "canvas already in use" console error; charts + map re-render each time; sidebar-collapse + dark-mode persist
- [ ] **ML non-blocking:** stop the Flask ML services, then load the dashboard — the page must render immediately (no multi-second stall); the topbar "Services" dot updates to offline a moment later via the async `/ml/nav-health` fetch
- [ ] **Reports → Risk:** a skeleton placeholder paints first, then the RiskReport table streams in (lazy); filters are not reset after it loads

## Rollback
- All changes are on branch `perf/frontend-rendering-optimization`; revert the branch or individual commits (each task is one commit) if any smoke check fails.
