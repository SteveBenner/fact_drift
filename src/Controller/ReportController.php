<?php

namespace Drupal\fact_drift\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager;
use Drupal\fact_drift\ClaimExtractor\ContextualClaimExtractorInterface;
use Drupal\fact_drift\Entity\Finding;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the admin report of findings.
 *
 * @phpstan-consistent-constructor
 */
class ReportController extends ControllerBase {

  /**
   * The number of findings per page.
   */
  public const PAGE_SIZE = 50;

  /**
   * The states a finding can be filtered by, in display order.
   */
  public const STATES = [Finding::STATE_OPEN, Finding::STATE_CLOSED, Finding::STATE_DISMISSED];

  /**
   * The ID of the findings table caption, which labels the scroll region.
   */
  public const CAPTION_ID = 'fact-drift-findings-caption';

  /**
   * The node field labels already looked up, keyed by bundle and field name.
   *
   * @var array<string, string>
   */
  protected array $fieldLabels = [];

  /**
   * Constructs a ReportController.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   The date formatter.
   * @param \Drupal\fact_drift\ClaimExtractor\ClaimExtractorManager $extractorManager
   *   The claim extractor plugin manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $fieldManager
   *   The entity field manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, protected DateFormatterInterface $dateFormatter, protected ClaimExtractorManager $extractorManager, protected EntityFieldManagerInterface $fieldManager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('fact_drift.extractor_manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * Builds the report.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request; its state, type and extractor query arguments filter the
   *   table.
   *
   * @return array<string, mixed>
   *   A render array using the fact_drift_report theme hook.
   */
  public function report(Request $request): array {
    $query = $request->query->all();
    $state = isset($query['state']) && is_string($query['state']) && in_array($query['state'], self::STATES, TRUE) ? $query['state'] : Finding::STATE_OPEN;
    $typeLabels = $this->claimTypeLabels();
    $type = isset($query['type']) && is_string($query['type']) && isset($typeLabels[$query['type']]) ? $query['type'] : NULL;
    $extractorLabels = $this->extractorLabels();
    $extractor = isset($query['extractor']) && is_string($query['extractor']) && isset($extractorLabels[$query['extractor']]) ? $query['extractor'] : NULL;
    $pastFirstPage = isset($query['page']) && is_scalar($query['page']) && (int) $query['page'] > 0;

    $metadata = new CacheableMetadata();
    $metadata->addCacheTags(['fact_drift_finding_list', 'config:fact_drift.settings']);
    $metadata->addCacheContexts([
      'user.permissions',
      'timezone',
      'url.query_args:state',
      'url.query_args:type',
      'url.query_args:extractor',
      'url.query_args.pagers:0',
    ]);
    $metadata->setCacheMaxAge(Cache::PERMANENT);

    $build = [
      '#theme' => 'fact_drift_report',
      '#attributes' => ['class' => ['fact-drift-report']],
      '#summary' => $this->summary($typeLabels, $metadata),
      '#filters' => $this->filters($state, $type, $extractor, $typeLabels, $extractorLabels),
      '#table' => $this->table($state, $type, $extractor, $typeLabels, $extractorLabels, $pastFirstPage, $metadata),
      '#attached' => ['library' => ['fact_drift/report']],
    ];
    $metadata->applyTo($build);
    return $build;
  }

