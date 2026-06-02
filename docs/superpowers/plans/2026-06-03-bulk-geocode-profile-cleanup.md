# Bulk Geocode Profile Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the non-functional "Verified Location Pin" section from the senior profile edit form, strip all supporting backend code from the Livewire component, and delete the field-gps-workflow.md doc.

**Architecture:** Pure deletion — no new code is introduced. The profile form stops reading or writing coordinate columns entirely. The `gis:geocode` artisan command continues to populate approximate coordinates at the database level for GIS analytics, untouched.

**Tech Stack:** Laravel 11, Livewire 3, Blade

---

## File Map

| File | Change |
|------|--------|
| `resources/views/livewire/surveys/profile-survey.blade.php` | Remove UI section (lines 165–203) and entire JS block (lines 586–901) |
| `app/Livewire/Surveys/ProfileSurvey.php` | Remove imports, properties, fill logic, save logic, 6 private methods |
| `docs/field-gps-workflow.md` | Delete |

---

### Task 1: Remove the Verified Location Pin UI section from the blade

**Files:**
- Modify: `resources/views/livewire/surveys/profile-survey.blade.php`

- [ ] **Step 1: Remove the location pin section**

Open `resources/views/livewire/surveys/profile-survey.blade.php`. Find and delete the entire block below (starts at line 165, ends at line 203). This is the `@if ($senior)` block that wraps the map and lat/lng inputs:

```blade
            @if ($senior)
            <div class="mt-5 pt-4 border-t border-slate-100">
                <div class="flex items-start justify-between gap-4 mb-3">
                    <div>
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Verified Location Pin</p>
                        <p class="text-xs text-slate-500 mt-1">Exact coordinates are used only for authorized OSCA analysis. Public reports use generalized locations.</p>
                    </div>
                    <span data-location-status class="text-[11px] font-medium text-slate-500 text-right">Click the map to set a pin.</span>
                </div>

                <div
                    wire:ignore
                    data-location-picker
                    data-boundary-url="{{ route('api.gis.boundary.pagsanjan', [], false) }}"
                    data-latitude-input="senior-location-latitude"
                    data-longitude-input="senior-location-longitude"
                    data-touched-input="senior-location-pin-touched"
                    data-initial-latitude="{{ $latitude }}"
                    data-initial-longitude="{{ $longitude }}"
                    data-initial-barangay="{{ $barangay }}"
                    class="h-[320px] rounded-xl border border-slate-200 bg-slate-100 overflow-hidden">
                </div>

                <div class="grid grid-cols-2 gap-4 mt-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Latitude</label>
                        <input id="senior-location-latitude" type="number" step="0.0000001" wire:model="latitude"
                               class="w-full text-sm border rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 {{ $errors->has('latitude') ? 'border-red-400 bg-red-50' : 'border-slate-200' }}">
                        @error('latitude') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-600 mb-1">Longitude</label>
                        <input id="senior-location-longitude" type="number" step="0.0000001" wire:model="longitude"
                               class="w-full text-sm border rounded-lg px-3 py-2 focus:ring-2 focus:ring-teal-500 {{ $errors->has('longitude') ? 'border-red-400 bg-red-50' : 'border-slate-200' }}">
                        @error('longitude') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </div>
                </div>
                <input id="senior-location-pin-touched" type="hidden" wire:model="locationPinTouched">
            </div>
            @endif
```

Nothing replaces it — just delete it.

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/surveys/profile-survey.blade.php
git commit -m "feat(profile): remove Verified Location Pin UI section"
```

---

### Task 2: Remove the location picker JS block from the blade

**Files:**
- Modify: `resources/views/livewire/surveys/profile-survey.blade.php`

- [ ] **Step 1: Remove the entire JS block**

In the same blade file, find and delete the entire block below. It starts just after the closing `</div>` of the main component at line 585 and runs to the end of the file at line 901:

```blade
@once
@push('scripts')
<script>
(function () {
    const PAGSANJAN_CENTER = [14.2708, 121.4560];
    ...
    new MutationObserver(() => initializeLocationPickers()).observe(document.body, { childList: true, subtree: true });
})();
</script>
@endpush
@endonce
```

The block starts with `@once` and ends with `@endonce`. Delete all of it. The file should end at the `</div>` that closes the root Livewire component, with no script block following.

- [ ] **Step 2: Commit**

```bash
git add resources/views/livewire/surveys/profile-survey.blade.php
git commit -m "feat(profile): remove location picker JS"
```

---

### Task 3: Remove imports and properties from ProfileSurvey.php

**Files:**
- Modify: `app/Livewire/Surveys/ProfileSurvey.php`

- [ ] **Step 1: Remove the two unused imports**

At the top of `app/Livewire/Surveys/ProfileSurvey.php`, delete these two lines (they are only used by the pin methods being removed):

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
```

