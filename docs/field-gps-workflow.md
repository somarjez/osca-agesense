# Field GPS Workflow

This workflow is for authorized OSCA staff who need to record a verified senior
location for internal GIS analysis.

## Open the Senior Profile

1. Sign in to AgeSense with an OSCA admin or encoder account.
2. Go to **Senior Records**.
3. Search for the senior citizen record.
4. Open the profile and choose **Edit**.

## Use the Coordinate Picker

The profile edit form includes a **Verified Location Pin** map section.

If the senior already has saved coordinates, the map shows a draggable marker at
that saved location. If no coordinates are saved yet, the map opens near the
senior's barangay or the center of Pagsanjan.

To set the location:

1. Click the map where the senior's verified location should be placed.
2. Drag the marker if the pin needs adjustment.
3. Confirm that the marker remains inside the Pagsanjan municipal boundary.
4. The latitude and longitude fields update automatically when the marker moves.
5. Save the profile.

On save, AgeSense marks the coordinate as a manual verified pin using:

- `location_source = manual_pin`
- `location_accuracy = verified/manual`
- `location_verified_at = current timestamp`

## Boundary Rule

The selected pin must be inside the Pagsanjan municipal boundary. If the pin is
outside the boundary, the form warns staff and prevents saving the coordinate.

## Privacy Rule

Exact coordinates are used only for authorized OSCA analysis. Public reports use
generalized locations.

Do not copy exact household coordinates into public reports, screenshots,
exports, or documents unless the data sharing is explicitly authorized. The GIS
Analytics public map popups should show only anonymized senior identifiers,
barangay, risk group, health group, and coordinate status.

## After Saving

After verified pins are saved, run:

```bash
php artisan gis:score-proximity
```

This updates senior accessibility metrics such as nearest facility distances and
GIS proximity score. The score is available for GIS analysis, but model
retraining is required before `gis_proximity_score` can be used as an ML feature.
