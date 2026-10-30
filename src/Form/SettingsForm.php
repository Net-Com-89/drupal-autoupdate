<?php

declare(strict_types=1);

namespace Drupal\netcom_autoupdate\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\netcom_autoupdate\Settings;

/**
 * Réglages et historique des mises à jour automatiques.
 */
final class SettingsForm extends FormBase {

  use AutowireTrait;

  public function __construct(
    private readonly StateInterface $state,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public function getFormId(): string {
    return 'netcom_autoupdate_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $settings = Settings::load($this->state);

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Activer les mises à jour automatiques'),
      '#default_value' => $settings['enabled'],
      '#description' => $this->t('Désactivé, la tâche cron ne fait rien. La commande se lance par le cron du serveur : <code>vendor/bin/drupal-autoupdate</code> (voir le README).'),
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Nom du projet'),
      '#default_value' => $settings['label'],
      '#description' => $this->t('Affiché dans les notifications. Vide : nom du site.'),
    ];
    $form['environment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Environnement'),
      '#default_value' => $settings['environment'],
      '#description' => $this->t('Par exemple « ppd » ou « prod ».'),
      '#size' => 20,
    ];
    $form['discord_webhook'] = [
      '#type' => 'url',
      '#title' => $this->t('Webhook Discord'),
      '#default_value' => $settings['discord_webhook'],
      '#maxlength' => 512,
      '#description' => $this->t('Aucune notification si vide. Rien n\'est envoyé quand tout est déjà à jour.'),
    ];
    $form['min_release_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Âge minimal d\'une version (jours)'),
      '#default_value' => $settings['min_release_age'],
      '#min' => 0,
      '#description' => $this->t('Une version publiée depuis moins longtemps est retenue jusqu\'au jour où elle atteint cet âge : la préproduction (0) la reçoit avant la production (3). Ignoré si une faille de sécurité touche une version installée.'),
    ];
    $form['sites'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Sites (multisite)'),
      '#default_value' => implode("\n", $settings['sites']),
      '#rows' => 3,
      '#description' => $this->t('Obligatoire en multisite : une URI drush par ligne, site par défaut compris (ex. https://exemple.fr et https://exemple.fr/actus), sinon updatedb ne serait joué que sur ce site. Vide : ce site uniquement.'),
    ];
    $form['smoke_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Chemins testés après mise à jour'),
      '#default_value' => implode("\n", $settings['smoke_paths']),
      '#rows' => 3,
      '#description' => $this->t('Un chemin par ligne, testé sur chaque site par une requête interne (sans réseau). Une réponse 500 ou plus déclenche le retour arrière. Choisir une page réellement rendue en anonyme (<code>/user/login</code>) : une redirection ou un 401/403 ne teste presque rien.'),
    ];
    $form['smoke_urls'] = [
      '#type' => 'textarea',
      '#title' => $this->t('URLs externes testées'),
      '#default_value' => implode("\n", $settings['smoke_urls']),
      '#rows' => 2,
      '#description' => $this->t('Facultatif, par exemple le front d\'un site headless. Une réponse 500 ou plus déclenche le retour arrière.'),
    ];
    $form['maintenance_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mode maintenance pendant la mise à jour'),
      '#default_value' => $settings['maintenance_mode'],
    ];
    $form['keep_backups'] = [
      '#type' => 'number',
      '#title' => $this->t('Sauvegardes conservées'),
      '#default_value' => $settings['keep_backups'],
      '#min' => 1,
      '#description' => $this->t('Nombre de mises à jour dont on garde le lock et le dump de base, dans <code>.autoupdate/backups</code> à la racine Composer.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Enregistrer')];

    $form['history'] = [
      '#type' => 'details',
      '#title' => $this->t('Historique'),
      '#open' => TRUE,
      '#weight' => 100,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Date'), $this->t('Statut'), $this->t('Détail')],
        '#empty' => $this->t('Aucune exécution enregistrée.'),
        '#rows' => array_map($this->historyRow(...), Settings::history($this->state)),
      ],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (self::lines($form_state->getValue('smoke_paths')) as $path) {
      if (!str_starts_with($path, '/')) {
        $form_state->setErrorByName('smoke_paths', $this->t('Chaque chemin doit commencer par « / » : %path.', ['%path' => $path]));
      }
    }
    foreach (self::lines($form_state->getValue('smoke_urls')) as $url) {
      if (!preg_match('#^https?://#', $url)) {
        $form_state->setErrorByName('smoke_urls', $this->t('URL invalide : %url.', ['%url' => $url]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->state->set(Settings::STATE_KEY, [
      'enabled' => (bool) $form_state->getValue('enabled'),
      'label' => trim((string) $form_state->getValue('label')),
      'environment' => trim((string) $form_state->getValue('environment')),
      'discord_webhook' => trim((string) $form_state->getValue('discord_webhook')),
      'min_release_age' => (int) $form_state->getValue('min_release_age'),
      'sites' => self::lines($form_state->getValue('sites')),
      'smoke_paths' => self::lines($form_state->getValue('smoke_paths')) ?: ['/user/login'],
      'smoke_urls' => self::lines($form_state->getValue('smoke_urls')),
      'maintenance_mode' => (bool) $form_state->getValue('maintenance_mode'),
      'keep_backups' => max(1, (int) $form_state->getValue('keep_backups')),
    ]);
    $this->messenger()->addStatus($this->t('Réglages enregistrés.'));
  }

  private function historyRow(array $entry): array {
    $details = array_filter([
      $entry['summary'] ?? '',
      implode("\n", $entry['changes'] ?? []),
      ($entry['held'] ?? []) ? 'Retenus : ' . implode(', ', $entry['held']) : '',
      implode("\n", $entry['warnings'] ?? []),
    ]);
    return [
      $this->dateFormatter->format((int) $entry['time'], 'short'),
      Settings::STATUS_LABELS[$entry['status'] ?? ''] ?? ($entry['status'] ?? ''),
      ['data' => ['#markup' => nl2br(htmlspecialchars(implode("\n", $details)))]],
    ];
  }

  private static function lines(mixed $value): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: [])));
  }

}
