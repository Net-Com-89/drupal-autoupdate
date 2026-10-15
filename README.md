# drupal-autoupdate

Mises à jour Composer **automatiques** d'un Drupal 11+ : chaque nuit, le core et les modules
passent à leurs dernières versions **mineures et patchs** (jamais de majeure), avec sauvegarde,
tests et **retour arrière automatique** en cas d'échec. Le résultat est envoyé sur Discord.

Aucune action humaine n'est nécessaire dans le cas courant. Le serveur prend de l'avance sur le
`composer.lock` du dépôt, et c'est voulu (voir [Déploiement](#déploiement)).

## Ce qui se passe chaque nuit

1. Calcul du nouveau `composer.lock`, sans rien installer.
2. **Garde-fous**, qui annulent avant toute modification :
   - aucune majeure sur les paquets de `composer.json` ni sur un paquet Drupal (core, module,
     thème). Une bibliothèque transitive peut changer de majeure si un paquet autorisé l'exige
     (`drupal/core-recommended` le fait entre deux mineures du core) ;
   - aucune version publiée depuis moins de *N* jours (réglage « âge minimal »), sauf si une faille
     de sécurité touche une version installée ;
   - aucun nouveau plugin Composer non autorisé dans `allow-plugins`.
3. Tests HTTP de référence, mode maintenance, dump de chaque base.
4. `composer install`, `drush updatedb` et `drush cache:rebuild` sur chaque site, puis sortie du
   mode maintenance et nouveaux tests HTTP.