  /**
   * Builds the summary of open findings by claim type and by source.
   *
   * @param array<string, string> $typeLabels
   *   The claim type labels keyed by claim type.
   * @param \Drupal\Core\Cache\CacheableMetadata $metadata
   *   Collects the cache metadata of everything shown.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function summary(array $typeLabels, CacheableMetadata $metadata): array {
    $summary = [
      '#prefix' => '<section>',
      '#suffix' => '</section>',
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Open findings'),
      ],
    ];
    $byType = $this->countOpenBy('claim_type');
    $bySource = $this->countOpenBy('source');
    if (!$byType) {
      $summary['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('No open findings.'),
      ];
      return $summary;
    }

    $typeRows = [];
    foreach ($byType as $type => $count) {
      $typeRows[] = [['data' => ['#plain_text' => (string) ($typeLabels[$type] ?? $type)]], $this->countCell($count)];
    }
    $sourceRows = [];
    $sources = $this->entityTypeManager->getStorage('fact_drift_source')->loadMultiple(array_keys($bySource));
    foreach ($bySource as $id => $count) {
      $label = isset($sources[$id]) ? $sources[$id]->label() : $id;
      if (isset($sources[$id])) {
        $metadata->addCacheableDependency($sources[$id]);
      }
      $sourceRows[] = [['data' => ['#plain_text' => (string) $label]], $this->countCell($count)];
    }
    $summary['tables'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['fact-drift-report__summary']],
      'by_type' => [
        '#type' => 'container',
        'table' => [
          '#type' => 'table',
          '#caption' => $this->t('By claim type'),
          '#header' => [$this->t('Claim type'), $this->countHeader()],
          '#rows' => $typeRows,
        ],
      ],
      'by_source' => [
        '#type' => 'container',
        'table' => [
          '#type' => 'table',
          '#caption' => $this->t('By source'),
          '#header' => [$this->t('Source'), $this->countHeader()],
          '#rows' => $sourceRows,
        ],
      ],
    ];
    return $summary;
  }

  /**
   * Builds the header cell of a summary count column.
   *
   * @return array<string, mixed>
   *   A table header cell.
   */
  protected function countHeader(): array {
    return ['data' => $this->t('Open'), 'class' => ['fact-drift-report__count']];
  }

  /**
   * Builds a summary count cell.
   *
   * @param int $count
   *   The count.
   *
   * @return array<string, mixed>
   *   A table cell.
   */
  protected function countCell(int $count): array {
    return ['data' => $count, 'class' => ['fact-drift-report__count']];
  }

  /**
   * Counts open findings grouped by a field.
   *
   * @param string $field
   *   The finding field to group by.
   *
   * @return int[]
   *   The counts keyed by the field's value, largest first.
   */
  protected function countOpenBy(string $field): array {
    /** @var array<int, array<string, mixed>> $rows */
    $rows = $this->entityTypeManager->getStorage('fact_drift_finding')->getAggregateQuery()
      ->accessCheck(TRUE)
      ->condition('state', Finding::STATE_OPEN)
      ->groupBy($field)
      ->aggregate('id', 'COUNT')
      ->execute();
    $counts = [];
    foreach ($rows as $row) {
      $value = (string) ($row[$field] ?? $row[$field . '_target_id'] ?? '');
      $counts[$value] = (int) $row['id_count'];
    }
    arsort($counts);
    return $counts;
  }

  /**
   * Gets the labels of the finding states.
   *
   * @return array<string, string>
   *   The state labels keyed by state, from the state field's allowed values.
   */
  protected function stateLabels(): array {
    $definitions = $this->fieldManager->getBaseFieldDefinitions('fact_drift_finding');
    $labels = [];
    foreach ((array) $definitions['state']->getSetting('allowed_values') as $state => $label) {
      $labels[(string) $state] = (string) $label;
    }
    return $labels;
  }

