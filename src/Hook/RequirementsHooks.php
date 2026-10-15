<?php

declare(strict_types=1);

namespace Drupal\netcom_autoupdate\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\netcom_autoupdate\Settings;

/**
 * Rapport d'état : signale une tâche cron absente ou une mise à jour en échec.
 */
final class RequirementsHooks {

  use StringTranslationTrait;

  // Une exécution par jour attendue : au-delà de deux jours, le cron ne tourne plus.
  private const STALE_AFTER = 2 * 86400;

  public function __construct(
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirement = ['title' => $this->t('Mises à jour automatiques')];
    $last = Settings::history($this->state)[0] ?? NULL;

    if (!Settings::load($this->state)['enabled']) {
      $requirement += ['value' => $this->t('Désactivées'), 'severity' => RequirementSeverity::Info];
    }
    elseif ($last === NULL) {
      $requirement += [
        'value' => $this->t('Aucune exécution enregistrée'),
        'description' => $this->t('Vérifier la tâche cron du serveur qui lance <code>vendor/bin/drupal-autoupdate</code>.'),
        'severity' => RequirementSeverity::Warning,
      ];
    }
    else {
      $age = $this->time->getRequestTime() - (int) $last['time'];
      $severity = match ($last['status']) {
        'success', 'uptodate', 'held' => RequirementSeverity::OK,
        'error', 'critical' => RequirementSeverity::Error,
        default => RequirementSeverity::Warning,
      };
      $description = $last['summary'] ?? '';
      if ($age > self::STALE_AFTER) {
        $severity = $severity === RequirementSeverity::Error ? $severity : RequirementSeverity::Warning;
        $description = $this->t('Aucune exécution depuis @age : la tâche cron tourne-t-elle encore ?', ['@age' => $this->dateFormatter->formatInterval($age)]) . ' ' . $description;
      }
      $requirement += [
        'value' => $this->t('@status le @date', [
          '@status' => Settings::STATUS_LABELS[$last['status']] ?? $last['status'],
          '@date' => $this->dateFormatter->format((int) $last['time'], 'short'),
        ]),
        'description' => $description,
        'severity' => $severity,
      ];
    }
    return ['netcom_autoupdate' => $requirement];
  }

}