5. **En cas d'échec** : ancien lock réinstallé, bases restaurées, maintenance rétablie.
6. Notification Discord (rien n'est envoyé quand tout est déjà à jour), historique visible dans
   Drupal et dans le rapport d'état.

## Installation

Compter dix minutes par projet. Les chemins sont relatifs à la **racine Composer**, celle qui
contient `composer.json` (parfois un sous-dossier `drupal/`).

1. **Paquet**

   ```
   composer require netcom/drupal-autoupdate
   ```

2. **Module** : `drush en netcom_autoupdate`, puis `drush config:export` pour le versionner. Sur un
   projet dont la base locale est partagée avec un serveur, ajouter plutôt
   `netcom_autoupdate: 0` à `core.extension.yml` à la main et déployer.

3. **Réglages**, sur **chaque environnement** : *Configuration › Système › Mises à jour
   automatiques* (`/admin/config/system/autoupdate`). Ils sont stockés en State : ils ne partent pas
   dans la config exportée, et le webhook reste hors de git.
   - cocher **Activer** ;
   - renseigner l'**environnement** (`ppd`, `prod`) et le **webhook Discord** ;
   - **âge minimal** : `0` en préproduction, `3` en production. La préproduction reçoit ainsi
     chaque version trois jours avant la production ;
   - **multisite** : une URI drush par site dans « Sites », site par défaut compris.

4. **Prérequis** :

   ```
   vendor/bin/drupal-autoupdate check
   ```

   Corriger chaque `[ÉCHEC]`. Les `[ATTENTION]` sont informatifs.

5. **Tâche cron** du serveur, lancée avec l'utilisateur **propriétaire du code** (pas `www-data`) :

   ```
   # Sans Docker
   0 23 * * * cd /var/www/projet && vendor/bin/drupal-autoupdate >/dev/null 2>&1

   # Avec Docker (le dossier de travail de l'image drupal est la racine Composer)
   0 23 * * * docker exec -T projet-drupal-1 vendor/bin/drupal-autoupdate >/dev/null 2>&1
   ```

   L'heure est libre. Si le rapport d'état ne voit passer aucune exécution pendant deux jours, il le
   signale.

6. **Déploiement** : adapter la procédure (section suivante).

7. **Vérifier** : `vendor/bin/drupal-autoupdate notify-test`, puis
   `vendor/bin/drupal-autoupdate run --dry-run`, qui affiche ce qui serait mis à jour sans rien
   modifier.

## Déploiement

Les mises à jour nocturnes ne sont **jamais commitées**. `vendor/` porte les versions réelles, et le
`composer.lock` du dépôt est en retard. Sans précaution, un déploiement réinstallerait donc les
anciennes versions.

`vendor/bin/drupal-autoupdate reconcile` règle ce problème. On le lance **après** la récupération
du code et **avant** `composer install` :

| Situation | Effet de `reconcile` |
| --------- | -------------------- |
| Le commit ne touche pas aux dépendances (cas courant) | le lock du serveur est restauré, `composer install` n'a rien à faire |
| Le commit ajoute ou modifie des dépendances | le lock du dépôt est gardé, et chaque paquet qu'il ferait reculer est remonté à la version du serveur |
| Une contrainte du dépôt impose une version plus ancienne | le recul est appliqué (le dépôt l'emporte) et signalé sur Discord |

**Déploiement automatique** (script lancé après `git reset --hard`) : ajouter avant `composer install`

```bash
if [ -x vendor/bin/drupal-autoupdate ]; then
  vendor/bin/drupal-autoupdate reconcile
fi
```

**Déploiement manuel** : `git pull` refuse de s'exécuter tant que `composer.lock` est modifié
localement. La séquence devient :

```
git checkout -- composer.json composer.lock
git checkout -- patches.lock.json 2>/dev/null   # si le projet en a un
git pull
vendor/bin/drupal-autoupdate reconcile
composer install --no-dev
# … suite habituelle (drush updatedb, config:import, cache:rebuild)
```

## Ce qui demande une action humaine

Chaque cas est notifié sur Discord et enregistré dans l'historique.

| Notification | Cause | Action |
| ------------ | ----- | ------ |
| ⚠️ Config modifiée par les update hooks | une mise à jour a modifié la config active | en dev, mettre à jour puis `drush config:export`. Sinon le prochain `config:import` annule la modification |
| 🛑 Nouveau plugin Composer à autoriser | un paquet apporte un plugin absent de `allow-plugins` | l'ajouter au `composer.json` du dépôt et déployer |
| 🛑 Bloquée par le garde-fou | une contrainte de `composer.json` laisse passer une majeure (`*`, `>=`) | resserrer la contrainte (`^`) |
| ↩️ Échec, retour arrière effectué | `composer install`, `updatedb` ou un test HTTP a échoué, souvent un patch qui ne s'applique plus | corriger en dev, déployer ; la nuit suivante retentera |
| 🚨 Échec du retour arrière | rarissime : le site est peut-être cassé | restauration manuelle (ci-dessous) |
| ❌ Prérequis non remplis | voir `check` | corriger |

## Prérequis

Ils sont vérifiés par `check`, et à chaque exécution.

- Drupal **11.2+** (ou 12), PHP 8.3+, Composer **2.4+**, drush dans `vendor/bin`.
- Outil de dump de la base dans le `PATH` (`mysqldump`/`mariadb-dump`, `pg_dump` ou `sqlite3`).
  L'image Docker officielle `drupal` n'en contient pas : ajouter `mariadb-client` au `Dockerfile`.
- Droit d'écriture sur la racine Composer, `composer.lock` et `vendor/`.
- `composer.lock` cohérent avec `vendor/` (sinon, lancer `composer install` d'abord).
- `cweagans/composer-patches` : en 1.x, `"composer-exit-on-patch-failure": true` dans `extra` ; en
  2.x, `git` installé.

## Commandes

```
vendor/bin/drupal-autoupdate [run|check|reconcile|notify-test] [options]
```

| Option | Rôle |
| ------ | ---- |
| `--dry-run` | `run` : affiche les mises à jour sans les appliquer |
| `--force` | `run` : passe outre la case « Activer » (test manuel) |
| `--uri=URI` | site drush qui porte les réglages (multisite, si ce n'est pas le site par défaut) |
| `--root=DIR` | racine Composer, si la détection échoue |
| `--composer=CMD` | commande Composer, si elle n'est pas dans le `PATH` |

Journaux, sauvegardes (lock + dump de chaque base) et dernier lock appliqué : dossier
`.autoupdate/` de la racine Composer. Il s'exclut lui-même de git et bloque l'accès web par un
`.htaccess`.

## Restauration manuelle

```
cd <racine Composer>
cp .autoupdate/backups/<date>/composer.json .autoupdate/backups/<date>/composer.lock .
cp .autoupdate/backups/<date>/patches.lock.json . 2>/dev/null
composer install --no-dev
cp .autoupdate/backups/<date>/db-0.sql.gz /tmp/restore.sql.gz   # db-1, db-2… pour les autres sites
drush sql:drop -y && drush sql:query --file=/tmp/restore.sql.gz
drush cache:rebuild && drush state:set system.maintenance_mode 0 --input-format=integer
```

Travailler sur une **copie** du dump : `drush sql:query` décompresse le fichier sur place.

## Limites

- Une majeure (core 12, module 3.x → 4.x) n'est **jamais** appliquée : elle reste un chantier
  manuel.
- Les tests HTTP détectent les erreurs fatales (réponse 500 ou plus), pas les régressions
  fonctionnelles. La préproduction, avec un âge minimal plus court, sert de canari.
- Le site est en maintenance pendant `composer install` et `updatedb`, soit une à trois minutes.