  /**
   * Builds the filters: the Findings heading and the inline link lists.
   *
   * @param string $state
   *   The active state.
   * @param string|null $type
   *   The active claim type, or NULL for all.
   * @param string|null $extractor
   *   The active extractor ID, or NULL for all.
   * @param array<string, string> $typeLabels
   *   The claim type labels keyed by claim type.
   * @param array<string, string> $extractorLabels
   *   The extractor labels keyed by extractor plugin ID.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function filters(string $state, ?string $type, ?string $extractor, array $typeLabels, array $extractorLabels): array {
    $stateLabels = $this->stateLabels();
    $stateLinks = [];
    foreach (self::STATES as $candidate) {
      $stateLinks[$candidate] = $this->filterLink($stateLabels[$candidate] ?? $candidate, $candidate, $type, $extractor, $candidate === $state);
    }
    $typeLinks = ['all' => $this->filterLink((string) $this->t('All'), $state, NULL, $extractor, $type === NULL)];
    foreach ($typeLabels as $candidate => $label) {
      $typeLinks[$candidate] = $this->filterLink($label, $state, $candidate, $extractor, $candidate === $type);
    }
    $filters = [
      'heading' => [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Findings'),
      ],
      'nav' => [
        '#prefix' => '<nav class="fact-drift-report__filters" aria-label="' . Html::escape((string) $this->t('Filter findings')) . '">',
        '#suffix' => '</nav>',
        'state' => [
          '#type' => 'container',
          'links' => [
            '#theme' => 'links',
            '#heading' => ['text' => $this->t('State'), 'level' => 'h3'],
            '#links' => $stateLinks,
            '#attributes' => ['class' => ['inline']],
          ],
        ],
        'type' => [
          '#type' => 'container',
          'links' => [
            '#theme' => 'links',
            '#heading' => ['text' => $this->t('Claim type'), 'level' => 'h3'],
            '#links' => $typeLinks,
            '#attributes' => ['class' => ['inline']],
          ],
        ],
      ],
    ];
    if ($this->hasContextualExtractor()) {
      $extractorLinks = ['all' => $this->filterLink((string) $this->t('All'), $state, $type, NULL, $extractor === NULL)];
      foreach ($extractorLabels as $candidate => $label) {
        $extractorLinks[$candidate] = $this->filterLink($label, $state, $type, $candidate, $candidate === $extractor);
      }
      $filters['nav']['extractor'] = [
        '#type' => 'container',
        'links' => [
          '#theme' => 'links',
          '#heading' => ['text' => $this->t('Extractor'), 'level' => 'h3'],
          '#links' => $extractorLinks,
          '#attributes' => ['class' => ['inline']],
        ],
      ];
    }
    return $filters;
  }

  /**
   * Builds one filter link.
   *
   * @param string $title
   *   The link text.
   * @param string $state
   *   The state the link selects.
   * @param string|null $type
   *   The claim type the link selects, or NULL for all.
   * @param string|null $extractor
   *   The extractor ID the link selects, or NULL for all.
   * @param bool $active
   *   Whether this is the active filter.
   *
   * @return array<string, mixed>
   *   A link definition for the links theme hook.
   */
  protected function filterLink(string $title, string $state, ?string $type, ?string $extractor, bool $active): array {
    $link = [
      'title' => $title,
      'url' => Url::fromRoute('fact_drift.report', [], ['query' => $this->filterQuery($state, $type, $extractor)]),
      'attributes' => [],
    ];
    if ($active) {
      $link['title'] = [
        '#type' => 'inline_template',
        '#template' => '<strong>{{ title }}</strong>',
        '#context' => ['title' => $title],
      ];
      $link['attributes'] = ['aria-current' => 'page', 'class' => ['is-active']];
    }
    return $link;
  }

  /**
   * Builds the query arguments that select a filter.
   *
   * @param string $state
   *   The state.
   * @param string|null $type
   *   The claim type, or NULL for all.
   * @param string|null $extractor
   *   (optional) The extractor ID, or NULL for all.
   *
   * @return array<string, string>
   *   The query arguments.
   */
  protected function filterQuery(string $state, ?string $type, ?string $extractor = NULL): array {
    $query = ['state' => $state];
    if ($type !== NULL) {
      $query['type'] = $type;
    }
    if ($extractor !== NULL) {
      $query['extractor'] = $extractor;
    }
    return $query;
  }

