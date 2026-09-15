<?php

namespace Drupal\cap_alerts_parks_australia\Plugin\EntityReferenceSelection;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Entity\Annotation\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;

/**
 * Restricts the Alert "Park" field to website gatsby_endpoint entities.
 *
 * Mobile-app endpoints share the same shortcode with an "_app" suffix (e.g.
 * "knp" / "knp_app"). Alerts only ever need to reference the website
 * endpoint; the aggregator normalises the "_app" suffix away when resolving
 * a park, so editors should never need to select both.
 *
 * Config entity queries only support a small fixed set of operators (no
 * "NOT LIKE"/negation), so "_app" entries are filtered out in PHP after the
 * query runs, rather than at the query level.
 *
 * @EntityReferenceSelection(
 *   id = "cap_alerts_parks_australia_gatsby_endpoint_website",
 *   label = @Translation("Gatsby endpoint (website only)"),
 *   entity_types = {"gatsby_endpoint"},
 *   group = "cap_alerts_parks_australia_gatsby_endpoint_website",
 *   weight = 0
 * )
 */
class GatsbyEndpointWebsiteSelection extends DefaultSelection {

  /**
   * {@inheritdoc}
   */
  public function getReferenceableEntities($match = NULL, $match_operator = 'CONTAINS', $limit = 0) {
    return $this->filterAppEndpoints(parent::getReferenceableEntities($match, $match_operator, $limit));
  }

  /**
   * {@inheritdoc}
   */
  public function validateReferenceableEntities(array $ids) {
    $validIds = parent::validateReferenceableEntities($ids);
    return array_values(array_filter($validIds, fn ($id) => !str_ends_with((string) $id, '_app')));
  }

  /**
   * Removes "_app" (mobile) gatsby_endpoint entries from a results array.
   */
  private function filterAppEndpoints(array $results): array {
    foreach ($results as $bundle => $options) {
      $results[$bundle] = array_filter(
        $options,
        fn ($id) => !str_ends_with((string) $id, '_app'),
        ARRAY_FILTER_USE_KEY
      );
    }
    return $results;
  }

}
