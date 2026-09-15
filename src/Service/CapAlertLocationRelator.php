<?php

declare(strict_types=1);

namespace Drupal\cap_alerts_parks_australia\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Auto-relates CAP Alert nodes to Location nodes by comparing geodata.
 *
 * This only ever adds Locations that are geographically inside an Alert's
 * polygon/circle area; it never removes entries, since the same field also
 * accepts manually-added Locations (e.g. for Alerts with no coordinates, or
 * where a Location falls outside the affected area but is still impacted).
 *
 * Uses itamair/geophp's globally-autoloaded classes (\geoPHP, \Point, etc.)
 * directly, rather than the `geophp` contrib module's GeoPHPWrapper service:
 * `geofield` already depends on itamair/geophp directly (autoloaded via
 * Composer's classmap), and GeoPHPWrapper's require_once of its own bundled
 * copy of the same classes fatals with "Cannot redeclare class" once both
 * are loaded in the same request.
 */
final class CapAlertLocationRelator {

  /**
   * The Alert content type's machine name.
   */
  private const ALERT_BUNDLE = 'cap_alert_message';

  /**
   * The Location content type's machine name.
   */
  private const LOCATION_BUNDLE = 'location';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Relates an Alert node to any Locations inside its polygon/circle area.
   *
   * @param \Drupal\node\NodeInterface $alert
   *   The CAP Alert node being saved.
   */
  public function relate(NodeInterface $alert): void {
    if ($alert->bundle() !== self::ALERT_BUNDLE || !$alert->hasField('field_location_reference')) {
      return;
    }

    $parkIds = $this->getReferencedIds($alert, 'field_site');
    if (!$parkIds) {
      return;
    }

    $areaGeometries = $this->getAlertAreaGeometries($alert);
    if (!$areaGeometries) {
      return;
    }

    $matchedLocationIds = [];
    foreach ($this->getLocationsForParks($parkIds) as $location) {
      $point = $this->getLocationPoint($location);
      if ($point === NULL) {
        continue;
      }
      foreach ($areaGeometries as $geometry) {
        try {
          if ($geometry->pointInPolygon($point)) {
            $matchedLocationIds[] = (int) $location->id();
            break;
          }
        }
        catch (\Throwable $e) {
          $this->logger->warning('CAP Alert @id: geometry containment check failed: @message', [
            '@id' => $alert->id(),
            '@message' => $e->getMessage(),
          ]);
        }
      }
    }

    if (!$matchedLocationIds) {
      return;
    }

    $existingIds = array_map('intval', $this->getReferencedIds($alert, 'field_location_reference'));
    $mergedIds = array_values(array_unique(array_merge($existingIds, $matchedLocationIds)));
    if ($mergedIds !== $existingIds) {
      $alert->set('field_location_reference', $mergedIds);
    }
  }

  /**
   * Gets the target entity IDs referenced by an entity-reference field.
   *
   * IDs are returned as stored (e.g. gatsby_endpoint IDs are strings like
   * "knp"; node IDs are numeric) — callers should cast as appropriate for
   * the specific field being read.
   *
   * @return array<int, string|int>
   *   The referenced entity IDs.
   */
  private function getReferencedIds(NodeInterface $node, string $fieldName): array {
    if (!$node->hasField($fieldName)) {
      return [];
    }
    $ids = [];
    foreach ($node->get($fieldName)->getValue() as $item) {
      if (isset($item['target_id']) && $item['target_id'] !== '') {
        $ids[] = $item['target_id'];
      }
    }
    return $ids;
  }

  /**
   * Builds geoPHP geometries for every polygon/circle in an Alert's area(s).
   *
   * @return \Geometry[]
   *   One geometry per polygon/circle found across all info/area paragraphs.
   */
  private function getAlertAreaGeometries(NodeInterface $alert): array {
    $geometries = [];
    if (!$alert->hasField('field_cap_alert_info')) {
      return $geometries;
    }

    foreach ($alert->get('field_cap_alert_info')->referencedEntities() as $info) {
      if (!$info->hasField('field_cap_area')) {
        continue;
      }
      foreach ($info->get('field_cap_area')->referencedEntities() as $area) {
        if ($area->hasField('field_cap_polygon') && !$area->get('field_cap_polygon')->isEmpty()) {
          $wkt = $area->get('field_cap_polygon')->value;
          $geometry = $this->loadWkt($wkt);
          if ($geometry) {
            $geometries[] = $geometry;
          }
        }
        if ($area->hasField('field_cap_circles')) {
          foreach ($area->get('field_cap_circles')->referencedEntities() as $circleParagraph) {
            if (!$circleParagraph->hasField('field_cap_circle') || $circleParagraph->get('field_cap_circle')->isEmpty()) {
              continue;
            }
            $geometry = $this->buildCirclePolygon((string) $circleParagraph->get('field_cap_circle')->value);
            if ($geometry) {
              $geometries[] = $geometry;
            }
          }
        }
      }
    }

    return $geometries;
  }

  /**
   * Loads a WKT string into a geoPHP geometry, or NULL if invalid.
   */
  private function loadWkt(?string $wkt): ?object {
    if (!$wkt) {
      return NULL;
    }
    try {
      $geometry = \geoPHP::load($wkt, 'wkt');
      return $geometry ?: NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Failed to parse CAP polygon WKT: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Approximates a CAP circle ("lat,lon radius_km") as a polygon geometry.
   */
  private function buildCirclePolygon(string $capCircle): ?object {
    // CAP circle format: "lat,lon radius" (radius in km).
    if (!preg_match('/^\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s+(-?[\d.]+)\s*$/', $capCircle, $matches)) {
      return NULL;
    }
    [, $lat, $lon, $radiusKm] = $matches;
    $lat = (float) $lat;
    $lon = (float) $lon;
    $radiusKm = (float) $radiusKm;
    if ($radiusKm <= 0) {
      return NULL;
    }

    // Build a 32-point polygon approximating the circle, since geoPHP has no
    // native circle geometry.
    $earthRadiusKm = 6371.0;
    $points = [];
    $steps = 32;
    for ($i = 0; $i <= $steps; $i++) {
      $bearing = deg2rad(($i / $steps) * 360);
      $angularDistance = $radiusKm / $earthRadiusKm;
      $latRad = deg2rad($lat);
      $lonRad = deg2rad($lon);
      $pointLatRad = asin(sin($latRad) * cos($angularDistance) + cos($latRad) * sin($angularDistance) * cos($bearing));
      $pointLonRad = $lonRad + atan2(
        sin($bearing) * sin($angularDistance) * cos($latRad),
        cos($angularDistance) - sin($latRad) * sin($pointLatRad)
      );
      $points[] = sprintf('%F %F', rad2deg($pointLonRad), rad2deg($pointLatRad));
    }

    return $this->loadWkt(sprintf('POLYGON((%s))', implode(', ', $points)));
  }

  /**
   * Gets a Location node's coordinates as a geoPHP Point geometry.
   */
  private function getLocationPoint(NodeInterface $location): ?object {
    if (!$location->hasField('field_latitude') || !$location->hasField('field_longitude')) {
      return NULL;
    }
    $lat = $location->get('field_latitude')->isEmpty() ? NULL : (float) $location->get('field_latitude')->value;
    $lon = $location->get('field_longitude')->isEmpty() ? NULL : (float) $location->get('field_longitude')->value;
    if ($lat === NULL || $lon === NULL) {
      return NULL;
    }
    return $this->loadWkt(sprintf('POINT(%F %F)', $lon, $lat));
  }

  /**
   * Loads all Location nodes sharing at least one of the given park IDs.
   *
   * @param int[] $parkIds
   *   Gatsby endpoint entity IDs referenced by field_site.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function getLocationsForParks(array $parkIds): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->condition('type', self::LOCATION_BUNDLE)
      ->condition('field_site', $parkIds, 'IN')
      ->accessCheck(FALSE);
    $ids = $query->execute();
    return $ids ? array_values($storage->loadMultiple($ids)) : [];
  }

}