- [ ] **Step 2: Remove the three public properties**

Find and delete these three property declarations (around lines 53–57):

```php
    public ?string $latitude = null;

    public ?string $longitude = null;

    public bool $locationPinTouched = false;
```

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/Surveys/ProfileSurvey.php
git commit -m "feat(profile): remove lat/lng/locationPinTouched properties"
```

---

### Task 4: Remove coordinate fill logic from populateFromModel()

**Files:**
- Modify: `app/Livewire/Surveys/ProfileSurvey.php`

- [ ] **Step 1: Remove the coordinate fill block**

Inside `populateFromModel()`, find and delete this block (around lines 254–261):

```php
        if ($this->hasValidCoordinatePair($s->latitude, $s->longitude)) {
            $this->latitude = (string) $s->latitude;
            $this->longitude = (string) $s->longitude;
        } else {
            $this->latitude = null;
            $this->longitude = null;
        }
        $this->locationPinTouched = false;
```

The line before this block is `$this->bloodType = $s->blood_type ?? '';` and the line after is `$this->numChildren = $s->num_children;`. Delete only the block above; those surrounding lines stay.

- [ ] **Step 2: Commit**

```bash
git add app/Livewire/Surveys/ProfileSurvey.php
git commit -m "feat(profile): remove coordinate fill from populateFromModel"
```

---

### Task 5: Remove coordinate logic from save()

**Files:**
- Modify: `app/Livewire/Surveys/ProfileSurvey.php`

- [ ] **Step 1: Remove the validateLocationPin() call**

In `save()`, delete this single line (around line 153, just after `$this->validateCurrentStep();`):

```php
        $this->validateLocationPin();
```

- [ ] **Step 2: Remove the manual-pin save block**

In `save()`, find and delete the entire if/else block below (around lines 197–212, immediately after the `$data = [...]` array closing bracket):

```php
        if ($this->hasUsableLocationPin()) {
            $data['latitude'] = round((float) $this->latitude, 7);
            $data['longitude'] = round((float) $this->longitude, 7);

            if ($this->locationPinTouched || ! $this->senior?->location_source) {
                $data['location_source'] = 'manual_pin';
                $data['location_accuracy'] = 'verified/manual';
                $data['location_verified_at'] = now();
            }
        } else {
            $data['latitude'] = null;
            $data['longitude'] = null;
            $data['location_source'] = null;
            $data['location_accuracy'] = null;
            $data['location_verified_at'] = null;
        }
