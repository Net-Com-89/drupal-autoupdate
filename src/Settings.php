<?php

declare(strict_types=1);

namespace Drupal\netcom_autoupdate;

use Drupal\Core\State\StateInterface;

/**
 * Réglages du module.
 *
 * En State et non en config : ils diffèrent d'un environnement à l'autre et
 * portent un secret (webhook), rien ne doit partir dans config/sync.
 */
final class Settings {

  public const STATE_KEY = 'netcom_autoupdate.settings';

  public const HISTORY_KEY = 'netcom_autoupdate.history';

  // Repris tel quel dans bin/drupal-autoupdate, qui ne peut rien inclure.
  public const DEFAULTS = [
    'enabled' => FALSE,
    'label' => '',
    'environment' => '',
    'discord_webhook' => '',
    'min_release_age' => 3,
    'sites' => [],
    'smoke_paths' => ['/'],
    'smoke_urls' => [],
    'maintenance_mode' => TRUE,
    'keep_backups' => 3,
  ];

  public const STATUS_LABELS = [
    'success' => 'Mis à jour',
    'uptodate' => 'À jour',
    'held' => 'Reporté',
    'blocked' => 'Bloqué par le garde-fou',
    'rollback' => 'Échec, retour arrière effectué',
    'error' => 'Erreur',
    'critical' => 'Échec du retour arrière',
    'warning' => 'Avertissement',
  ];

  public static function load(StateInterface $state): array {
    return ((array) $state->get(self::STATE_KEY, [])) + self::DEFAULTS;
  }

  /**
   * @return list<array{time: int, command: string, status: string, summary: string, changes: list<string>, held: list<string>, warnings: list<string>}>
   */
  public static function history(StateInterface $state): array {
    return (array) $state->get(self::HISTORY_KEY, []);
  }

}
