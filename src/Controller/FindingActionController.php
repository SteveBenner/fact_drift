<?php

namespace Drupal\fact_drift\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager;
use Drupal\fact_drift\Entity\Finding;
use Drupal\fact_drift\FindingRecorder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Changes findings from the report.
 *
 * @phpstan-consistent-constructor
 */
class FindingActionController extends ControllerBase {

  /**
   * Constructs a FindingActionController.
   *
   * @param \Drupal\fact_drift\FindingRecorder $recorder
   *   The finding recorder.
   * @param \Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager $extractorManager
   *   The claim extractor plugin manager.
   */
  public function __construct(protected FindingRecorder $recorder, protected ClaimExtractorManager $extractorManager) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('fact_drift.finding_recorder'), $container->get('fact_drift.extractor_manager'));
  }

  /**
   * Dismisses an open finding and returns to the report.
   *
   * The route is a GET with a CSRF token and carries no form, so the reason
   * names the account that followed the link.
   *
   * @param \Drupal\fact_drift\Entity\Finding $fact_drift_finding
   *   The finding.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its state, type and extractor query arguments are kept on
   *   return.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   A redirect to the report.
   */
  public function dismiss(Finding $fact_drift_finding, Request $request): RedirectResponse {
    if ($fact_drift_finding->getState() === Finding::STATE_OPEN) {
      $this->recorder->dismiss($fact_drift_finding, 'Dismissed from the report by ' . $this->currentUser()->getDisplayName());
      $this->messenger()->addStatus($this->t('Finding dismissed.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Only open findings can be dismissed. Nothing was changed.'));
    }

    $query = [];
    $input = $request->query->all();
    if (isset($input['state']) && is_string($input['state']) && in_array($input['state'], ReportController::STATES, TRUE)) {
      $query['state'] = $input['state'];
    }
    if (isset($input['type']) && is_string($input['type']) && preg_match('/^[a-z0-9_]+$/', $input['type'])) {
      $query['type'] = $input['type'];
    }
    if (isset($input['extractor']) && is_string($input['extractor']) && $this->extractorManager->hasDefinition($input['extractor'])) {
      $query['extractor'] = $input['extractor'];
    }
    return $this->redirect('fact_drift.report', [], ['query' => $query]);
  }

}