```

After deletion, the line `if ($this->senior) {` should follow immediately after the `$data = [...]` closing bracket.

- [ ] **Step 3: Commit**

```bash
git add app/Livewire/Surveys/ProfileSurvey.php
git commit -m "feat(profile): remove coordinate save logic from save()"
```

---

### Task 6: Remove the six private pin methods from ProfileSurvey.php

**Files:**
- Modify: `app/Livewire/Surveys/ProfileSurvey.php`

- [ ] **Step 1: Delete validateLocationPin()**

Find and delete the full method (around lines 303–336):

```php
    private function validateLocationPin(): void
    {
        $hasLatitude = $this->latitude !== null && $this->latitude !== '';
        $hasLongitude = $this->longitude !== null && $this->longitude !== '';

        if (! $hasLatitude && ! $hasLongitude) {
            return;
        }

        $this->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        if (! $this->hasUsableLocationPin()) {
            $message = 'Click inside Pagsanjan to set a valid verified location pin.';
            $this->addError('latitude', $message);
            $this->addError('longitude', $message);
            throw ValidationException::withMessages([
                'latitude' => $message,
                'longitude' => $message,
            ]);
        }

        if (! $this->pointIsInsidePagsanjan((float) $this->longitude, (float) $this->latitude)) {
            $message = 'Selected location must be inside the Pagsanjan municipal boundary.';
            $this->addError('latitude', $message);
            $this->addError('longitude', $message);
            throw ValidationException::withMessages([
                'latitude' => $message,
                'longitude' => $message,
            ]);
        }
    }
```

- [ ] **Step 2: Delete hasUsableLocationPin()**

```php
    private function hasUsableLocationPin(): bool
    {
        return $this->hasValidCoordinatePair($this->latitude, $this->longitude);
    }
```

- [ ] **Step 3: Delete hasValidCoordinatePair()**

```php
    private function hasValidCoordinatePair(mixed $latitude, mixed $longitude): bool
    {
        if ($latitude === null || $latitude === '' || $longitude === null || $longitude === '') {
            return false;
        }

        $lat = filter_var($latitude, FILTER_VALIDATE_FLOAT);
        $lng = filter_var($longitude, FILTER_VALIDATE_FLOAT);

        if ($lat === false || $lng === false) {
            return false;
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return false;
        }

        return abs((float) $lat) >= 0.000001 && abs((float) $lng) >= 0.000001;
    }
```

- [ ] **Step 4: Delete pointIsInsidePagsanjan()**

```php
    private function pointIsInsidePagsanjan(float $longitude, float $latitude): bool
    {
        $path = 'gis/boundaries/pagsanjan_boundary.geojson';
        if (! Storage::disk('local')->exists($path)) {
            return true;
        }

        $geoJson = json_decode(Storage::disk('local')->get($path), true);
        if (! is_array($geoJson) || ! isset($geoJson['features']) || ! is_array($geoJson['features'])) {
            return true;
        }

        foreach ($geoJson['features'] as $feature) {
            $geometry = $feature['geometry'] ?? null;
            $coordinates = $geometry['coordinates'] ?? null;

            if (($geometry['type'] ?? null) === 'Polygon' && $this->pointInPolygon([$longitude, $latitude], $coordinates)) {
                return true;
            }

            if (($geometry['type'] ?? null) === 'MultiPolygon' && is_array($coordinates)) {
                foreach ($coordinates as $polygon) {
                    if ($this->pointInPolygon([$longitude, $latitude], $polygon)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
```

- [ ] **Step 5: Delete pointInPolygon()**

```php
    private function pointInPolygon(array $point, mixed $polygonCoordinates): bool
    {
        if (! is_array($polygonCoordinates) || ! isset($polygonCoordinates[0]) || ! is_array($polygonCoordinates[0])) {
            return false;
        }

        if (! $this->pointInRing($point, $polygonCoordinates[0])) {
            return false;
        }

        foreach (array_slice($polygonCoordinates, 1) as $hole) {
            if (is_array($hole) && $this->pointInRing($point, $hole)) {
                return false;
            }
        }

        return true;
    }
```

- [ ] **Step 6: Delete pointInRing()**

```php
    private function pointInRing(array $point, array $ring): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) ($ring[$i][0] ?? 0);
            $yi = (float) ($ring[$i][1] ?? 0);
            $xj = (float) ($ring[$j][0] ?? 0);
            $yj = (float) ($ring[$j][1] ?? 0);

            $intersects = (($yi > $point[1]) !== ($yj > $point[1]))
                && ($point[0] < (($xj - $xi) * ($point[1] - $yi)) / (($yj - $yi) ?: PHP_FLOAT_EPSILON) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
```

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Surveys/ProfileSurvey.php
git commit -m "feat(profile): remove pin validation private methods"
```

---

### Task 7: Delete field-gps-workflow.md

**Files:**
- Delete: `docs/field-gps-workflow.md`

- [ ] **Step 1: Delete the file**

```bash
git rm docs/field-gps-workflow.md
```

- [ ] **Step 2: Commit**

```bash
git commit -m "docs: delete field-gps-workflow (workflow not implemented)"
```

---

### Task 8: Smoke test

**Files:** none

- [ ] **Step 1: Start the dev server**

```bash
php artisan serve
```

- [ ] **Step 2: Open a senior profile edit form**

Navigate to a senior record and open the profile edit form. Verify:
- No "Verified Location Pin" section appears anywhere in the form
- No Leaflet map is rendered
- No lat/lng input fields are visible
- The form still renders all other sections correctly (Identifying Information, Family Composition, etc.)

- [ ] **Step 3: Save the profile**

Fill in any required field change and click **Save Profile**. Verify:
- The save completes without error
- The success flash message appears: `"Senior citizen profile saved. OSCA ID: ..."`
- No PHP errors about undefined properties `$latitude`, `$longitude`, or `$locationPinTouched`

- [ ] **Step 4: Confirm GIS analytics still work**

Navigate to the GIS Analytics report. Verify the heatmap and senior plots still render (they read coordinates from the model directly, unaffected by this change).

- [ ] **Step 5: Final commit if any cleanup needed**

If no issues, no commit needed. If any stray reference was missed, fix and commit:

```bash
git add -p
git commit -m "fix(profile): remove stray location pin references"
```
