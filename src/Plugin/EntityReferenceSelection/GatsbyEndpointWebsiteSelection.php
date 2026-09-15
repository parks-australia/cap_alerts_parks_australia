<?php

namespace Drupal\cap_alerts_parks_australia\Plugin\EntityReferenceSelection;

use Drupal\Core\Annotation\Translation;
use Drupal\Core\Entity\Annotation\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\Entity\Query\QueryInterface;

/**
 * Restricts the Alert "Park" field to website gatsby_endpoint entities.
 *
 * Mobile-app endpoints share the same shortcode with an "_app" suffix (e.g.
 * "knp" / "knp_app"). Alerts only ever need to reference the website
 * endpoint; the aggregator normalises the "_app" suffix away when resolving
 * a park, so editors should never need to select both.
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
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS'): QueryInterface {
    $query = parent::buildEntityQuery($match, $match_operator);
    $query->condition('id', '%\_app', 'NOT LIKE');
    return $query;
  }

}