  /**
   * Builds the findings table in its scroll region, and its pager.
   *
   * @param string $state
   *   The state to list.
   * @param string|null $type
   *   The claim type to list, or NULL for all.
   * @param string|null $extractor
   *   The extractor ID to list, or NULL for all.
   * @param array<string, string> $typeLabels
   *   The claim type labels keyed by claim type.
   * @param array<string, string> $extractorLabels
   *   The extractor labels keyed by extractor plugin ID.
   * @param bool $pastFirstPage
   *   Whether a page after the first was requested.
   * @param \Drupal\Core\Cache\CacheableMetadata $metadata
   *   Collects the cache metadata of everything shown.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function table(string $state, ?string $type, ?string $extractor, array $typeLabels, array $extractorLabels, bool $pastFirstPage, CacheableMetadata $metadata): array {
    $storage = $this->entityTypeManager->getStorage('fact_drift_finding');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('state', $state)
      ->sort('created', 'DESC')
      ->sort('id', 'DESC')
      ->pager(self::PAGE_SIZE);
    if ($type !== NULL) {
      $query->condition('claim_type', $type);
    }
    if ($extractor !== NULL) {
      $query->condition('extractor', $extractor);
    }
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $finding) {
      assert($finding instanceof Finding);
      $rows[] = $this->row($finding, $state, $type, $extractor, $typeLabels, $metadata);
    }
    $sourceStorage = $this->entityTypeManager->getStorage('fact_drift_source');
    $metadata->addCacheTags($sourceStorage->getEntityType()->getListCacheTags());
    $stateLabel = $this->stateLabels()[$state] ?? $state;

    $header = [
      $this->t('Page'),
      ['data' => $this->t('Field'), 'class' => ['priority-low']],
      $this->t('Claim type'),
      $this->t('Expected'),
      $this->t('Found'),
      $this->t('Snippet'),
      ['data' => $this->t('First seen'), 'class' => ['priority-low', 'fact-drift-report__seen']],
      ['data' => $this->t('Operations'), 'class' => ['fact-drift-report__ops']],
    ];
    $hasOperations = FALSE;
    foreach ($rows as $row) {
      if (end($row) !== '') {
        $hasOperations = TRUE;
        break;
      }
    }
    if (!$hasOperations) {
      array_pop($header);
      foreach ($rows as $index => $row) {
        array_pop($row);
        $rows[$index] = $row;
      }
    }

    return [
      'scroll' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['fact-drift-report__scroll'],
          'role' => 'region',
          'tabindex' => '0',
          'aria-labelledby' => self::CAPTION_ID,
        ],
        'table' => [
          '#type' => 'table',
          '#caption' => [
            '#type' => 'inline_template',
            '#template' => '<span id="{{ id }}">{{ text }}</span>',
            '#context' => [
              'id' => self::CAPTION_ID,
              'text' => $this->t('Findings (@state)', ['@state' => mb_strtolower($stateLabel)]),
            ],
          ],
          '#header' => $header,
          '#rows' => $rows,
          '#empty' => $this->emptyText($state, $stateLabel, $type, $extractor, $typeLabels, $extractorLabels, $pastFirstPage),
        ],
      ],
      'pager' => [
        '#type' => 'pager',
        '#element' => 0,
      ],
    ];
  }

  /**
   * Builds one table row.
   *
   * @param \Drupal\fact_drift\Entity\Finding $finding
   *   The finding.
   * @param string $state
   *   The active state filter.
   * @param string|null $type
   *   The active claim type filter.
   * @param string|null $extractor
   *   The active extractor filter.
   * @param array<string, string> $typeLabels
   *   The claim type labels keyed by claim type.
   * @param \Drupal\Core\Cache\CacheableMetadata $metadata
   *   Collects the cache metadata of everything shown.
   *
   * @return array<int, mixed>
   *   The row cells.
   */
  protected function row(Finding $finding, string $state, ?string $type, ?string $extractor, array $typeLabels, CacheableMetadata $metadata): array {
    $node = $finding->getNode();
    if ($node) {
      $metadata->addCacheableDependency($node);
      $label = (string) $node->label();
      $page = [
        'data' => [
          '#type' => 'link',
          '#title' => $label,
          '#url' => $node->toUrl(),
          '#attributes' => ['class' => ['fact-drift-report__title']],
        ],
      ];
    }
    else {
      $label = (string) $this->t('(deleted)');
      $page = ['data' => ['#plain_text' => $label]];
    }

    $operations = '';
    if ($finding->getState() === Finding::STATE_OPEN) {
      $access = $finding->access('update', NULL, TRUE);
      $metadata->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $operations = [
          'data' => [
            '#type' => 'link',
            '#title' => [
              '#type' => 'inline_template',
              '#template' => '{{ label }}<span class="visually-hidden"> {{ name }}</span>',
              '#context' => [
                'label' => $this->t('Dismiss'),
                'name' => $this->t('finding @id on @page', ['@id' => $finding->id(), '@page' => $label]),
              ],
            ],
            '#url' => Url::fromRoute('fact_drift.finding_dismiss', ['fact_drift_finding' => $finding->id()], ['query' => $this->filterQuery($state, $type, $extractor)]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
          'class' => ['fact-drift-report__ops'],
        ];
      }
    }

    $claimType = $finding->getClaimType();
    $fieldCell = $this->fieldCell($node ? $node->bundle() : NULL, $finding->getFieldName());
    return [
      $page,
      ['data' => $fieldCell, 'class' => ['fact-drift-report__field']],
      ['data' => $this->claimTypeCell((string) ($typeLabels[$claimType] ?? $claimType), $finding->getExtractor())],
      ['data' => $this->valueCell($finding->getExpected(), $claimType)],
      ['data' => $this->valueCell($finding->getFound(), $claimType)],
      [
        'data' => $this->snippetCell($finding->getSnippet(), (int) $finding->id()),
        'class' => ['fact-drift-report__snippet-cell'],
      ],
      [
        'data' => $this->dateFormatter->format($finding->getCreatedTime(), 'short'),
        'class' => ['fact-drift-report__seen'],
      ],
      $operations,
    ];
  }

  /**
   * Builds an Expected or Found cell.
   *
   * @param string $value
   *   The normalised value.
   * @param string $claimType
   *   The claim type of the finding.
   *
   * @return array<string, mixed>
   *   A render array. An hours value is split on "; " into segments that
   *   never wrap inside themselves; any other value is plain text.
   */
  protected function valueCell(string $value, string $claimType): array {
    if ($claimType !== 'hours') {
      return ['#plain_text' => $value];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{% for segment in segments %}<span class="fact-drift-report__segment">{{ segment }}</span>{% if not loop.last %}; {% endif %}{% endfor %}',
      '#context' => ['segments' => explode('; ', $value)],
    ];
  }

  /**
   * Builds the claim type cell: the type label and the extractor's marker.
   *
   * @param string $label
   *   The claim type label.
   * @param string $extractorId
   *   The ID of the extractor that opened the finding.
   *
   * @return array<string, mixed>
   *   A render array. The marker shows only for an extractor whose definition
   *   has one, as visible text with a visually hidden name for the extractor.
   */
  protected function claimTypeCell(string $label, string $extractorId): array {
    $definition = $extractorId === '' ? NULL : $this->extractorManager->getDefinition($extractorId, FALSE);
    $marker = $definition['marker'] ?? NULL;
    $context = ['label' => $label, 'marker' => '', 'name' => ''];
    if ($marker !== NULL && (string) $marker !== '') {
      $context['marker'] = $marker;
      $context['name'] = $this->t('found by the @extractor', ['@extractor' => (string) $definition['label']]);
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<span class="fact-drift-report__type">{{ label }}{% if marker %}<span class="fact-drift-report__marker">{{ marker }}<span class="visually-hidden"> ({{ name }})</span></span>{% endif %}</span>',
      '#context' => $context,
    ];
  }

  /**
   * Builds a snippet cell: a clamped preview and the full text in a disclosure.
   *
   * @param string $snippet
   *   The snippet text.
   * @param int $id
   *   The finding ID, which makes the disclosure's name unique.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  protected function snippetCell(string $snippet, int $id): array {
    $cell = [
      'preview' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['fact-drift-report__snippet']],
        'text' => ['#plain_text' => $snippet],
      ],
    ];
    $hidden = (string) $this->t('of the snippet for finding @id', ['@id' => $id]);
    $cell['full'] = [
      '#prefix' => '<details class="fact-drift-report__more"><summary>' . Html::escape((string) $this->t('Full text'))
      . '<span class="visually-hidden"> ' . Html::escape($hidden) . '</span></summary>',
      '#suffix' => '</details>',
      'text' => ['#plain_text' => $snippet],
    ];
    return $cell;
  }

  /**
   * Builds the Field cell.
   *
   * @param string|null $bundle
   *   The node bundle, or NULL when the node no longer exists.
   * @param string $field
   *   The field machine name.
   *
   * @return array<string, mixed>
   *   The field's label; or, when the field cannot be found, its machine name
   *   as secondary text that breaks only at underscores, preceded by
   *   "(removed field)" when the node's bundle no longer has it.
   */
  protected function fieldCell(?string $bundle, string $field): array {
    if ($bundle !== NULL) {
      $key = $bundle . ':' . $field;
      if (!isset($this->fieldLabels[$key])) {
        $definitions = $this->fieldManager->getFieldDefinitions('node', $bundle);
        $this->fieldLabels[$key] = isset($definitions[$field]) ? (string) $definitions[$field]->getLabel() : '';
      }
      if ($this->fieldLabels[$key] !== '') {
        return ['#plain_text' => $this->fieldLabels[$key]];
      }
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{% if removed %}<span class="fact-drift-report__removed">{{ removed }}</span> {% endif %}<span class="fact-drift-report__machine">{% for part in parts %}{{ part }}{% if not loop.last %}_<wbr>{% endif %}{% endfor %}</span>',
      '#context' => [
        'removed' => $bundle === NULL ? '' : $this->t('(removed field)'),
        'parts' => explode('_', $field),
      ],
    ];
  }

  /**
   * Builds the text shown when the table has no rows.
   *
   * @param string $state
   *   The active state filter.
   * @param string $stateLabel
   *   The state's label.
   * @param string|null $type
   *   The active claim type filter.
   * @param string|null $extractor
   *   The active extractor filter.
   * @param array<string, string> $typeLabels
   *   The claim type labels keyed by claim type.
   * @param array<string, string> $extractorLabels
   *   The extractor labels keyed by extractor plugin ID.
   * @param bool $pastFirstPage
   *   Whether a page after the first was requested.
   *
   * @return array<string, mixed>|\Drupal\Core\StringTranslation\TranslatableMarkup
   *   The empty text.
   */
  protected function emptyText(string $state, string $stateLabel, ?string $type, ?string $extractor, array $typeLabels, array $extractorLabels, bool $pastFirstPage) {
    $enabled = $this->entityTypeManager->getStorage('fact_drift_source')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', TRUE)
      ->count()
      ->execute();
    if (!$enabled) {
      $empty = ['message' => ['#plain_text' => (string) $this->t('No sources are enabled, so nothing has been scanned.')]];
      if ($this->currentUser()->hasPermission('administer fact drift')) {
        $empty['link'] = [
          '#type' => 'link',
          '#title' => $this->t('Add a source'),
          '#url' => Url::fromRoute('entity.fact_drift_source.collection'),
          '#prefix' => ' ',
        ];
      }
      return $empty;
    }
    $lower = mb_strtolower($stateLabel);
    if ($type !== NULL || $extractor !== NULL || $pastFirstPage) {
      $arguments = [
        '@state' => $lower,
        '@type' => $type === NULL ? '' : ($typeLabels[$type] ?? $type),
        '@extractor' => $extractor === NULL ? '' : ($extractorLabels[$extractor] ?? $extractor),
      ];
      $message = match (TRUE) {
        $type !== NULL && $extractor !== NULL => $this->t('No @state findings of claim type @type found by @extractor.', $arguments),
        $type !== NULL => $this->t('No @state findings of claim type @type.', $arguments),
        $extractor !== NULL => $this->t('No @state findings found by @extractor.', $arguments),
        default => $this->t('No @state findings on this page.', $arguments),
      };
      return [
        'message' => ['#plain_text' => (string) $message],
        'link' => [
          '#type' => 'link',
          '#title' => $this->t('Show all @state findings', ['@state' => $lower]),
          '#url' => Url::fromRoute('fact_drift.report', [], ['query' => $this->filterQuery($state, NULL)]),
          '#prefix' => ' ',
        ],
      ];
    }
    return match ($state) {
      Finding::STATE_CLOSED => $this->t('No closed findings.'),
      Finding::STATE_DISMISSED => $this->t('No dismissed findings.'),
      default => $this->t('No open findings. Every scanned claim matches its source.'),
    };
  }

  /**
   * Lists the claim types the extractor plugins can find, with their labels.
   *
   * @return array<string, string>
   *   The extractor plugins' labels keyed by claim type.
   */
  protected function claimTypeLabels(): array {
    $labels = [];
    foreach ($this->extractorManager->getDefinitions() as $definition) {
      if (($definition['claim_type'] ?? NULL) !== NULL) {
        $labels[$definition['claim_type']] = (string) ($definition['claim_label'] ?? $definition['label']);
      }
    }
    return $labels;
  }

  /**
   * Lists every extractor plugin with its label.
   *
   * @return array<string, string>
   *   The extractor labels keyed by plugin ID, in weight order.
   */
  protected function extractorLabels(): array {
    $labels = [];
    foreach ($this->extractorManager->getDefinitions() as $id => $definition) {
      $labels[(string) $id] = (string) $definition['label'];
    }
    return $labels;
  }

  /**
   * Tells whether any extractor plugin is contextual.
   *
   * @return bool
   *   TRUE when the extractor filter should be offered.
   */
  protected function hasContextualExtractor(): bool {
    foreach ($this->extractorManager->getDefinitions() as $definition) {
      if (is_subclass_of($definition['class'], ContextualClaimExtractorInterface::class)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
