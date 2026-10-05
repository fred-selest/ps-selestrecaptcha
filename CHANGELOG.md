# Journal des modifications

Les évolutions notables de ce projet sont décrites ici. Le format suit
[Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et le projet utilise
le [versionnage sémantique](https://semver.org/lang/fr/).

## [1.0.0]

Première version.

### Ajouté

- reCAPTCHA v2 avec case à cocher, v2 invisible et v3, vérifiés côté serveur
  via `siteverify` de Google avant que le gestionnaire natif ne voie la
  soumission.
- Formulaires protégés : contact, création de compte, newsletter et avis
  produits, chacun avec son interrupteur, son nom d'action et son score
  minimum.
- Chemin de refus qui retire les champs déclencheurs au lieu de laisser le
  code natif s'exécuter, tout en conservant la saisie du visiteur.
- Comportement fail-closed / fail-open appliqué aux seules pannes réseau : un
  jeton absent ou un refus explicite de Google est toujours refusé.
- Gestion des jetons à usage unique : un jeton capturé ne peut pas être rejoué.
- Contrôles de score, d'action et de nom de domaine pour la v3.
- Réglages par boutique sur les installations multistore.
- Écran de configuration dans le back-office avec un diagnostic « Tester les
  clés » ; la clé secrète n'est jamais renvoyée au navigateur.
- Intégration RGPD via le hook `registerGDPRConsent`.
- Détection des clés de test de Google, avec un avertissement dans le
  back-office.
- Un autoloader PSR-4 de secours : aucune installation de Composer sur le
  serveur du marchand.
- Traductions **français et anglais** de l'interface (`translations/fr-FR`).
- Statistiques d'installation : un message anonyme par installation et par mise à
  jour, vers un point de collecte choisi par le marchand, désactivé par défaut.
- Détection d'un autre module reCAPTCHA installé, avec un avertissement dans le
  back-office.
- Suites de tests : unitaires, intégration (un faux du point de vérification de
  Google), end-to-end en HTTP réel contre PrestaShop 8.2.8 et 9.2.0, un harnais
  de rendu du back-office, et un test navigateur pour le widget.

### Corrigé

- Les réglages par boutique n'étaient jamais lus : `Configuration::get()`
  attend le groupe de boutiques avant la boutique, et le module passait l'identifiant
  de boutique dans le mauvais paramètre.
- Un enregistrement pouvait ne rien faire sans prévenir. La ligne était supprimée
  avant `Configuration::updateValue()`, dont `hasKey()` répond depuis le cache
  construit au démarrage de la requête : PrestaShop prenait sa branche UPDATE,
  ne trouvait aucune ligne, et déclarait pourtant avoir enregistré.
- Sur une boutique sans la fonction multistore, les réglages étaient écrits
  avec un `id_shop` et n'étaient jamais relus : tout y passe par la ligne
  globale.
- Le widget pouvait rester invisible : un callback en attente était appelé sans
  l'objet `grecaptcha`, et l'erreur qui en découlait était avalée par le garde-fou
  « un script tiers cassé ne doit pas casser la page ».
- Les quatre libellés de formulaires (contact, création de compte, newsletter,
  avis produits) n'étaient pas traduisibles : ils ne passaient pas par le
  traducteur.